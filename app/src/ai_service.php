<?php
require_once __DIR__ . '/config.php';

function ai_autoload(): void {
    static $loaded = false;
    if ($loaded) return;
    foreach (['/var/www/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $path) {
        if (is_file($path)) {
            require_once $path;
            $loaded = true;
            return;
        }
    }
}

function ai_allowed_tables(): array {
    return [
        'categories',
        'products',
        'units',
        'warehouses',
        'warehouse_locations',
        'product_lots',
        'product_prices',
        'inventory_documents',
        'inventory_lines',
        'stock_balances',
        'stock_movements',
    ];
}

function ai_db(bool $reconnect = false): PDO {
    static $pdo = null;
    if ($reconnect) {
        $pdo = null;
    }
    if ($pdo !== null) return $pdo;

    $config = require __DIR__ . '/config.php';
    $d = $config['ai_db'];
    if ($d['username'] === '' || $d['password'] === '') {
        throw new RuntimeException('Chưa cấu hình tài khoản MySQL chỉ đọc (AI_DB_USERNAME / AI_DB_PASSWORD).');
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['port'], $d['database']);
    $pdo = new PDO($dsn, $d['username'], $d['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}

/**
 * Normalize Vietnamese text to lowercase ASCII for accent-insensitive lexical matching.
 */
function ai_normalize_vi(string $text): string {
    $lower = mb_strtolower($text, 'UTF-8');
    static $map = [
        'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
        'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
        'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
        'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
        'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
        'đ'=>'d',
    ];
    return strtr($lower, $map);
}

/**
 * Lexical RAG retrieval from app/src/ai/schema_context.md.
 * Keeps core rules and ranks business table chunks by keyword relevance.
 */
function ai_retrieve_schema_context(string $question): string {
    $path = __DIR__ . '/ai/schema_context.md';
    if (!is_file($path)) {
        throw new RuntimeException('Không tìm thấy file schema context: app/src/ai/schema_context.md');
    }
    $raw = trim((string)file_get_contents($path));
    $lines = preg_split('/\R/', $raw) ?: [];

    $headerLines = [];
    $tableLines = [];
    $footerLines = [];
    $section = 'header';

    foreach ($lines as $line) {
        if (preg_match('/^\s*-\s+`/', $line)) {
            $section = 'tables';
            $tableLines[] = $line;
        } elseif ($section === 'tables' && trim($line) === '') {
            $section = 'footer';
            $footerLines[] = $line;
        } elseif ($section === 'header') {
            $headerLines[] = $line;
        } else {
            $footerLines[] = $line;
        }
    }

    if (!$tableLines) {
        return $raw;
    }

    $qLower = mb_strtolower($question, 'UTF-8');
    $qNorm = ai_normalize_vi($question);
    $tokens = preg_split('/[^\p{L}\p{N}_]+/u', $qLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $hints = [
        'categories' => ['danh mục', 'danh muc', 'loại hàng', 'loai hang', 'category', 'categories'],
        'products' => ['sản phẩm', 'san pham', 'hàng hóa', 'hang hoa', 'mặt hàng', 'mat hang', 'sku', 'tối thiểu', 'toi thieu', 'min_stock', 'product'],
        'units' => ['đơn vị', 'don vi', 'đvt', 'unit', 'cái', 'hộp', 'thùng'],
        'warehouses' => ['kho', 'warehouse'],
        'warehouse_locations' => ['vị trí', 'vi tri', 'ô kệ', 'o ke', 'kệ', 'bin', 'zone', 'rack', 'location'],
        'product_lots' => ['lô', 'hạn dùng', 'han dung', 'hạn sử dụng', 'han su dung', 'hết hạn', 'het han', 'lot', 'expires'],
        'stock_balances' => ['tồn', 'số dư', 'so du', 'hiện tại', 'hien tai', 'stock', 'balance', 'thiếu', 'thieu', 'hết hàng', 'het hang'],
        'inventory_documents' => ['phiếu', 'phieu', 'chứng từ', 'chung tu', 'nhập', 'xuất', 'ghi sổ', 'ghi so', 'posted', 'nháp', 'draft', 'đối tác', 'doi tac', 'partner', 'document'],
        'inventory_lines' => ['dòng phiếu', 'chi tiết phiếu', 'nhập', 'xuất', 'đơn giá', 'don gia', 'unit_price', 'giá trị nhập', 'line'],
        'stock_movements' => ['biến động', 'bien dong', 'lịch sử kho', 'lich su kho', 'movement'],
        'product_prices' => ['giá', 'bảng giá', 'bang gia', 'giá bán', 'gia ban', 'giá nhập', 'gia nhap', 'price'],
    ];

    $scored = [];
    foreach ($tableLines as $idx => $line) {
        $score = 1;
        $lineLower = mb_strtolower($line, 'UTF-8');
        foreach ($tokens as $tok) {
            if (mb_strlen($tok, 'UTF-8') >= 2 && str_contains($lineLower, $tok)) {
                $score += 3;
            }
        }
        foreach ($hints as $table => $keywords) {
            if (str_contains($lineLower, '`' . $table . '(')) {
                foreach ($keywords as $kw) {
                    if (str_contains($qLower, $kw) || str_contains($qNorm, $kw)) {
                        $score += 5;
                    }
                }
            }
        }
        $scored[] = ['idx' => $idx, 'score' => $score, 'line' => $line];
    }

    usort($scored, static fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['idx'] <=> $b['idx']);

    // Keep top ranked table lines (all 11 allowlisted tables, ordered by relevance)
    $selected = array_slice($scored, 0, min(11, count($scored)));
    $selectedLines = array_map(static fn($item) => $item['line'], $selected);

    return trim(
        implode("\n", $headerLines) . "\n" .
        implode("\n", $selectedLines) . "\n\n" .
        implode("\n", $footerLines)
    );
}

/**
 * Dynamic lexical RAG retrieval of top-K verified Vietnamese Q->SQL few-shot examples.
 */
function ai_retrieve_few_shot_examples(string $question, int $topK = 4): array {
    $path = __DIR__ . '/ai/few_shot_examples.php';
    if (!is_file($path)) {
        return [];
    }
    $examples = require $path;
    if (!is_array($examples) || !$examples) {
        return [];
    }

    $qNorm = ai_normalize_vi($question);
    $qTokens = array_values(array_unique(array_filter(
        preg_split('/[^a-z0-9_]+/', $qNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        static fn(string $t): bool => strlen($t) >= 2
    )));

    $scored = [];
    foreach ($examples as $idx => $ex) {
        $score = 0;
        $tags = $ex['tags'] ?? [];
        foreach ($tags as $tag) {
            $tagNorm = ai_normalize_vi((string)$tag);
            if ($tagNorm !== '' && str_contains($qNorm, $tagNorm)) {
                $score += (str_contains($tagNorm, ' ') ? 8 : 5);
            }
        }
        $exQNorm = ai_normalize_vi((string)($ex['question'] ?? ''));
        foreach ($qTokens as $tok) {
            if (str_contains($exQNorm, $tok)) {
                $score += 2;
            }
        }
        $scored[] = ['idx' => $idx, 'score' => $score, 'example' => $ex];
    }

    usort($scored, static fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['idx'] <=> $b['idx']);

    $selected = [];
    foreach ($scored as $item) {
        if (count($selected) >= $topK) {
            break;
        }
        // Only include refusal (sql === null) examples when they actually matched query intent
        if (($item['example']['sql'] ?? '') === null && $item['score'] <= 0) {
            continue;
        }
        if ($item['score'] > 0) {
            $selected[] = $item['example'];
        }
    }

    // Fallback to top 3 core inventory SQL examples if no keywords matched
    if (!$selected) {
        foreach ($examples as $ex) {
            if (($ex['sql'] ?? null) === null) {
                continue;
            }
            $selected[] = $ex;
            if (count($selected) >= min(3, $topK)) {
                break;
            }
        }
    }

    return $selected;
}

/**
 * Live Value Grounding from read-only public lookup tables (warehouses, categories, units).
 * Never accesses admins, roles, permissions, or private user tables.
 */
function ai_retrieve_catalog_values(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $pdo = ai_db();
        $whRows = $pdo->query('SELECT code, name FROM warehouses WHERE is_active = 1 ORDER BY id LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
        $catRows = $pdo->query('SELECT name FROM categories WHERE status = 1 ORDER BY id LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
        $unitRows = $pdo->query('SELECT code, name FROM units WHERE is_active = 1 ORDER BY id LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);

        $whList = array_map(static fn($r) => $r['code'] . '="' . $r['name'] . '"', $whRows);
        $catList = array_map(static fn($r) => '"' . $r['name'] . '"', $catRows);
        $unitList = array_map(static fn($r) => $r['code'] . '="' . $r['name'] . '"', $unitRows);

        $lines = ['Actual catalog values in database (use these exact codes/names when filtering):'];
        if ($whList) {
            $lines[] = '- warehouses: ' . implode(', ', $whList);
        }
        if ($catList) {
            $lines[] = '- categories: ' . implode(', ', $catList);
        }
        if ($unitList) {
            $lines[] = '- units: ' . implode(', ', $unitList);
        }
        $cached = implode("\n", $lines);
        return $cached;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Assemble complete system prompt: ranked schema + live catalog values + dynamic few-shot examples + JSON contract.
 */
function ai_build_system_prompt(string $question, ?string $schemaContext = null): string {
    $context = $schemaContext ?? ai_retrieve_schema_context($question);
    $catalog = ai_retrieve_catalog_values();
    $examples = ai_retrieve_few_shot_examples($question, 3);

    $parts = [$context];
    if ($catalog !== '') {
        $parts[] = $catalog;
    }
    if ($examples) {
        $exLines = ['Reference MySQL 8 examples (adapt SELECT columns, JOINs, filters, and GROUP BY to match the exact user question; omit stock_balances when asked for all lots including zero-stock lots; use ABS(SUM(sm.quantity)) when asked for positive issued quantities; select d.number and d.partner_name when asked to list individual documents):'];
        foreach ($examples as $ex) {
            $exLines[] = 'Q: ' . $ex['question'];
            if (($ex['sql'] ?? null) === null) {
                $exLines[] = 'JSON: ' . json_encode([
                    'sql' => null,
                    'explanation' => $ex['explanation'] ?? 'Cơ sở dữ liệu kho hiện tại không lưu thông tin này.',
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $exLines[] = 'SQL: ' . $ex['sql'];
            }
        }
        $parts[] = implode("\n", $exLines);
    }

    $parts[] = "Return ONLY a valid JSON object with exactly two keys:\n"
        . "- \"sql\": a single MySQL 8 SELECT (or WITH ... SELECT) query using ONLY the 11 allowed tables and exact column names above (no trailing semicolon, no markdown, no comments, LIMIT <= 200). ALWAYS generate a SQL query whenever the question involves products, categories, units, warehouses, locations, lots, purchase/sale prices or price differences, stock balances, documents/lines, or stock movements. ONLY set \"sql\" to null when the question asks for entities that do NOT exist in these 11 tables (such as employees, staff payroll/salaries, commissions, customer sales orders/revenue, or admin/RBAC tables).\n"
        . "- \"explanation\": a brief Vietnamese explanation of what the query calculates (or why the database does not contain the requested information when \"sql\" is null).\n"
        . "Do not output any text outside the JSON object.";

    return implode("\n\n", $parts);
}

/**
 * Fetch installed chat models from local Ollama (/api/tags), with safe fallback to OLLAMA_MODEL.
 */
function ai_ollama_list_models(): array {
    $config = require __DIR__ . '/config.php';
    $ollama = $config['ollama'];
    $defaultModel = (string)$ollama['model'];

    $ch = curl_init($ollama['url'] . '/api/tags');
    if ($ch === false) {
        return [$defaultModel];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
        return [$defaultModel];
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded) || empty($decoded['models']) || !is_array($decoded['models'])) {
        return [$defaultModel];
    }

    $models = [];
    foreach ($decoded['models'] as $m) {
        $name = trim((string)($m['name'] ?? ''));
        if ($name === '' || stripos($name, 'embed') !== false) {
            continue;
        }
        $models[] = $name;
    }
    if (!$models) {
        return [$defaultModel];
    }
    // Keep configured default model first if installed
    usort($models, static function (string $a, string $b) use ($defaultModel): int {
        if ($a === $defaultModel) return -1;
        if ($b === $defaultModel) return 1;
        return strcmp($a, $b);
    });
    return array_values(array_unique($models));
}

/**
 * Validate requested model name against installed Ollama models.
 */
function ai_resolve_model(?string $requested): string {
    $config = require __DIR__ . '/config.php';
    $defaultModel = (string)$config['ollama']['model'];
    $requested = trim((string)$requested);
    if ($requested === '') {
        return $defaultModel;
    }
    $installed = ai_ollama_list_models();
    if (in_array($requested, $installed, true)) {
        return $requested;
    }
    return $defaultModel;
}

/**
 * Call local Ollama /api/chat expecting JSON {"sql": "..."|null, "explanation": "..."}.
 * Optionally includes previous failed SQL + error for self-correction repair turns.
 */
function ai_ollama_json(
    string $question,
    ?string $schemaContext = null,
    ?string $model = null,
    ?string $prevSql = null,
    ?string $prevError = null
): array {
    $config = require __DIR__ . '/config.php';
    $ollama = $config['ollama'];
    $usedModel = ai_resolve_model($model);
    $systemPrompt = ai_build_system_prompt($question, $schemaContext);

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $question],
    ];
    if ($prevSql !== null && $prevError !== null) {
        $messages[] = [
            'role' => 'assistant',
            'content' => json_encode(['sql' => $prevSql, 'explanation' => ''], JSON_UNESCAPED_UNICODE),
        ];
        $messages[] = [
            'role' => 'user',
            'content' => "Câu SQL trước bị lỗi khi kiểm duyệt/thực thi: {$prevError}\n"
                . "Hãy sửa lại câu MySQL 8 SELECT hợp lệ chỉ dùng đúng 11 bảng được phép và đúng tên cột trong schema context, không dùng hàm tổng hợp (SUM/COUNT/AVG) trong WHERE (hãy dùng HAVING), và trả về đúng JSON {\"sql\": ..., \"explanation\": ...}.",
        ];
    }

    $payload = json_encode([
        'model' => $usedModel,
        'stream' => false,
        'format' => 'json',
        'options' => [
            'temperature' => 0.0,
            'repeat_penalty' => 1.0,
            'num_predict' => 640,
            'num_ctx' => 4096,
        ],
        'messages' => $messages,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($ollama['url'] . '/api/chat');
    if ($ch === false) {
        throw new RuntimeException('Không thể khởi tạo kết nối cURL tới Ollama.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => (int)$ollama['timeout'],
    ]);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Lỗi kết nối Ollama: ' . $curlErr);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Ollama trả về HTTP ' . $httpCode . ': ' . mb_substr((string)$raw, 0, 200));
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded) || !isset($decoded['message']['content'])) {
        throw new RuntimeException('Phản hồi Ollama không đúng định dạng JSON.');
    }

    $content = trim((string)$decoded['message']['content']);
    // Strip accidental markdown code fences if the model wrapped JSON
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/', '', $content);

    $result = json_decode((string)$content, true);
    if (!is_array($result)) {
        throw new RuntimeException('Mô hình không sinh được phản hồi JSON hợp lệ.');
    }

    $explanation = isset($result['explanation']) && is_string($result['explanation']) ? trim($result['explanation']) : '';
    $rawSql = $result['sql'] ?? null;

    if ($rawSql === null || (is_string($rawSql) && trim($rawSql) === '')) {
        if ($explanation !== '') {
            return [
                'model' => $usedModel,
                'sql' => null,
                'explanation' => $explanation,
                'refused' => true,
            ];
        }
        throw new RuntimeException('Mô hình không sinh được câu SQL hợp lệ trong JSON.');
    }

    if (!is_string($rawSql)) {
        throw new RuntimeException('Mô hình không sinh được câu SQL hợp lệ trong JSON.');
    }

    return [
        'model' => $usedModel,
        'sql' => trim($rawSql),
        'explanation' => $explanation,
        'refused' => false,
    ];
}

/**
 * Strip balanced argument lists of SQL functions that contain `FROM` syntax (EXTRACT/TRIM/SUBSTRING).
 */
function ai_strip_balanced_functions(string $sql, array $fnNames): string {
    $pattern = '/\b(' . implode('|', $fnNames) . ')\s*\(/i';
    $out = '';
    $len = strlen($sql);
    $offset = 0;

    while (preg_match($pattern, $sql, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $matchPos = $m[0][1];
        $fnName = $m[1][0];
        $openPos = $matchPos + strlen($m[0][0]) - 1;

        $depth = 0;
        $pos = $openPos;
        while ($pos < $len) {
            $ch = $sql[$pos];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $pos++;
        }

        if ($depth !== 0) {
            break;
        }

        $out .= substr($sql, $offset, $matchPos - $offset) . $fnName . '()';
        $offset = $pos + 1;
    }

    return $out . substr($sql, $offset);
}

/**
 * Extract top-level CTE alias names from a `WITH [RECURSIVE] ...` query.
 */
function ai_extract_cte_names(string $sqlNoStrings): array {
    if (!preg_match('/^\s*WITH(?:\s+RECURSIVE)?\s+/i', $sqlNoStrings, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $pos = $m[0][1] + strlen($m[0][0]);
    $len = strlen($sqlNoStrings);
    $cteNames = [];

    while ($pos < $len) {
        if (!preg_match('/\G\s*(`?[a-zA-Z_][a-zA-Z0-9_]*`?)\s*(?:\([^()]*\))?\s+AS\s*\(/i', $sqlNoStrings, $cm, 0, $pos)) {
            break;
        }
        $cteNames[] = strtolower(str_replace('`', '', $cm[1]));
        $openPos = $pos + strlen($cm[0]) - 1;
        $depth = 0;
        $p = $openPos;
        while ($p < $len) {
            if ($sqlNoStrings[$p] === '(') {
                $depth++;
            } elseif ($sqlNoStrings[$p] === ')') {
                $depth--;
                if ($depth === 0) {
                    $p++;
                    break;
                }
            }
            $p++;
        }
        if ($depth !== 0) {
            break;
        }
        $pos = $p;
        while ($pos < $len && ctype_space($sqlNoStrings[$pos])) {
            $pos++;
        }
        if ($pos < $len && $sqlNoStrings[$pos] === ',') {
            $pos++;
            continue;
        }
        break;
    }

    return array_values(array_unique($cteNames));
}

/**
 * Extract all table identifiers referenced in FROM (including comma-joins), JOIN, and STRAIGHT_JOIN.
 */
function ai_extract_table_refs(string $sqlForTables): array {
    $refs = [];

    // 1. All JOIN and STRAIGHT_JOIN targets
    if (preg_match_all('/\b(?:STRAIGHT_)?JOIN\s+(?!\()(`?[a-zA-Z0-9_]+`?(?:\s*\.\s*`?[a-zA-Z0-9_]+`?)?)/i', $sqlForTables, $jm)) {
        foreach ($jm[1] as $raw) {
            $refs[] = $raw;
        }
    }

    // 2. All FROM clauses (including comma-separated table lists at depth 0)
    $len = strlen($sqlForTables);
    $boundaryRegex = '/^(?:WHERE|GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|UNION|INTERSECT|EXCEPT|WINDOW|FOR|LOCK|INTO|ON|USING|SET|VALUES|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|JOIN)\b/i';

    if (preg_match_all('/\bFROM\b/i', $sqlForTables, $fm, PREG_OFFSET_CAPTURE)) {
        foreach ($fm[0] as $match) {
            $start = $match[1] + strlen($match[0]);
            $depth = 0;
            $pos = $start;
            while ($pos < $len) {
                $ch = $sqlForTables[$pos];
                if ($ch === '(') {
                    $depth++;
                    $pos++;
                    continue;
                }
                if ($ch === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                    $pos++;
                    continue;
                }
                if ($depth === 0 && ($pos === $start || !preg_match('/[a-zA-Z0-9_]/', $sqlForTables[$pos - 1]))) {
                    if (preg_match($boundaryRegex, substr($sqlForTables, $pos))) {
                        break;
                    }
                }
                $pos++;
            }

            $segment = trim(substr($sqlForTables, $start, $pos - $start));
            if ($segment === '') {
                continue;
            }

            // Split segment by depth-0 commas
            $factors = [];
            $cur = '';
            $d = 0;
            $segLen = strlen($segment);
            for ($i = 0; $i < $segLen; $i++) {
                $c = $segment[$i];
                if ($c === '(') {
                    $d++;
                } elseif ($c === ')') {
                    $d--;
                } elseif ($c === ',' && $d === 0) {
                    $factors[] = trim($cur);
                    $cur = '';
                    continue;
                }
                $cur .= $c;
            }
            if (trim($cur) !== '') {
                $factors[] = trim($cur);
            }

            foreach ($factors as $factor) {
                if ($factor === '' || str_starts_with($factor, '(')) {
                    continue;
                }
                if (preg_match('/^(`?[a-zA-Z0-9_]+`?(?:\s*\.\s*`?[a-zA-Z0-9_]+`?)?)/', $factor, $tm)) {
                    $refs[] = $tm[1];
                }
            }
        }
    }

    return $refs;
}

/**
 * Strict SQL allowlist validator (single SELECT or read-only WITH ... SELECT, allowed tables only, LIMIT <= 200).
 */
function ai_validate_select(string $sql): string {
    $sql = trim($sql);
    // Strip one optional trailing semicolon
    $sql = rtrim($sql, " \t\n\r\0\x0B");
    if (str_ends_with($sql, ';')) {
        $sql = rtrim(substr($sql, 0, -1));
    }

    if ($sql === '') {
        throw new InvalidArgumentException('Câu SQL rỗng.');
    }

    // Disallow multiple statements (outside string literals check below as well, and strict global check)
    if (str_contains($sql, ';')) {
        throw new InvalidArgumentException('Chỉ cho phép đúng một câu lệnh SELECT.');
    }

    // Disallow SQL comments
    if (preg_match('/(--|\/\*|\*\/|#)/', $sql)) {
        throw new InvalidArgumentException('Không cho phép comment trong câu SQL.');
    }

    // Strip string literals FIRST so keywords/symbols inside Vietnamese text filters don't trigger false positives
    $sqlNoStrings = (string)preg_replace("/'(?:\\\\.|[^'\\\\])*'|\"(?:\\\\.|[^\"\\\\])*\"/", "''", $sql);

    // Disallow session/system variables outside string literals
    if (preg_match('/@@?[a-zA-Z_]/', $sqlNoStrings)) {
        throw new InvalidArgumentException('Không cho phép dùng biến session/system trong SQL.');
    }

    // Disallow dangerous keywords, DML/DDL, file I/O, locking, sleep (checked before SELECT check so DELETE/UPDATE are flagged as security errors)
    $forbiddenPattern = '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|MERGE|GRANT|REVOKE|CALL|EXECUTE|PREPARE|DEALLOCATE|LOCK|UNLOCK|SLEEP|BENCHMARK|LOAD_FILE|OUTFILE|DUMPFILE|HANDLER|SHOW|DESCRIBE|EXPLAIN|USE|SET|DO|KILL|FLUSH|RESET|PURGE|SHUTDOWN)\b/i';
    if (preg_match($forbiddenPattern, $sqlNoStrings, $m)) {
        throw new InvalidArgumentException('SQL chứa từ khóa bị cấm: ' . strtoupper($m[1]));
    }

    // Disallow REPLACE DML statement while permitting safe string function REPLACE(...)
    if (preg_match('/\bREPLACE\s*(?!\()/i', $sqlNoStrings)) {
        throw new InvalidArgumentException('SQL chứa từ khóa bị cấm: REPLACE');
    }

    // Disallow MySQL 8 row-locking clauses (FOR UPDATE / FOR SHARE)
    if (preg_match('/\bFOR\s+(?:UPDATE|SHARE)\b/i', $sqlNoStrings, $m)) {
        throw new InvalidArgumentException('Không cho phép mệnh đề khóa dòng: ' . strtoupper($m[0]));
    }

    // Must start with SELECT or read-only CTE (WITH ... SELECT)
    if (!preg_match('/^(?:SELECT|WITH)\b/i', $sqlNoStrings)) {
        throw new InvalidArgumentException('Chỉ cho phép câu lệnh bắt đầu bằng SELECT.');
    }
    if (preg_match('/^WITH\b/i', $sqlNoStrings) && !preg_match('/\bSELECT\b/i', $sqlNoStrings)) {
        throw new InvalidArgumentException('CTE phải đi kèm câu lệnh SELECT.');
    }

    // Disallow system schemas
    if (preg_match('/\b(information_schema|mysql|performance_schema|sys)\s*\./i', $sqlNoStrings, $m)) {
        throw new InvalidArgumentException('Không được phép truy vấn schema hệ thống: ' . $m[1]);
    }

    // Extract CTE aliases (valid only within this query) and strip EXTRACT/TRIM/SUBSTRING(... FROM ...) safely
    $cteNames = ai_extract_cte_names($sqlNoStrings);
    $sqlForTables = ai_strip_balanced_functions($sqlNoStrings, ['EXTRACT', 'TRIM', 'SUBSTRING']);

    $allowed = ai_allowed_tables();
    $foundPhysicalTables = [];
    $config = require __DIR__ . '/config.php';
    $allowedDb = strtolower((string)$config['ai_db']['database']);

    foreach (ai_extract_table_refs($sqlForTables) as $rawTable) {
        $clean = str_replace('`', '', (string)preg_replace('/\s+/', '', $rawTable));
        $isSchemaQualified = str_contains($clean, '.');
        if ($isSchemaQualified) {
            [$schema, $table] = explode('.', $clean, 2);
            if (strtolower($schema) !== $allowedDb) {
                throw new InvalidArgumentException('Không được phép truy vấn database khác: ' . $schema);
            }
        } else {
            $table = $clean;
        }

        $tableLower = strtolower($table);
        if (in_array($tableLower, $allowed, true)) {
            $foundPhysicalTables[] = $tableLower;
            continue;
        }
        if (!$isSchemaQualified && in_array($tableLower, $cteNames, true)) {
            continue;
        }
        throw new InvalidArgumentException('Bảng không nằm trong danh sách được phép: ' . $table);
    }

    if (!$foundPhysicalTables) {
        throw new InvalidArgumentException('Câu SQL phải truy vấn ít nhất một bảng nghiệp vụ được phép.');
    }

    // Enforce LIMIT <= 200
    if (preg_match('/\bLIMIT\s+(\d+)\s*,\s*(\d+)\s*$/i', $sql, $lim)) {
        $count = (int)$lim[2];
        if ($count < 1 || $count > 200) {
            throw new InvalidArgumentException('LIMIT vượt quá giới hạn tối đa 200 dòng.');
        }
    } elseif (preg_match('/\bLIMIT\s+(\d+)(?:\s+OFFSET\s+\d+)?\s*$/i', $sql, $lim)) {
        $count = (int)$lim[1];
        if ($count < 1 || $count > 200) {
            throw new InvalidArgumentException('LIMIT vượt quá giới hạn tối đa 200 dòng.');
        }
    } else {
        $sql .= ' LIMIT 200';
    }

    return $sql;
}

/**
 * Execute validated SELECT via read-only AI_DB_* connection with MAX_EXECUTION_TIME=5000ms.
 */
function ai_execute_read_query(string $sql): array {
    $validatedSql = ai_validate_select($sql);

    try {
        $pdo = ai_db();
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME = 5000');
    } catch (PDOException $e) {
        // Retry once on dropped connection
        $pdo = ai_db(true);
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME = 5000');
    }

    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query($validatedSql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 200) {
            $rows = array_slice($rows, 0, 200);
        }
        $columns = [];
        if ($rows) {
            $columns = array_keys($rows[0]);
        } else {
            $colCount = $stmt->columnCount();
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                if (is_array($meta) && isset($meta['name'])) {
                    $columns[] = $meta['name'];
                }
            }
        }
        $pdo->commit();
        return [
            'sql' => $validatedSql,
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => count($rows),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Determine whether an error represents a security/policy violation (must fail immediately without retry).
 */
function ai_is_security_error(Throwable $e): bool {
    if ($e instanceof PDOException) {
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        return in_array($driverCode, [1044, 1045, 1142, 1143], true);
    }
    if ($e instanceof InvalidArgumentException) {
        $msg = $e->getMessage();
        foreach ([
            'Bảng không nằm trong danh sách được phép',
            'SQL chứa từ khóa bị cấm',
            'schema hệ thống',
            'database khác',
            'comment trong câu SQL',
            'đúng một câu lệnh SELECT',
            'biến session/system',
            'mệnh đề khóa dòng',
        ] as $marker) {
            if (str_contains($msg, $marker)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Orchestrate LLM generation -> validation -> execution with bounded SQL self-correction retries
 * and clean out-of-domain refusal handling.
 */
function ai_run_question_pipeline(
    string $question,
    callable $llmGenerator,
    callable $queryExecutor,
    int $maxRetries = 2
): array {
    $prevSql = null;
    $prevError = null;
    $lastException = null;

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        $llm = $llmGenerator($prevSql, $prevError);
        $usedModel = (string)($llm['model'] ?? '');
        $explanation = (string)($llm['explanation'] ?? '');
        $refused = !empty($llm['refused']) || ($llm['sql'] ?? null) === null || trim((string)$llm['sql']) === '';

        if ($refused) {
            return [
                'question' => $question,
                'model' => $usedModel,
                'explanation' => $explanation !== '' ? $explanation : 'Cơ sở dữ liệu kho hiện tại không có dữ liệu phù hợp để trả lời câu hỏi này.',
                'sql' => '',
                'columns' => [],
                'rows' => [],
                'row_count' => 0,
                'refused' => true,
            ];
        }

        $candidateSql = trim((string)$llm['sql']);
        try {
            $exec = $queryExecutor($candidateSql);
            return [
                'question' => $question,
                'model' => $usedModel,
                'explanation' => $explanation,
                'sql' => $exec['sql'],
                'columns' => $exec['columns'],
                'rows' => $exec['rows'],
                'row_count' => $exec['row_count'],
                'refused' => false,
            ];
        } catch (Throwable $e) {
            $lastException = $e;
            if (ai_is_security_error($e) || $attempt >= $maxRetries) {
                throw $e;
            }
            $prevSql = $candidateSql;
            $prevError = $e->getMessage();
        }
    }

    throw $lastException ?? new RuntimeException('Không thể xử lý câu hỏi AI.');
}

/**
 * Full RAG -> Ollama (with self-correction retry) -> Validate -> Read-only MySQL execution pipeline.
 */
function ai_ask_question(string $question, ?string $model = null): array {
    $question = trim($question);
    if ($question === '') {
        throw new InvalidArgumentException('Vui lòng nhập câu hỏi dữ liệu.');
    }
    $context = ai_retrieve_schema_context($question);

    return ai_run_question_pipeline(
        $question,
        static fn(?string $prevSql, ?string $prevError): array => ai_ollama_json($question, $context, $model, $prevSql, $prevError),
        'ai_execute_read_query',
        2
    );
}

/**
 * List available Markdown report templates from reports/templates/*.md.
 */
function report_list_templates(): array {
    $config = require __DIR__ . '/config.php';
    $dir = $config['reports']['templates_dir'];
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/*.md') ?: [];
    sort($files);
    $templates = [];
    foreach ($files as $file) {
        $slug = basename($file, '.md');
        if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
            continue;
        }
        $content = (string)file_get_contents($file);
        $title = $slug;
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            $title = trim($m[1]);
        }
        preg_match_all('/<!--\s*ai-query:\s*(.*?)\s*-->/s', $content, $markers);
        $templates[] = [
            'slug' => $slug,
            'title' => $title,
            'filename' => basename($file),
            'queries' => array_map('trim', $markers[1] ?? []),
        ];
    }
    return $templates;
}

/**
 * Validate template slug and return safe realpath inside templates_dir.
 */
function report_validate_slug(string $slug): string {
    if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
        throw new InvalidArgumentException('Mã mẫu báo cáo không hợp lệ.');
    }
    $config = require __DIR__ . '/config.php';
    $baseDir = realpath($config['reports']['templates_dir']);
    if ($baseDir === false) {
        throw new RuntimeException('Thư mục mẫu báo cáo chưa tồn tại.');
    }
    $candidate = realpath($baseDir . DIRECTORY_SEPARATOR . $slug . '.md');
    if ($candidate === false || !str_starts_with($candidate, $baseDir . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        throw new InvalidArgumentException('Không tìm thấy mẫu báo cáo: ' . $slug);
    }
    return $candidate;
}

/**
 * Convert SQL result rows into a GFM Markdown table with proper escaping.
 */
function report_rows_to_markdown_table(array $columns, array $rows): string {
    if (!$columns || !$rows) {
        return "_Không có dữ liệu phù hợp._";
    }
    $escapeCell = static function ($val): string {
        $s = $val === null ? '' : (string)$val;
        $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);
        $s = str_replace('|', '\\|', $s);
        return trim($s);
    };

    $header = '| ' . implode(' | ', array_map($escapeCell, $columns)) . ' |';
    $separator = '| ' . implode(' | ', array_fill(0, count($columns), '---')) . ' |';
    $lines = [$header, $separator];

    foreach ($rows as $row) {
        $cells = [];
        foreach ($columns as $col) {
            $cells[] = $escapeCell($row[$col] ?? '');
        }
        $lines[] = '| ' . implode(' | ', $cells) . ' |';
    }

    return implode("\n", $lines);
}

/**
 * Render a Markdown template by substituting {{generated_at}} and executing all <!-- ai-query: ... --> markers.
 */
function report_render_markdown(string $slug, ?string $model = null): array {
    $templatePath = report_validate_slug($slug);
    $template = (string)file_get_contents($templatePath);
    $usedModel = ai_resolve_model($model);

    $generatedAt = date('d/m/Y H:i:s') . ' (UTC+7)';
    $rendered = str_replace('{{generated_at}}', $generatedAt, $template);

    $queries = [];
    $rendered = (string)preg_replace_callback(
        '/<!--\s*ai-query:\s*(.*?)\s*-->/s',
        static function (array $match) use (&$queries, $usedModel): string {
            $prompt = trim($match[1]);
            $res = ai_ask_question($prompt, $usedModel);
            $queries[] = [
                'prompt' => $prompt,
                'model' => $res['model'],
                'sql' => $res['sql'],
                'explanation' => $res['explanation'],
                'row_count' => $res['row_count'],
            ];

            $parts = [];
            if ($res['explanation'] !== '') {
                $parts[] = '> ' . str_replace(["\r\n", "\r", "\n"], ' ', $res['explanation']);
            }
            if ($res['sql'] !== '') {
                $parts[] = "```sql\n" . $res['sql'] . "\n```";
            }
            $parts[] = report_rows_to_markdown_table($res['columns'], $res['rows']);
            $parts[] = '_Tổng số dòng trả về: ' . $res['row_count'] . ' (giới hạn tối đa 200 dòng · model: ' . $res['model'] . ')._';

            return implode("\n\n", $parts);
        },
        $rendered
    );

    return [
        'slug' => $slug,
        'model' => $usedModel,
        'generated_at' => $generatedAt,
        'markdown' => $rendered,
        'queries' => $queries,
    ];
}

/**
 * Convert rendered Markdown to A4 landscape PDF using ParsedownExtra (Safe Mode) + Dompdf.
 */
function report_markdown_to_pdf(string $markdown, string $pdfPath): void {
    ai_autoload();
    if (!class_exists('Parsedown') || !class_exists('Dompdf\\Dompdf')) {
        throw new RuntimeException('Thiếu thư viện Parsedown hoặc Dompdf. Hãy build lại container PHP.');
    }

    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    $bodyHtml = $parsedown->text($markdown);

    $html = '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><style>'
        . '@page { margin: 16mm 14mm; }'
        . 'body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1f2937; line-height: 1.45; }'
        . 'h1 { font-size: 18px; color: #0f172a; margin: 0 0 8px 0; border-bottom: 2px solid #1d4ed8; padding-bottom: 6px; }'
        . 'h2 { font-size: 14px; color: #1e3a8a; margin: 14px 0 6px 0; }'
        . 'h3 { font-size: 12px; color: #334155; margin: 10px 0 4px 0; }'
        . 'p { margin: 4px 0 8px 0; }'
        . 'blockquote { margin: 6px 0 10px 0; padding: 6px 10px; background: #eff6ff; border-left: 3px solid #2563eb; color: #1e3a8a; }'
        . 'pre { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 8px; font-size: 9.5px; white-space: pre-wrap; word-break: break-word; font-family: "DejaVu Sans Mono", monospace; }'
        . 'code { font-family: "DejaVu Sans Mono", monospace; font-size: 9.5px; }'
        . 'table { width: 100%; border-collapse: collapse; margin: 8px 0 12px 0; table-layout: auto; }'
        . 'th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; vertical-align: top; word-wrap: break-word; }'
        . 'th { background: #e2e8f0; color: #0f172a; font-weight: bold; }'
        . 'tr:nth-child(even) td { background: #f8fafc; }'
        . 'ul { margin: 6px 0; padding-left: 18px; }'
        . '</style></head><body>' . $bodyHtml . '</body></html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $output = $dompdf->output();
    unset($dompdf);
    gc_collect_cycles();
    if (file_put_contents($pdfPath, $output) === false) {
        throw new RuntimeException('Không thể ghi file PDF báo cáo: ' . basename($pdfPath));
    }
}

/**
 * Generate both <job-uuid>.md and <job-uuid>.pdf in REPORT_DIR.
 */
function report_generate_files(string $jobId, string $slug, ?string $model = null): array {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobId)) {
        throw new InvalidArgumentException('Job UUID không hợp lệ.');
    }
    $config = require __DIR__ . '/config.php';
    $exportsDir = $config['reports']['exports_dir'];
    if (!is_dir($exportsDir) && !mkdir($exportsDir, 0775, true) && !is_dir($exportsDir)) {
        throw new RuntimeException('Không thể tạo thư mục lưu báo cáo: ' . $exportsDir);
    }

    $rendered = report_render_markdown($slug, $model);
    $mdPath = rtrim($exportsDir, '/\\') . DIRECTORY_SEPARATOR . $jobId . '.md';
    $pdfPath = rtrim($exportsDir, '/\\') . DIRECTORY_SEPARATOR . $jobId . '.pdf';

    if (file_put_contents($mdPath, $rendered['markdown']) === false) {
        throw new RuntimeException('Không thể ghi file Markdown báo cáo.');
    }

    report_markdown_to_pdf($rendered['markdown'], $pdfPath);

    return [
        'slug' => $slug,
        'model' => $rendered['model'],
        'generated_at' => $rendered['generated_at'],
        'markdown' => $rendered['markdown'],
        'queries' => $rendered['queries'],
        'md_file' => $jobId . '.md',
        'pdf_file' => $jobId . '.pdf',
    ];
}

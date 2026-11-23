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
    $tokens = preg_split('/[^\p{L}\p{N}_]+/u', $qLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $hints = [
        'categories' => ['danh mục', 'loại hàng', 'category', 'categories'],
        'products' => ['sản phẩm', 'hàng hóa', 'mặt hàng', 'sku', 'tối thiểu', 'min_stock', 'product'],
        'units' => ['đơn vị', 'đvt', 'unit', 'cái', 'hộp', 'thùng'],
        'warehouses' => ['kho', 'warehouse'],
        'warehouse_locations' => ['vị trí', 'kệ', 'ô', 'bin', 'zone', 'rack', 'location', 'kho'],
        'product_lots' => ['lô', 'hạn dùng', 'hết hạn', 'lot', 'expires'],
        'stock_balances' => ['tồn', 'số dư', 'hiện tại', 'stock', 'balance', 'thiếu'],
        'inventory_documents' => ['phiếu', 'chứng từ', 'nhập', 'xuất', 'ghi sổ', 'posted', 'đối tác', 'document'],
        'inventory_lines' => ['dòng phiếu', 'chi tiết phiếu', 'nhập', 'xuất', 'đơn giá', 'line'],
        'stock_movements' => ['biến động', 'lịch sử kho', 'xuất nhập', 'movement', 'tháng', 'ngày'],
        'product_prices' => ['giá', 'bảng giá', 'giá bán', 'giá nhập', 'price'],
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
                    if (str_contains($qLower, $kw)) {
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
 * Call local Ollama /api/chat expecting JSON {"sql": "...", "explanation": "..."}.
 */
function ai_ollama_json(string $question, ?string $schemaContext = null, ?string $model = null): array {
    $config = require __DIR__ . '/config.php';
    $ollama = $config['ollama'];
    $usedModel = ai_resolve_model($model);
    $context = $schemaContext ?? ai_retrieve_schema_context($question);

    $systemPrompt = $context . "\n\n"
        . "Return ONLY a valid JSON object with exactly two keys:\n"
        . "- \"sql\": a single MySQL 8 SELECT query (no trailing semicolon, no markdown, no comments, LIMIT <= 200).\n"
        . "- \"explanation\": a brief Vietnamese explanation of what the query calculates.\n"
        . "Do not output any text outside the JSON object.";

    $payload = json_encode([
        'model' => $usedModel,
        'stream' => false,
        'format' => 'json',
        'options' => [
            'temperature' => 0.1,
            'repeat_penalty' => 1.15,
            'num_predict' => 512,
        ],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $question],
        ],
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
    if (!is_array($result) || empty($result['sql']) || !is_string($result['sql'])) {
        throw new RuntimeException('Mô hình không sinh được câu SQL hợp lệ trong JSON.');
    }

    return [
        'model' => $usedModel,
        'sql' => trim($result['sql']),
        'explanation' => isset($result['explanation']) && is_string($result['explanation']) ? trim($result['explanation']) : '',
    ];
}

/**
 * Strict SQL allowlist validator (single SELECT, allowed tables only, LIMIT <= 200).
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

    // Disallow multiple statements
    if (str_contains($sql, ';')) {
        throw new InvalidArgumentException('Chỉ cho phép đúng một câu lệnh SELECT.');
    }

    // Disallow SQL comments
    if (preg_match('/(--|\/\*|\*\/|#)/', $sql)) {
        throw new InvalidArgumentException('Không cho phép comment trong câu SQL.');
    }

    // Must start with SELECT
    if (!preg_match('/^SELECT\b/i', $sql)) {
        throw new InvalidArgumentException('Chỉ cho phép câu lệnh bắt đầu bằng SELECT.');
    }

    // Disallow session/system variables
    if (preg_match('/@@?[a-zA-Z_]/', $sql)) {
        throw new InvalidArgumentException('Không cho phép dùng biến session/system trong SQL.');
    }

    // Disallow dangerous keywords, DML/DDL, file I/O, locking, sleep
    $forbiddenPattern = '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|MERGE|GRANT|REVOKE|CALL|EXECUTE|PREPARE|DEALLOCATE|LOCK|UNLOCK|SLEEP|BENCHMARK|LOAD_FILE|OUTFILE|DUMPFILE|HANDLER|SHOW|DESCRIBE|EXPLAIN|USE|SET|DO|KILL|FLUSH|RESET|PURGE|SHUTDOWN)\b/i';
    if (preg_match($forbiddenPattern, $sql, $m)) {
        throw new InvalidArgumentException('SQL chứa từ khóa bị cấm: ' . strtoupper($m[1]));
    }

    // Disallow system schemas
    if (preg_match('/\b(information_schema|mysql|performance_schema|sys)\s*\./i', $sql, $m)) {
        throw new InvalidArgumentException('Không được phép truy vấn schema hệ thống: ' . $m[1]);
    }

    // Strip string literals before scanning table names
    $sqlNoStrings = preg_replace("/'(?:\\\\.|[^'\\\\])*'|\"(?:\\\\.|[^\"\\\\])*\"/", "''", $sql);
    // Remove EXTRACT(... FROM ...), TRIM(... FROM ...), SUBSTRING(... FROM ...) so FROM isn't misread as table
    $sqlForTables = preg_replace('/\b(EXTRACT|TRIM|SUBSTRING)\s*\([^()]*\)/i', '$1()', (string)$sqlNoStrings);

    $allowed = ai_allowed_tables();
    $foundTables = [];

    if (preg_match_all('/\b(?:FROM|JOIN)\s+(?!\()(`?[a-zA-Z0-9_]+`?(?:\s*\.\s*`?[a-zA-Z0-9_]+`?)?)/i', (string)$sqlForTables, $matches)) {
        foreach ($matches[1] as $rawTable) {
            $clean = str_replace('`', '', preg_replace('/\s+/', '', $rawTable));
            if (str_contains($clean, '.')) {
                [$schema, $table] = explode('.', $clean, 2);
                $config = require __DIR__ . '/config.php';
                $allowedDb = strtolower((string)$config['ai_db']['database']);
                if (strtolower($schema) !== $allowedDb) {
                    throw new InvalidArgumentException('Không được phép truy vấn database khác: ' . $schema);
                }
            } else {
                $table = $clean;
            }
            $tableLower = strtolower($table);
            if (!in_array($tableLower, $allowed, true)) {
                throw new InvalidArgumentException('Bảng không nằm trong danh sách được phép: ' . $table);
            }
            $foundTables[] = $tableLower;
        }
    }

    if (!$foundTables) {
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
 * Full RAG -> Ollama -> Validate -> Read-only MySQL execution pipeline.
 */
function ai_ask_question(string $question, ?string $model = null): array {
    $question = trim($question);
    if ($question === '') {
        throw new InvalidArgumentException('Vui lòng nhập câu hỏi dữ liệu.');
    }
    $context = ai_retrieve_schema_context($question);
    $llm = ai_ollama_json($question, $context, $model);
    $exec = ai_execute_read_query($llm['sql']);

    return [
        'question' => $question,
        'model' => $llm['model'],
        'explanation' => $llm['explanation'],
        'sql' => $exec['sql'],
        'columns' => $exec['columns'],
        'rows' => $exec['rows'],
        'row_count' => $exec['row_count'],
    ];
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
            $parts[] = "```sql\n" . $res['sql'] . "\n```";
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

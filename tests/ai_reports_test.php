<?php
declare(strict_types=1);

$srcDir = is_dir(__DIR__ . '/../app/src') ? __DIR__ . '/../app/src' : '/var/www/html/src';
require_once $srcDir . '/ai_service.php';
require_once $srcDir . '/ai_job_repository.php';

function assert_true(bool $cond, string $label): void {
    if (!$cond) {
        $msg = "FAIL: {$label}\n";
        defined('STDERR') ? fwrite(STDERR, $msg) : print($msg);
        exit(1);
    }
    echo "PASS: {$label}\n";
}

function assert_throws(callable $fn, string $label): void {
    try {
        $fn();
    } catch (Throwable $e) {
        echo "PASS: {$label} ({$e->getMessage()})\n";
        return;
    }
    $msg = "FAIL (expected exception): {$label}\n";
    defined('STDERR') ? fwrite(STDERR, $msg) : print($msg);
    exit(1);
}

// 1. RAG lexical retrieval, few-shot examples & value grounding
$ctx = ai_retrieve_schema_context('Tồn hiện tại theo kho và đơn vị');
assert_true(str_contains($ctx, 'stock_balances'), 'RAG includes stock_balances');
assert_true(str_contains($ctx, 'warehouses'), 'RAG includes warehouses');
assert_true(str_contains($ctx, 'warehouse_locations'), 'RAG includes warehouse_locations');
assert_true(str_contains($ctx, 'stock_balances.location_id = warehouse_locations.id'), 'RAG includes exact FK join rule');

$lotCtx = ai_retrieve_schema_context('Lô hàng sắp hết hạn trước năm 2027');
preg_match_all('/^- `([a-z_]+)\(/m', $lotCtx, $lotMatches);
$lotTables = $lotMatches[1] ?? [];
assert_true(($lotTables[0] ?? '') === 'product_lots', 'RAG ranks product_lots #1 for expiry query');
assert_true(($lotTables[1] ?? '') !== 'warehouse_locations', 'RAG does not falsely boost warehouse_locations on Vietnamese "ô"');

assert_true(function_exists('ai_retrieve_few_shot_examples'), 'Dynamic few-shot RAG function exists');
$priceExamples = ai_retrieve_few_shot_examples('Xem giá bán hiện hành của từng sản phẩm thuộc danh mục Điện thoại', 4);
assert_true(count($priceExamples) >= 1 && count($priceExamples) <= 4, 'Dynamic few-shot returns bounded top-K examples');
$priceExamplesJoined = implode("\n", array_column($priceExamples, 'sql'));
assert_true(str_contains($priceExamplesJoined, 'product_prices') && str_contains($priceExamplesJoined, 'valid_to'), 'Few-shot retrieval surfaces temporal product_prices example');

assert_true(function_exists('ai_retrieve_catalog_values'), 'Catalog value grounding function exists');
$catalogText = ai_retrieve_catalog_values();
assert_true(str_contains($catalogText, 'MAIN') && str_contains($catalogText, 'SUB'), 'Catalog value grounding exposes warehouse codes MAIN/SUB');
assert_true(!str_contains($catalogText, 'password_hash') && !str_contains($catalogText, 'admins'), 'Catalog value grounding never leaks admins/passwords');

// 2. SQL allowlist validator (security + false-positive fixes + CTE)
$valid1 = ai_validate_select('SELECT p.sku, SUM(b.quantity) AS total FROM stock_balances b JOIN products p ON p.id = b.product_id GROUP BY p.sku ORDER BY total DESC');
assert_true(str_ends_with($valid1, 'LIMIT 200'), 'Auto-appends LIMIT 200 when missing');

$valid2 = ai_validate_select('SELECT EXTRACT(MONTH FROM created_at) AS m, SUM(quantity) FROM stock_movements GROUP BY m LIMIT 12');
assert_true(str_contains($valid2, 'LIMIT 12'), 'Allows EXTRACT(... FROM ...) and LIMIT <= 200');

$valid3 = ai_validate_select("SELECT * FROM inventory_documents WHERE note LIKE '%do hong%' AND partner_name LIKE '%show%' LIMIT 10");
assert_true(str_contains($valid3, '%do hong%'), 'Allows Vietnamese "do" and "show" inside string literals');

$valid4 = ai_validate_select("SELECT REPLACE(sku, '-', '_') AS clean_sku FROM products WHERE name LIKE '%set combo%' LIMIT 10");
assert_true(str_contains($valid4, 'REPLACE('), 'Allows safe SQL REPLACE() function and "set" inside string literals');

$valid5 = ai_validate_select("SELECT SUBSTRING(COALESCE(sku, '') FROM 1 FOR 4) AS s FROM products LIMIT 10");
assert_true(str_contains($valid5, 'SUBSTRING('), 'Allows nested function calls inside SUBSTRING(... FROM ...)');

$validCte = ai_validate_select('WITH latest AS (SELECT product_id, MAX(valid_from) AS mf FROM product_prices GROUP BY product_id) SELECT p.sku, l.mf FROM products p JOIN latest l ON l.product_id = p.id LIMIT 20');
assert_true(str_starts_with($validCte, 'WITH latest'), 'Allows read-only MySQL 8 CTE over allowlisted tables');

assert_throws(fn() => ai_validate_select('SELECT * FROM admins'), 'Rejects admins table');
assert_throws(fn() => ai_validate_select('SELECT p.id, a.username FROM products p, admins a LIMIT 5'), 'Rejects comma-join admins bypass');
assert_throws(fn() => ai_validate_select('SELECT p.id, a.username FROM products p STRAIGHT_JOIN admins a ON 1=1 LIMIT 5'), 'Rejects STRAIGHT_JOIN admins bypass');
assert_throws(fn() => ai_validate_select('WITH bad AS (SELECT id FROM admins) SELECT * FROM bad LIMIT 5'), 'Rejects CTE referencing forbidden table admins');
assert_throws(fn() => ai_validate_select('SELECT * FROM products LIMIT 5 FOR SHARE'), 'Rejects MySQL 8 FOR SHARE lock clause');
assert_throws(fn() => ai_validate_select('REPLACE INTO products (id, name) VALUES (1, "x")'), 'Rejects REPLACE INTO DML');
assert_throws(fn() => ai_validate_select('SELECT * FROM products; DELETE FROM products'), 'Rejects multi-statement');
assert_throws(fn() => ai_validate_select('SELECT * FROM products -- comment'), 'Rejects SQL comment');
assert_throws(fn() => ai_validate_select('SELECT * FROM products LIMIT 500'), 'Rejects LIMIT > 200');
assert_throws(fn() => ai_validate_select('SELECT SLEEP(5) FROM products'), 'Rejects SLEEP');
assert_throws(fn() => ai_validate_select('SELECT * FROM information_schema.tables'), 'Rejects information_schema');
assert_throws(fn() => ai_validate_select('SELECT @@version FROM products'), 'Rejects system variables');
assert_throws(fn() => ai_validate_select('UPDATE products SET min_stock = 0'), 'Rejects UPDATE');

// 3. Self-correction retry & Out-of-Domain refusal pipeline
assert_true(function_exists('ai_run_question_pipeline'), 'Question retry/refusal pipeline helper exists');

// 3a. Recovers from a SQL column error on retry #1
$attempts = 0;
$recovered = ai_run_question_pipeline(
    'Đếm sản phẩm',
    function (?string $prevSql, ?string $prevError) use (&$attempts): array {
        $attempts++;
        if ($attempts === 1) {
            return ['model' => 'test-model', 'sql' => 'SELECT COUNT(nonexistent_col) FROM products', 'explanation' => 'Attempt 1', 'refused' => false];
        }
        assert_true(str_contains((string)$prevError, 'nonexistent_col'), 'Retry callback receives previous MySQL error');
        return ['model' => 'test-model', 'sql' => 'SELECT COUNT(*) AS cnt FROM products LIMIT 1', 'explanation' => 'Fixed attempt 2', 'refused' => false];
    },
    'ai_execute_read_query',
    2
);
assert_true($attempts === 2 && (int)($recovered['rows'][0]['cnt'] ?? 0) > 0, 'Self-correction retries SQL error and succeeds');

// 3b. Does NOT retry security violations (forbidden table)
$secAttempts = 0;
assert_throws(function () use (&$secAttempts): void {
    ai_run_question_pipeline(
        'Lấy mật khẩu admins',
        function () use (&$secAttempts): array {
            $secAttempts++;
            return ['model' => 'test-model', 'sql' => 'SELECT username, password_hash FROM admins LIMIT 5', 'explanation' => 'Bad', 'refused' => false];
        },
        'ai_execute_read_query',
        2
    );
}, 'Security violation fails immediately without retrying');
assert_true($secAttempts === 1, 'Security violation stopped after exactly 1 attempt');

// 3c. Handles clean out-of-domain refusal (sql: null) without executing SQL
$ood = ai_run_question_pipeline(
    'Doanh thu từng nhân viên kinh doanh',
    fn(): array => ['model' => 'test-model', 'sql' => null, 'explanation' => 'Cơ sở dữ liệu kho không có bảng nhân viên hay doanh thu.', 'refused' => true],
    fn(): array => throw new RuntimeException('Should never execute SQL on refusal'),
    2
);
assert_true($ood['sql'] === '' && $ood['row_count'] === 0 && !empty($ood['refused']), 'Out-of-domain refusal returns empty result cleanly without running SQL');

// 4. Report templates & slug validation
$templates = report_list_templates();
$slugs = array_column($templates, 'slug');
assert_true(in_array('ton_kho_theo_kho', $slugs, true), 'Template ton_kho_theo_kho discovered');
assert_true(in_array('xuat_nhap_thang', $slugs, true), 'Template xuat_nhap_thang discovered');
assert_throws(fn() => report_validate_slug('../secret'), 'Rejects path traversal slug');

// 5. Markdown table escaping
$mdTable = report_rows_to_markdown_table(['Tên', 'Ghi chú'], [['Tên' => 'A | B', 'Ghi chú' => "Dòng 1\nDòng 2"]]);
assert_true(str_contains($mdTable, 'A \\| B'), 'Escapes pipe in Markdown table');
assert_true(str_contains($mdTable, 'Dòng 1 Dòng 2'), 'Normalizes newlines in Markdown table');

// 6. Markdown -> PDF rendering (Parsedown Safe Mode + Dompdf)
$tmpPdf = sys_get_temp_dir() . '/test_ai_report_' . bin2hex(random_bytes(4)) . '.pdf';
$sampleMd = "# Báo cáo kiểm thử tiếng Việt\n\n<script>alert(1)</script>\n\n" . $mdTable;
report_markdown_to_pdf($sampleMd, $tmpPdf);
assert_true(is_file($tmpPdf) && filesize($tmpPdf) > 1000, 'PDF generated successfully');
$pdfHeader = file_get_contents($tmpPdf, false, null, 0, 5);
assert_true($pdfHeader === '%PDF-', 'Generated file starts with %PDF-');
@unlink($tmpPdf);

// 7. Read-only MySQL ai_reader check
$dbRes = ai_execute_read_query('SELECT COUNT(*) AS cnt FROM products');
assert_true(isset($dbRes['rows'][0]['cnt']), 'ai_reader can SELECT products (cnt=' . ($dbRes['rows'][0]['cnt'] ?? '?') . ')');
assert_throws(fn() => ai_db()->query('SELECT COUNT(*) FROM admins'), 'ai_reader blocked from SELECT admins at MySQL level');

// 8. Worker stale running job recovery check
assert_true(function_exists('ai_job_recover_stale_running'), 'Stale running job recovery function exists');
$adminId = (int)db()->query('SELECT id FROM admins ORDER BY id ASC LIMIT 1')->fetchColumn();
$staleId = ai_job_create($adminId, 'question', ['question' => 'stale test']);
$freshId = ai_job_create($adminId, 'question', ['question' => 'fresh test']);
try {
    db()->prepare('UPDATE ai_jobs SET status = "running", started_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id = ?')->execute([$staleId]);
    db()->prepare('UPDATE ai_jobs SET status = "running", started_at = NOW() WHERE id = ?')->execute([$freshId]);
    $recoveredCount = ai_job_recover_stale_running(600);
    $staleAfter = ai_job_find($staleId);
    $freshAfter = ai_job_find($freshId);
    assert_true($recoveredCount >= 1 && $staleAfter['status'] === 'failed', 'Stale running job recovered to failed');
    assert_true($freshAfter['status'] === 'running', 'Fresh running job left untouched');
} finally {
    db()->prepare('DELETE FROM ai_jobs WHERE id IN (?, ?)')->execute([$staleId, $freshId]);
}

echo "\nAll AI & Report unit checks passed.\n";

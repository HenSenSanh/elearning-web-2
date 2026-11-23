<?php
declare(strict_types=1);

$srcDir = is_dir(__DIR__ . '/../app/src') ? __DIR__ . '/../app/src' : '/var/www/html/src';
require_once $srcDir . '/ai_service.php';
require_once $srcDir . '/ai_job_repository.php';

function assert_true(bool $cond, string $label): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$label}\n");
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
    fwrite(STDERR, "FAIL (expected exception): {$label}\n");
    exit(1);
}

// 1. RAG lexical retrieval
$ctx = ai_retrieve_schema_context('Tồn hiện tại theo kho và đơn vị');
assert_true(str_contains($ctx, 'stock_balances'), 'RAG includes stock_balances');
assert_true(str_contains($ctx, 'warehouses'), 'RAG includes warehouses');
assert_true(str_contains($ctx, 'warehouse_locations'), 'RAG includes warehouse_locations');
assert_true(str_contains($ctx, 'stock_balances.location_id = warehouse_locations.id'), 'RAG includes exact FK join rule');

// 2. SQL allowlist validator
$valid1 = ai_validate_select('SELECT p.sku, SUM(b.quantity) AS total FROM stock_balances b JOIN products p ON p.id = b.product_id GROUP BY p.sku ORDER BY total DESC');
assert_true(str_ends_with($valid1, 'LIMIT 200'), 'Auto-appends LIMIT 200 when missing');

$valid2 = ai_validate_select('SELECT EXTRACT(MONTH FROM created_at) AS m, SUM(quantity) FROM stock_movements GROUP BY m LIMIT 12');
assert_true(str_contains($valid2, 'LIMIT 12'), 'Allows EXTRACT(... FROM ...) and LIMIT <= 200');

assert_throws(fn() => ai_validate_select('SELECT * FROM admins'), 'Rejects admins table');
assert_throws(fn() => ai_validate_select('SELECT * FROM products; DELETE FROM products'), 'Rejects multi-statement');
assert_throws(fn() => ai_validate_select('SELECT * FROM products -- comment'), 'Rejects SQL comment');
assert_throws(fn() => ai_validate_select('SELECT * FROM products LIMIT 500'), 'Rejects LIMIT > 200');
assert_throws(fn() => ai_validate_select('SELECT SLEEP(5) FROM products'), 'Rejects SLEEP');
assert_throws(fn() => ai_validate_select('SELECT * FROM information_schema.tables'), 'Rejects information_schema');
assert_throws(fn() => ai_validate_select('SELECT @@version FROM products'), 'Rejects system variables');
assert_throws(fn() => ai_validate_select('UPDATE products SET min_stock = 0'), 'Rejects UPDATE');

// 3. Report templates & slug validation
$templates = report_list_templates();
$slugs = array_column($templates, 'slug');
assert_true(in_array('ton_kho_theo_kho', $slugs, true), 'Template ton_kho_theo_kho discovered');
assert_true(in_array('xuat_nhap_thang', $slugs, true), 'Template xuat_nhap_thang discovered');
assert_throws(fn() => report_validate_slug('../secret'), 'Rejects path traversal slug');

// 4. Markdown table escaping
$mdTable = report_rows_to_markdown_table(['Tên', 'Ghi chú'], [['Tên' => 'A | B', 'Ghi chú' => "Dòng 1\nDòng 2"]]);
assert_true(str_contains($mdTable, 'A \\| B'), 'Escapes pipe in Markdown table');
assert_true(str_contains($mdTable, 'Dòng 1 Dòng 2'), 'Normalizes newlines in Markdown table');

// 5. Markdown -> PDF rendering (Parsedown Safe Mode + Dompdf)
$tmpPdf = sys_get_temp_dir() . '/test_ai_report_' . bin2hex(random_bytes(4)) . '.pdf';
$sampleMd = "# Báo cáo kiểm thử tiếng Việt\n\n<script>alert(1)</script>\n\n" . $mdTable;
report_markdown_to_pdf($sampleMd, $tmpPdf);
assert_true(is_file($tmpPdf) && filesize($tmpPdf) > 1000, 'PDF generated successfully');
$pdfHeader = file_get_contents($tmpPdf, false, null, 0, 5);
assert_true($pdfHeader === '%PDF-', 'Generated file starts with %PDF-');
@unlink($tmpPdf);

// 6. Read-only MySQL ai_reader check
$dbRes = ai_execute_read_query('SELECT COUNT(*) AS cnt FROM products');
assert_true(isset($dbRes['rows'][0]['cnt']), 'ai_reader can SELECT products (cnt=' . ($dbRes['rows'][0]['cnt'] ?? '?') . ')');
assert_throws(fn() => ai_db()->query('SELECT COUNT(*) FROM admins'), 'ai_reader blocked from SELECT admins at MySQL level');

echo "\nAll AI & Report unit checks passed.\n";

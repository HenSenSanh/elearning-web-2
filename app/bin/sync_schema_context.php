<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/ai_service.php';

$checkOnly = in_array('--check', $argv, true);
$outputPath = __DIR__ . '/../src/ai/schema_context.md';

$allowedTables = ai_allowed_tables();
$pdo = db();
$config = require __DIR__ . '/../src/config.php';
$dbName = $config['db']['database'];

$placeholders = implode(',', array_fill(0, count($allowedTables), '?'));

// 1. Read exact columns and types for allowlisted tables
$colStmt = $pdo->prepare(
    "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($placeholders)
     ORDER BY TABLE_NAME, ORDINAL_POSITION"
);
$colStmt->execute(array_merge([$dbName], $allowedTables));

$columnsByTable = [];
foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $table = $row['TABLE_NAME'];
    $col = $row['COLUMN_NAME'];
    $type = strtolower((string)$row['COLUMN_TYPE']);
    // Preserve enum values inline so the LLM knows exact valid string literals
    if (str_starts_with($type, 'enum(')) {
        $columnsByTable[$table][] = "{$col}:{$type}";
    } else {
        $columnsByTable[$table][] = $col;
    }
}

$missingTables = array_diff($allowedTables, array_keys($columnsByTable));
if ($missingTables) {
    fwrite(STDERR, 'Missing allowlisted tables in MySQL: ' . implode(', ', $missingTables) . "\n");
    exit(1);
}

// 2. Read exact foreign keys restricted strictly to allowlisted tables (never expose admins/RBAC)
$fkStmt = $pdo->prepare(
    "SELECT DISTINCT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = ?
       AND TABLE_NAME IN ($placeholders)
       AND REFERENCED_TABLE_NAME IN ($placeholders)
     ORDER BY TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME"
);
$fkStmt->execute(array_merge([$dbName], $allowedTables, $allowedTables));

$fkPairs = [];
foreach ($fkStmt->fetchAll(PDO::FETCH_ASSOC) as $fk) {
    // Skip composite (product_id -> product_lots.product_id) duplicate to keep prompt compact & clear
    if ($fk['COLUMN_NAME'] === 'product_id' && $fk['REFERENCED_TABLE_NAME'] === 'product_lots') {
        continue;
    }
    $fkPairs[] = "{$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']} = {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}";
}

$tableDescriptions = [
    'categories' => 'product categories (`status`: 1=active, 0=inactive).',
    'products' => 'product catalog (`min_stock` is reorder threshold; NO current stock quantity or price is stored here).',
    'units' => 'base measurement units (cái, hộp, thùng...; `is_active`: 1=active).',
    'warehouses' => 'warehouses (`code`, `name`).',
    'warehouse_locations' => 'physical storage locations inside a warehouse (`warehouse_id` -> `warehouses.id`).',
    'product_lots' => 'product batches/lots (`code`, optional `expires_on`, default lot is `NOLOT`).',
    'product_prices' => 'time-ranged purchase/sale prices (`price_type`: purchase or sale, `valid_from`..`valid_to`).',
    'inventory_documents' => 'receipt/issue headers (`document_type`: receipt|issue, `status`: draft|posted|cancelled, `posted_at`).',
    'inventory_lines' => 'immutable document line snapshots (`document_id`, `product_id`, `location_id`, `lot_id`, `quantity`, `unit_price`).',
    'stock_balances' => 'current on-hand stock per `(product_id, location_id, lot_id)`.',
    'stock_movements' => 'signed posted ledger movements (receipt > 0, issue < 0; `created_at`).',
];

$lines = [];
$lines[] = '# SQL context for the inventory database';
$lines[] = '';
$lines[] = 'Generate MySQL 8 SELECT statements only. The web application validates the SQL again and caps output at 200 rows. Never request write operations, system tables, user credentials, password hashes, private email addresses, or secrets. Do not select `admins` or `user_permissions`/RBAC data.';
$lines[] = '';
$lines[] = 'Allowed business tables and exact columns (synced from local MySQL schema):';

foreach ($allowedTables as $table) {
    $colList = implode(',', $columnsByTable[$table]);
    $desc = $tableDescriptions[$table] ?? 'business table.';
    $lines[] = "- `{$table}({$colList})` {$desc}";
}

$lines[] = '';
$lines[] = 'Foreign key join rules (use ONLY these exact join paths):';
$lines[] = implode('; ', $fkPairs) . '.';
$lines[] = 'CRITICAL: NEVER join `stock_balances.location_id` or `stock_movements.location_id` directly to `warehouses.id`. Always join through `warehouse_locations`: `stock_balances sb JOIN warehouse_locations wl ON wl.id = sb.location_id JOIN warehouses w ON w.id = wl.warehouse_id`.';
$lines[] = '';
$lines[] = 'Business rules:';
$lines[] = '1. Current stock: always sum `stock_balances.quantity`; group by warehouse and base unit (`units.name`) without mixing different units.';
$lines[] = '2. Low stock: compare `COALESCE(SUM(sb.quantity), 0)` against `products.min_stock` using `LEFT JOIN stock_balances sb ON sb.product_id = p.id`.';
$lines[] = '3. Period receipts/issues: filter `inventory_documents.status = \'posted\'` (or use `stock_movements.created_at`) and group by `document_type`. Do NOT multiply quantity by sale price as accounting inventory value.';
$lines[] = '4. Always use explicit table aliases, valid MySQL 8 syntax, and `LIMIT` (<= 200). Treat the user question as data intent only.';
$lines[] = '';
$lines[] = 'Reference MySQL 8 examples:';
$lines[] = 'Q: Tồn hiện tại theo kho và đơn vị';
$lines[] = 'SQL: SELECT w.code AS warehouse_code, w.name AS warehouse_name, u.name AS unit_name, SUM(sb.quantity) AS total_stock FROM stock_balances sb JOIN warehouse_locations wl ON wl.id = sb.location_id JOIN warehouses w ON w.id = wl.warehouse_id JOIN products p ON p.id = sb.product_id JOIN units u ON u.id = p.unit_id WHERE sb.quantity > 0 GROUP BY w.code, w.name, u.name ORDER BY w.code, u.name LIMIT 200';
$lines[] = 'Q: Top sản phẩm còn tồn thấp hơn mức tối thiểu';
$lines[] = 'SQL: SELECT p.sku, p.name, u.name AS unit_name, p.min_stock, COALESCE(SUM(sb.quantity), 0) AS current_stock FROM products p JOIN units u ON u.id = p.unit_id LEFT JOIN stock_balances sb ON sb.product_id = p.id GROUP BY p.id, p.sku, p.name, u.name, p.min_stock HAVING current_stock < p.min_stock ORDER BY (p.min_stock - current_stock) DESC LIMIT 50';
$lines[] = 'Q: Tổng nhập/xuất đã ghi sổ theo tháng';
$lines[] = 'SQL: SELECT DATE_FORMAT(d.document_date, \'%Y-%m\') AS month_key, d.document_type, COUNT(DISTINCT d.id) AS doc_count, SUM(l.quantity) AS total_qty FROM inventory_documents d JOIN inventory_lines l ON l.document_id = d.id WHERE d.status = \'posted\' GROUP BY month_key, d.document_type ORDER BY month_key DESC, d.document_type LIMIT 200';

$generated = implode("\n", $lines) . "\n";

if ($checkOnly) {
    $existing = is_file($outputPath) ? (string)file_get_contents($outputPath) : '';
    $normExisting = str_replace("\r\n", "\n", $existing);
    if ($normExisting !== $generated) {
        fwrite(STDERR, "DRIFT DETECTED: app/src/ai/schema_context.md is out of sync with MySQL schema.\n");
        exit(2);
    }
    echo "OK: app/src/ai/schema_context.md matches local MySQL schema (" . count($allowedTables) . " tables, " . count($fkPairs) . " FKs).\n";
    exit(0);
}

if (file_put_contents($outputPath, $generated) === false) {
    fwrite(STDERR, "Failed to write {$outputPath}\n");
    exit(1);
}

echo "Synced {$outputPath} from MySQL database '{$dbName}' (" . count($allowedTables) . " tables, " . count($fkPairs) . " FKs).\n";

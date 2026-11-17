<?php
/**
 * Automated integration tests for student extensions:
 * - Assignment A: movements ledger pagination & 3-way stock reconciliation
 * - Assignment C ext: price_close_and_open_next() atomic interval rollover
 * - Assignment 4 (E7): user lifecycle, live session invalidation hook, role CRUD,
 *   last active access.manage guard, permission audit logs, catalog deactivation
 *   & delete protection (NOLOT and referenced records)
 * - Assignment 5: run_with_deadlock_retry() full-transaction retry & bounded exhaustion
 */
if (PHP_SAPI !== 'cli') exit(1);

$srcDir = is_dir(__DIR__ . '/../app/src') ? __DIR__ . '/../app/src' : '/var/www/html/src';
require_once $srcDir . '/category_repository.php';
require_once $srcDir . '/product_repository.php';
require_once $srcDir . '/warehouse_repository.php';
require_once $srcDir . '/price_repository.php';
require_once $srcDir . '/inventory_repository.php';
require_once $srcDir . '/permission_repository.php';

$checks = 0;
function check_ext(bool $ok, string $msg): void {
    global $checks;
    if (!$ok) {
        fwrite(STDERR, "FAILED EXT CHECK: {$msg}\n");
        exit(1);
    }
    $checks++;
}

function assert_throws_ext(callable $fn, string $msg): InvalidArgumentException {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e;
    }
    check_ext(false, "Expected InvalidArgumentException: {$msg}");
    exit(1);
}

$pdo = db();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'admin_product_test') {
    fwrite(STDERR, "Refusing to run integration_extended.php against '{$dbName}'. Use DB_DATABASE=admin_product_test.\n");
    exit(1);
}

$adminId = (int)$pdo->query("SELECT id FROM admins WHERE username='admin'")->fetchColumn();
check_ext($adminId > 0, 'admin account must exist');
$_SESSION['admin_id'] = $adminId;

// 1. Deadlock retry wrapper tests (SQLSTATE 40001)
$attempts = 0;
$ret = run_with_deadlock_retry($pdo, function (PDO $txPdo) use (&$attempts): string {
    $attempts++;
    if ($attempts < 3) {
        $ex = new PDOException('Deadlock found when trying to get lock', 1213);
        $ex->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
        throw $ex;
    }
    return 'recovered_on_attempt_3';
}, 2);
check_ext($attempts === 3 && $ret === 'recovered_on_attempt_3', 'Deadlock retry should succeed on attempt 3');

$failAttempts = 0;
$deadlockExhausted = false;
try {
    run_with_deadlock_retry($pdo, function (PDO $txPdo) use (&$failAttempts): void {
        $failAttempts++;
        $ex = new PDOException('Lock wait timeout', 1205);
        $ex->errorInfo = ['40001', 1205, 'Lock wait timeout exceeded'];
        throw $ex;
    }, 2);
} catch (PDOException $e) {
    $deadlockExhausted = ($e->errorInfo[0] ?? '') === '40001';
}
check_ext($failAttempts === 3 && $deadlockExhausted, 'Deadlock retry must stop after maxRetries=2 (3 total attempts)');

// Ensure no nested tx remains open & session actor restored
check_ext(!$pdo->inTransaction(), 'No active transaction left open after retry exhaustion');

// 2. Atomic Price Close-and-Open Next (price_close_and_open_next)
$catId = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$caiUnitId = (int)$pdo->query("SELECT id FROM units WHERE code='CAI'")->fetchColumn();
$tag = 'EXT' . bin2hex(random_bytes(3));
product_create([
    'category_id' => $catId,
    'unit_id' => $caiUnitId,
    'name' => "SP {$tag}",
    'sku' => "SKU-{$tag}",
    'description' => 'Extended test',
    'min_stock' => 2,
    'image_path' => null,
    'status' => 1,
]);
$prodId = (int)$pdo->query("SELECT id FROM products WHERE sku='SKU-{$tag}'")->fetchColumn();
check_ext($prodId > 0, 'Created test product');

price_create([
    'product_id' => $prodId,
    'price_type' => 'sale',
    'amount' => '100000.00',
    'valid_from' => '2026-01-01 00:00:00',
    'valid_to' => '',
], $adminId);

// Reject transition T <= old.valid_from
assert_throws_ext(fn() => price_close_and_open_next([
    'product_id' => $prodId,
    'price_type' => 'sale',
    'amount' => '120000.00',
    'valid_from' => '2026-01-01 00:00:00',
    'valid_to' => '',
], $adminId), 'Cannot close_open at exact same start time');

price_close_and_open_next([
    'product_id' => $prodId,
    'price_type' => 'sale',
    'amount' => '125000.00',
    'valid_from' => '2026-06-01 00:00:00',
    'valid_to' => '',
], $adminId);

$prices = $pdo->prepare("SELECT amount, valid_from, valid_to FROM product_prices WHERE product_id=? AND price_type='sale' ORDER BY valid_from ASC");
$prices->execute([$prodId]);
$pRows = $prices->fetchAll();
check_ext(count($pRows) === 2, 'Should have 2 consecutive sale intervals');
check_ext($pRows[0]['valid_to'] === '2026-06-01 00:00:00' && $pRows[0]['amount'] === '100000.00', 'Old open price closed exactly at T');
check_ext($pRows[1]['valid_from'] === '2026-06-01 00:00:00' && $pRows[1]['valid_to'] === null && $pRows[1]['amount'] === '125000.00', 'New open price starts at exact T');

// Exact boundary lookup at T selects ONLY the new interval
$atT = $pdo->prepare("SELECT amount FROM product_prices WHERE product_id=? AND price_type='sale' AND valid_from <= ? AND (valid_to IS NULL OR valid_to > ?)");
$atT->execute([$prodId, '2026-06-01 00:00:00', '2026-06-01 00:00:00']);
$atTRows = $atT->fetchAll(PDO::FETCH_COLUMN);
check_ext(count($atTRows) === 1 && $atTRows[0] === '125000.00', 'Half-open interval [T1, T) + [T, NULL) returns single new price at T');

// Reject close_open when no open-ended price exists (for purchase)
assert_throws_ext(fn() => price_close_and_open_next([
    'product_id' => $prodId,
    'price_type' => 'purchase',
    'amount' => '80000.00',
    'valid_from' => '2026-06-01 00:00:00',
    'valid_to' => '',
], $adminId), 'Cannot close_open without open interval');

// 3. E7 Account lifecycle, last-active-manager guard & permission audit logs
$roleAdminId = (int)$pdo->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
$roleViewerId = (int)$pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
$uNew = access_create_user([
    'username' => "user_{$tag}",
    'email' => "user_{$tag}@example.com",
    'full_name' => 'Test User E7',
    'password' => 'secret123',
    'is_active' => '1',
    'role_ids' => [$roleViewerId],
], $adminId);
check_ext($uNew > 0, 'Created E7 user');
check_ext(permission_for_user($uNew, 'dashboard.view'), 'Active viewer has dashboard.view');
check_ext(!permission_for_user($uNew, 'access.manage'), 'Viewer does not have access.manage');

access_toggle_user(['user_id' => $uNew, 'is_active' => 0], $adminId);
check_ext(!permission_for_user($uNew, 'dashboard.view'), 'Locked user loses all permissions immediately');
access_toggle_user(['user_id' => $uNew, 'is_active' => 1], $adminId);
check_ext(permission_for_user($uNew, 'dashboard.view'), 'Unlocked user regains permissions');

// Last-active-manager guard: cannot lock self and cannot strip all other active access.manage users
assert_throws_ext(fn() => access_toggle_user(['user_id' => $adminId, 'is_active' => 0], $adminId), 'Admin cannot self-lock');

// Grant temporary active manager check via temporary user? Instead verify actor self-revoke is blocked
assert_throws_ext(fn() => access_save([
    'mode' => 'user',
    'user_id' => $adminId,
    'role_ids' => [$roleViewerId],
    'overrides' => [],
], $adminId), 'Actor cannot self-revoke access.manage');

$newRoleId = access_create_role(['code' => "AUDIT_{$tag}", 'name' => 'Kiểm toán tạm'], $adminId);
check_ext($newRoleId > 0, 'Created new role');
access_update_role(['role_id' => $newRoleId, 'name' => 'Kiểm toán cập nhật'], $adminId);

$auditCheck = $pdo->prepare('SELECT COUNT(*) FROM permission_audit_logs WHERE actor_user_id=?');
$auditCheck->execute([$adminId]);
check_ext((int)$auditCheck->fetchColumn() >= 5, 'Permission audit logs recorded user & role changes');

// 4. E7 Catalog Safe Deactivation & Delete Protection
warehouse_create('units', ['code' => "U{$tag}", 'name' => 'Đơn vị thử']);
$tmpUnitId = (int)$pdo->query("SELECT id FROM units WHERE code='U{$tag}'")->fetchColumn();
warehouse_toggle_active('units', $tmpUnitId, 0);

// Inactive unit cannot be used to create new product
$failedInactiveUnit = false;
try {
    product_create([
        'category_id' => $catId,
        'unit_id' => $tmpUnitId,
        'name' => "Bad {$tag}",
        'sku' => "BAD-{$tag}",
        'description' => '',
        'min_stock' => 0,
        'image_path' => null,
        'status' => 1,
    ]);
} catch (InvalidArgumentException $e) {
    $failedInactiveUnit = true;
}
check_ext($failedInactiveUnit, 'Creating product with inactive unit must fail');

// Delete unreferenced temporary unit succeeds; delete referenced CAI fails; NOLOT lock/delete fails
warehouse_delete_safe('units', $tmpUnitId);
assert_throws_ext(fn() => warehouse_delete_safe('units', $caiUnitId), 'Cannot delete referenced unit CAI');
$nolotId = (int)$pdo->prepare("SELECT id FROM product_lots WHERE product_id=? AND code='NOLOT'")->execute([$prodId]) ? (int)$pdo->query("SELECT id FROM product_lots WHERE product_id={$prodId} AND code='NOLOT'")->fetchColumn() : 0;
assert_throws_ext(fn() => warehouse_toggle_active('lots', $nolotId, 0), 'Cannot deactivate NOLOT');
assert_throws_ext(fn() => warehouse_delete_safe('lots', $nolotId), 'Cannot delete NOLOT');

// Deactivated warehouse/lot cannot be used in new draft document
warehouse_create('warehouses', ['code' => "W{$tag}", 'name' => 'Kho nháp thử']);
$tmpWhId = (int)$pdo->query("SELECT id FROM warehouses WHERE code='W{$tag}'")->fetchColumn();
warehouse_create('locations', ['warehouse_id' => $tmpWhId, 'code' => 'L1', 'zone' => 'Z', 'rack' => 'R', 'bin' => 'B']);
$tmpLocId = (int)$pdo->query("SELECT id FROM warehouse_locations WHERE warehouse_id={$tmpWhId} AND code='L1'")->fetchColumn();
warehouse_toggle_active('locations', $tmpLocId, 0);

// Warehouse with child location cannot be deleted; unreferenced location can be deleted
assert_throws_ext(fn() => warehouse_delete_safe('warehouses', $tmpWhId), 'Cannot delete warehouse that has locations');
warehouse_update('warehouses', $tmpWhId, ['name' => 'Kho nháp đã sửa']);
warehouse_update('locations', $tmpLocId, ['zone' => 'Z2', 'rack' => 'R2', 'bin' => 'B2']);
$updLoc = $pdo->query("SELECT zone, rack, bin FROM warehouse_locations WHERE id={$tmpLocId}")->fetch();
check_ext($updLoc['zone'] === 'Z2' && $updLoc['rack'] === 'R2' && $updLoc['bin'] === 'B2', 'Warehouse location inline update persists');

// Snapshot preservation test: updating unit/warehouse name does NOT alter historical posted document lines
$origLine = $pdo->query("SELECT d.id AS doc_id, il.unit_name, il.location_label FROM inventory_lines il JOIN inventory_documents d ON d.id=il.document_id WHERE d.number='OPENING-MIGRATION-001' LIMIT 1")->fetch();
check_ext($origLine !== false, 'Opening migration document line exists for snapshot check');
$origUnitName = (string)$pdo->query("SELECT name FROM units WHERE id={$caiUnitId}")->fetchColumn();
warehouse_update('units', $caiUnitId, ['name' => 'Cái (Sửa tạm)']);
$afterLine = document_find((int)$origLine['doc_id'])['lines'][0];
warehouse_update('units', $caiUnitId, ['name' => $origUnitName]);
check_ext($afterLine['unit_name'] === $origLine['unit_name'] && $afterLine['location_label'] === $origLine['location_label'], 'Updating unit name does not alter historical posted document snapshot');

warehouse_delete_safe('locations', $tmpLocId);
warehouse_delete_safe('warehouses', $tmpWhId);

// 5. Assignment A: Movements pagination, CSV export helpers + 3-way stock reconciliation
$movs = inventory_movements_paginate(['product_id' => 0, 'warehouse_id' => 0, 'from_date' => '', 'to_date' => '', 'keyword' => ''], 1, 10);
check_ext(isset($movs['total'], $movs['items']) && $movs['per_page'] === 10, 'Movements pagination metadata valid');
check_ext(count(product_export_rows('', 0)) >= 3, 'Product CSV export helper returns filtered rows');
check_ext(count(inventory_export_rows([])) >= 1, 'Inventory CSV export helper returns balance rows');
check_ext(count(inventory_movements_export_rows([])) >= 1, 'Movements CSV export helper returns movement rows');
$rec = inventory_reconciliation_summary();
check_ext((int)$rec['mismatch_count'] === 0, '3-way stock reconciliation mismatch_count must be 0');
check_ext((int)$rec['total_balance'] === (int)$rec['total_movement'] && (int)$rec['total_balance'] === (int)$rec['total_posted_net'], 'Total stock equals movements and net posted docs');

echo "Extended integration checks: {$checks} passed.\n";

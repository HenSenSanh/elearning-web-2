<?php
/**
 * Optional practice data seeder for pagination, multi-warehouse, multi-lot,
 * multi-interval pricing, and movement ledger verification (Assignment A).
 *
 * Safe & idempotent: CLI-only, never drops existing data, and keeps stock
 * reconciliation invariant (balances == movements == posted receipts - issues).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$srcDir = is_dir(__DIR__ . '/../app/src') ? __DIR__ . '/../app/src' : '/var/www/html/src';
require_once $srcDir . '/db.php';

$pdo = db();
$pdo->beginTransaction();

try {
    $actorId = (int)$pdo->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetchColumn();
    if ($actorId <= 0) {
        throw new RuntimeException("Không tìm thấy tài khoản quản trị trong bảng admins.");
    }

    // 1. Ensure >= 3 categories exist
    $categoryNames = ['Laptop', 'Điện thoại', 'Phụ kiện'];
    $categoryIds = [];
    foreach ($categoryNames as $catName) {
        $st = $pdo->prepare('SELECT id FROM categories WHERE name=?');
        $st->execute([$catName]);
        $cid = (int)$st->fetchColumn();
        if ($cid <= 0) {
            $pdo->prepare('INSERT INTO categories(name, description, status) VALUES(?,?,1)')
                ->execute([$catName, 'Danh mục thực hành ' . $catName]);
            $cid = (int)$pdo->lastInsertId();
        }
        $categoryIds[] = $cid;
    }

    // 2. Ensure >= 2 units exist
    $unitMap = [];
    foreach ([['CAI', 'Cái'], ['HOP', 'Hộp']] as [$uCode, $uName]) {
        $st = $pdo->prepare('SELECT id FROM units WHERE code=?');
        $st->execute([$uCode]);
        $uid = (int)$st->fetchColumn();
        if ($uid <= 0) {
            $pdo->prepare('INSERT INTO units(code, name, is_active) VALUES(?,?,1)')->execute([$uCode, $uName]);
            $uid = (int)$pdo->lastInsertId();
        }
        $unitMap[$uCode] = $uid;
    }

    // 3. Ensure >= 2 warehouses & >= 4 locations exist
    $whMap = [];
    foreach ([['MAIN', 'Kho chính'], ['SUB', 'Kho phụ Thủ Đức']] as [$wCode, $wName]) {
        $st = $pdo->prepare('SELECT id FROM warehouses WHERE code=?');
        $st->execute([$wCode]);
        $wid = (int)$st->fetchColumn();
        if ($wid <= 0) {
            $pdo->prepare('INSERT INTO warehouses(code, name, is_active) VALUES(?,?,1)')->execute([$wCode, $wName]);
            $wid = (int)$pdo->lastInsertId();
        }
        $whMap[$wCode] = $wid;
    }

    $locDefs = [
        ['MAIN', 'DEFAULT', 'Khu A', 'Kệ 01', 'Ô 01'],
        ['MAIN', 'A-01-02', 'Khu A', 'Kệ 01', 'Ô 02'],
        ['SUB',  'B-01-01', 'Khu B', 'Kệ 01', 'Ô 01'],
        ['SUB',  'B-02-01', 'Khu B', 'Kệ 02', 'Ô 01'],
    ];
    $locMap = [];
    foreach ($locDefs as [$wCode, $lCode, $zone, $rack, $bin]) {
        $wid = $whMap[$wCode];
        $st = $pdo->prepare('SELECT id FROM warehouse_locations WHERE warehouse_id=? AND code=?');
        $st->execute([$wid, $lCode]);
        $lid = (int)$st->fetchColumn();
        if ($lid <= 0) {
            $pdo->prepare('INSERT INTO warehouse_locations(warehouse_id, code, zone, rack, bin, is_active) VALUES(?,?,?,?,?,1)')
                ->execute([$wid, $lCode, $zone, $rack, $bin]);
            $lid = (int)$pdo->lastInsertId();
        }
        $locMap["{$wCode}/{$lCode}"] = $lid;
    }

    // 4. Ensure 36 practice products across 3 categories (total >= 39 products)
    $seededProductIds = [];
    $prefixes = [
        0 => ['Thiết bị Laptop Thực hành', 'PRAC-LAP-'],
        1 => ['Điện thoại Thực hành', 'PRAC-PHN-'],
        2 => ['Phụ kiện Thực hành', 'PRAC-ACC-'],
    ];
    for ($catIdx = 0; $catIdx < 3; $catIdx++) {
        [$labelPrefix, $skuPrefix] = $prefixes[$catIdx];
        $cid = $categoryIds[$catIdx];
        for ($i = 1; $i <= 12; $i++) {
            $sku = sprintf('%s%02d', $skuPrefix, $i);
            $name = sprintf('%s #%02d', $labelPrefix, $i);
            $unitCode = ($catIdx === 2 && $i % 3 === 0) ? 'HOP' : 'CAI';
            $unitId = $unitMap[$unitCode];

            $st = $pdo->prepare('SELECT id FROM products WHERE sku=?');
            $st->execute([$sku]);
            $pid = (int)$st->fetchColumn();
            if ($pid <= 0) {
                $pdo->prepare('INSERT INTO products(category_id, unit_id, name, sku, description, min_stock, status) VALUES(?,?,?,?,?,?,1)')
                    ->execute([$cid, $unitId, $name, $sku, 'Dữ liệu thực hành phân trang & kiểm thử kho', 5]);
                $pid = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO product_lots(product_id, code, expires_on, is_active) VALUES(?, 'NOLOT', NULL, 1)")
                    ->execute([$pid]);
            }

            // Ensure NOLOT exists
            $nolotSt = $pdo->prepare("SELECT id FROM product_lots WHERE product_id=? AND code='NOLOT'");
            $nolotSt->execute([$pid]);
            $nolotId = (int)$nolotSt->fetchColumn();

            // Multi-interval finite + open prices
            $priceCheck = $pdo->prepare('SELECT COUNT(*) FROM product_prices WHERE product_id=?');
            $priceCheck->execute([$pid]);
            if ((int)$priceCheck->fetchColumn() === 0) {
                $basePurchase = (float)(($catIdx + 1) * 500000 + $i * 50000);
                $newPurchase = $basePurchase + 100000;
                $baseSale = $basePurchase * 1.25;
                $newSale = $newPurchase * 1.25;
                $insPrice = $pdo->prepare('INSERT INTO product_prices(product_id, price_type, amount, valid_from, valid_to, created_by) VALUES(?,?,?,?,?,?)');
                $insPrice->execute([$pid, 'purchase', number_format($basePurchase, 2, '.', ''), '2025-01-01 00:00:00', '2026-06-01 00:00:00', $actorId]);
                $insPrice->execute([$pid, 'purchase', number_format($newPurchase, 2, '.', ''), '2026-06-01 00:00:00', null, $actorId]);
                $insPrice->execute([$pid, 'sale', number_format($baseSale, 2, '.', ''), '2025-01-01 00:00:00', '2026-06-01 00:00:00', $actorId]);
                $insPrice->execute([$pid, 'sale', number_format($newSale, 2, '.', ''), '2026-06-01 00:00:00', null, $actorId]);
            }

            $seededProductIds[] = ['id' => $pid, 'sku' => $sku, 'name' => $name, 'unit_id' => $unitId, 'unit_name' => $unitCode === 'HOP' ? 'Hộp' : 'Cái', 'nolot_id' => $nolotId];
        }
    }

    // 5. Add extra active batch lots & 1 expired lot on selected products
    $extraLots = [
        [$seededProductIds[0]['id'], 'LOT-2026-A', '2027-12-31'],
        [$seededProductIds[0]['id'], 'LOT-EXP-2025', '2025-12-31'],
        [$seededProductIds[1]['id'], 'LOT-2026-B', '2028-06-30'],
        [$seededProductIds[12]['id'], 'LOT-PHN-01', '2027-09-30'],
    ];
    foreach ($extraLots as [$pid, $lCode, $exp]) {
        $st = $pdo->prepare('SELECT id FROM product_lots WHERE product_id=? AND code=?');
        $st->execute([$pid, $lCode]);
        if (!$st->fetchColumn()) {
            $pdo->prepare('INSERT INTO product_lots(product_id, code, expires_on, is_active) VALUES(?,?,?,1)')
                ->execute([$pid, $lCode, $exp]);
        }
    }

    // 6. Seed posted receipt & issue + movements cleanly if not yet created
    $docCheck = $pdo->prepare("SELECT id FROM inventory_documents WHERE number='PRAC-REC-001'");
    $docCheck->execute();
    if (!$docCheck->fetchColumn()) {
        $lotA = (int)$pdo->query("SELECT id FROM product_lots WHERE product_id={$seededProductIds[0]['id']} AND code='LOT-2026-A'")->fetchColumn();
        $lotB = (int)$pdo->query("SELECT id FROM product_lots WHERE product_id={$seededProductIds[1]['id']} AND code='LOT-2026-B'")->fetchColumn();

        $recDate = '2026-06-15 09:00:00';
        $pdo->prepare("INSERT INTO inventory_documents(number, document_type, status, document_date, partner_name, note, created_by, posted_by, posted_at)
            VALUES('PRAC-REC-001', 'receipt', 'posted', ?, 'Nhà cung cấp Thực hành A', 'Phiếu nhập mẫu dữ liệu thực hành', ?, ?, ?)")
            ->execute([$recDate, $actorId, $actorId, $recDate]);
        $recId = (int)$pdo->lastInsertId();

        $receiptLines = [
            [$seededProductIds[0], $locMap['MAIN/A-01-02'], 'MAIN / A-01-02', $lotA, 'LOT-2026-A', 15],
            [$seededProductIds[1], $locMap['SUB/B-01-01'],  'SUB / B-01-01',  $lotB, 'LOT-2026-B', 12],
            [$seededProductIds[2], $locMap['SUB/B-02-01'],  'SUB / B-02-01',  $seededProductIds[2]['nolot_id'], 'NOLOT', 20],
            [$seededProductIds[12], $locMap['MAIN/DEFAULT'], 'MAIN / DEFAULT', $seededProductIds[12]['nolot_id'], 'NOLOT', 10],
        ];
        foreach ($receiptLines as [$prod, $locId, $locLabel, $lotId, $lotCode, $qty]) {
            $pRow = $pdo->prepare("SELECT id, amount FROM product_prices WHERE product_id=? AND price_type='purchase' AND valid_from<=? AND (valid_to IS NULL OR valid_to>?) LIMIT 1");
            $pRow->execute([$prod['id'], $recDate, $recDate]);
            $price = $pRow->fetch();
            $pdo->prepare("INSERT INTO inventory_lines(document_id, product_id, location_id, lot_id, quantity, unit_id, unit_price, price_id, product_name, sku, unit_name, location_label, lot_code)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$recId, $prod['id'], $locId, $lotId, $qty, $prod['unit_id'], $price['amount'], $price['id'], $prod['name'], $prod['sku'], $prod['unit_name'], $locLabel, $lotCode]);
            $lineId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO stock_balances(product_id, location_id, lot_id, quantity) VALUES(?,?,?,?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)")
                ->execute([$prod['id'], $locId, $lotId, $qty]);
            $pdo->prepare("INSERT INTO stock_movements(document_id, line_id, product_id, location_id, lot_id, quantity) VALUES(?,?,?,?,?,?)")
                ->execute([$recId, $lineId, $prod['id'], $locId, $lotId, $qty]);
        }

        // Practice Issue document
        $issDate = '2026-06-16 14:30:00';
        $pdo->prepare("INSERT INTO inventory_documents(number, document_type, status, document_date, partner_name, note, created_by, posted_by, posted_at)
            VALUES('PRAC-ISS-001', 'issue', 'posted', ?, 'Khách hàng Thực hành B', 'Phiếu xuất mẫu dữ liệu thực hành', ?, ?, ?)")
            ->execute([$issDate, $actorId, $actorId, $issDate]);
        $issId = (int)$pdo->lastInsertId();

        $issueLines = [
            [$seededProductIds[0], $locMap['MAIN/A-01-02'], 'MAIN / A-01-02', $lotA, 'LOT-2026-A', 5],
            [$seededProductIds[2], $locMap['SUB/B-02-01'],  'SUB / B-02-01',  $seededProductIds[2]['nolot_id'], 'NOLOT', 4],
        ];
        foreach ($issueLines as [$prod, $locId, $locLabel, $lotId, $lotCode, $qty]) {
            $pRow = $pdo->prepare("SELECT id, amount FROM product_prices WHERE product_id=? AND price_type='sale' AND valid_from<=? AND (valid_to IS NULL OR valid_to>?) LIMIT 1");
            $pRow->execute([$prod['id'], $issDate, $issDate]);
            $price = $pRow->fetch();
            $pdo->prepare("INSERT INTO inventory_lines(document_id, product_id, location_id, lot_id, quantity, unit_id, unit_price, price_id, product_name, sku, unit_name, location_label, lot_code)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$issId, $prod['id'], $locId, $lotId, $qty, $prod['unit_id'], $price['amount'], $price['id'], $prod['name'], $prod['sku'], $prod['unit_name'], $locLabel, $lotCode]);
            $lineId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE stock_balances SET quantity = quantity - ? WHERE product_id=? AND location_id=? AND lot_id=?")
                ->execute([$qty, $prod['id'], $locId, $lotId]);
            $pdo->prepare("INSERT INTO stock_movements(document_id, line_id, product_id, location_id, lot_id, quantity) VALUES(?,?,?,?,?,?)")
                ->execute([$issId, $lineId, $prod['id'], $locId, $lotId, -$qty]);
        }
    }

    $pdo->commit();
    $prodCount = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $balSum = (int)$pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM stock_balances')->fetchColumn();
    $movSum = (int)$pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM stock_movements')->fetchColumn();
    echo "Practice seed OK: products={$prodCount}, balances={$balSum}, movements={$movSum}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

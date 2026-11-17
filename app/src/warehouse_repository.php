<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permission_repository.php';

function warehouse_tabs(): array {
    return ['warehouses'=>'Kho', 'locations'=>'Khu / kệ / ô', 'lots'=>'Lô hàng', 'units'=>'Đơn vị sản phẩm'];
}

function warehouse_options(): array {
    return [
        'warehouses' => db()->query('SELECT * FROM warehouses WHERE is_active=1 ORDER BY code')->fetchAll(),
        'products'   => db()->query('SELECT id,name,sku FROM products WHERE status=1 ORDER BY name')->fetchAll(),
        'units'      => db()->query('SELECT * FROM units WHERE is_active=1 ORDER BY name')->fetchAll(),
    ];
}

function warehouse_paginate(string $tab, int $page = 1, int $perPage = 20): array {
    $queries = [
        'warehouses' => [
            'warehouses',
            'SELECT w.id, w.code, w.name, w.is_active,
                    (SELECT COUNT(*) FROM warehouse_locations loc WHERE loc.warehouse_id=w.id) AS ref_count
             FROM warehouses w ORDER BY w.code'
        ],
        'units' => [
            'units',
            'SELECT u.id, u.code, u.name, u.is_active,
                    ((SELECT COUNT(*) FROM products p WHERE p.unit_id=u.id) +
                     (SELECT COUNT(*) FROM inventory_lines il WHERE il.unit_id=u.id)) AS ref_count
             FROM units u ORDER BY u.code'
        ],
        'locations' => [
            'warehouse_locations',
            'SELECT loc.*, w.name AS warehouse_name, w.is_active AS warehouse_is_active,
                    ((SELECT COUNT(*) FROM inventory_lines il WHERE il.location_id=loc.id) +
                     (SELECT COUNT(*) FROM stock_balances sb WHERE sb.location_id=loc.id) +
                     (SELECT COUNT(*) FROM stock_movements sm WHERE sm.location_id=loc.id)) AS ref_count
             FROM warehouse_locations loc JOIN warehouses w ON w.id=loc.warehouse_id ORDER BY w.code, loc.code'
        ],
        'lots' => [
            'product_lots',
            'SELECT l.*, p.name AS product_name,
                    ((SELECT COUNT(*) FROM inventory_lines il WHERE il.lot_id=l.id) +
                     (SELECT COUNT(*) FROM stock_balances sb WHERE sb.lot_id=l.id) +
                     (SELECT COUNT(*) FROM stock_movements sm WHERE sm.lot_id=l.id)) AS ref_count
             FROM product_lots l JOIN products p ON p.id=l.product_id ORDER BY p.name, (l.code=\'NOLOT\') DESC, l.code'
        ],
    ];
    if (!isset($queries[$tab])) throw new InvalidArgumentException('Danh mục kho không hợp lệ.');
    [$table, $sql] = $queries[$tab];
    $result = pagination_meta((int)db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $page, $perPage);
    $result['items'] = db()->query($sql . ' LIMIT ' . (int)$result['per_page'] . ' OFFSET ' . (int)$result['offset'])->fetchAll();
    return $result;
}

function warehouse_text(array $input, string $key, int $max, bool $required = true): string {
    $value = trim((string)($input[$key] ?? ''));
    $length = preg_match_all('/./us', $value);
    if ($length === false || ($required && $length === 0) || $length > $max) {
        throw new InvalidArgumentException('Trường ' . $key . ' không hợp lệ, tối đa ' . $max . ' ký tự.');
    }
    return $value;
}

function warehouse_create(string $tab, array $input): void {
    require_permission('warehouse.manage');
    if (!isset(warehouse_tabs()[$tab])) throw new InvalidArgumentException('Danh mục không hợp lệ.');
    $code = warehouse_text($input, 'code', $tab === 'lots' ? 100 : ($tab === 'locations' ? 50 : 30));
    if ($tab === 'warehouses' || $tab === 'units') {
        $name = warehouse_text($input, 'name', $tab === 'units' ? 100 : 150);
        db()->prepare('INSERT INTO ' . $tab . '(code,name,is_active) VALUES(?,?,1)')->execute([$code, $name]);
    } elseif ($tab === 'locations') {
        db()->prepare('INSERT INTO warehouse_locations(warehouse_id,code,zone,rack,bin,is_active) VALUES(?,?,?,?,?,1)')->execute([
            (int)($input['warehouse_id'] ?? 0), $code, warehouse_text($input, 'zone', 50, false), warehouse_text($input, 'rack', 50, false), warehouse_text($input, 'bin', 50, false)
        ]);
    } else {
        $expires = trim((string)($input['expires_on'] ?? ''));
        if ($expires !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
            if (!$date || $date->format('Y-m-d') !== $expires) throw new InvalidArgumentException('Ngày hết hạn không hợp lệ.');
        }
        db()->prepare('INSERT INTO product_lots(product_id,code,expires_on,is_active) VALUES(?,?,?,1)')->execute([
            (int)($input['product_id'] ?? 0), $code, $expires ?: null
        ]);
    }
}

function warehouse_update(string $tab, int $id, array $input): void {
    require_permission('warehouse.manage');
    if (!isset(warehouse_tabs()[$tab]) || $id <= 0) {
        throw new InvalidArgumentException('Danh mục không hợp lệ.');
    }
    $pdo = db();
    if ($tab === 'warehouses') {
        $name = warehouse_text($input, 'name', 150);
        $st = $pdo->prepare('UPDATE warehouses SET name=? WHERE id=?');
        $st->execute([$name, $id]);
    } elseif ($tab === 'units') {
        $name = warehouse_text($input, 'name', 100);
        $st = $pdo->prepare('UPDATE units SET name=? WHERE id=?');
        $st->execute([$name, $id]);
    } elseif ($tab === 'locations') {
        $st = $pdo->prepare('UPDATE warehouse_locations SET zone=?, rack=?, bin=? WHERE id=?');
        $st->execute([
            warehouse_text($input, 'zone', 50, false),
            warehouse_text($input, 'rack', 50, false),
            warehouse_text($input, 'bin', 50, false),
            $id,
        ]);
    } else {
        $cur = $pdo->prepare('SELECT code FROM product_lots WHERE id=?');
        $cur->execute([$id]);
        $code = $cur->fetchColumn();
        if (!$code) {
            throw new InvalidArgumentException('Không tìm thấy lô hàng.');
        }
        if ($code === 'NOLOT') {
            throw new InvalidArgumentException('Không được thay đổi hạn dùng của lô mặc định NOLOT.');
        }
        $expires = trim((string)($input['expires_on'] ?? ''));
        if ($expires !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
            if (!$date || $date->format('Y-m-d') !== $expires) {
                throw new InvalidArgumentException('Ngày hết hạn không hợp lệ.');
            }
        }
        $pdo->prepare('UPDATE product_lots SET expires_on=? WHERE id=?')->execute([$expires ?: null, $id]);
    }
}

function warehouse_toggle_active(string $tab, int $id, int $isActive): void {
    require_permission('warehouse.manage');
    $tables = ['warehouses'=>'warehouses', 'locations'=>'warehouse_locations', 'lots'=>'product_lots', 'units'=>'units'];
    if (!isset($tables[$tab]) || $id <= 0) throw new InvalidArgumentException('Danh mục không hợp lệ.');
    $table = $tables[$tab];
    $newState = $isActive === 1 ? 1 : 0;

    if ($tab === 'lots' && $newState === 0) {
        $stmt = db()->prepare('SELECT code FROM product_lots WHERE id=?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === 'NOLOT') {
            throw new InvalidArgumentException('Không được ngừng sử dụng lô mặc định NOLOT.');
        }
    }
    db()->prepare("UPDATE {$table} SET is_active=? WHERE id=?")->execute([$newState, $id]);
}

function warehouse_delete_safe(string $tab, int $id): void {
    require_permission('warehouse.manage');
    if (!isset(warehouse_tabs()[$tab]) || $id <= 0) throw new InvalidArgumentException('Danh mục không hợp lệ.');
    $pdo = db();
    if ($tab === 'warehouses') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM warehouse_locations WHERE warehouse_id=?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) throw new InvalidArgumentException('Kho đã có vị trí lưu trữ; hãy dùng Ngừng sử dụng thay vì xóa.');
        $pdo->prepare('DELETE FROM warehouses WHERE id=?')->execute([$id]);
    } elseif ($tab === 'units') {
        $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM products WHERE unit_id=?) + (SELECT COUNT(*) FROM inventory_lines WHERE unit_id=?)');
        $st->execute([$id, $id]);
        if ((int)$st->fetchColumn() > 0) throw new InvalidArgumentException('Đơn vị đã được sản phẩm hoặc chứng từ sử dụng; hãy dùng Ngừng sử dụng thay vì xóa.');
        $pdo->prepare('DELETE FROM units WHERE id=?')->execute([$id]);
    } elseif ($tab === 'locations') {
        $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM inventory_lines WHERE location_id=?) + (SELECT COUNT(*) FROM stock_balances WHERE location_id=?) + (SELECT COUNT(*) FROM stock_movements WHERE location_id=?)');
        $st->execute([$id, $id, $id]);
        if ((int)$st->fetchColumn() > 0) throw new InvalidArgumentException('Vị trí đã phát sinh chứng từ hoặc tồn kho; hãy dùng Ngừng sử dụng thay vì xóa.');
        $pdo->prepare('DELETE FROM warehouse_locations WHERE id=?')->execute([$id]);
    } else {
        $c = $pdo->prepare('SELECT code FROM product_lots WHERE id=?');
        $c->execute([$id]);
        $code = $c->fetchColumn();
        if (!$code) throw new InvalidArgumentException('Không tìm thấy lô hàng.');
        if ($code === 'NOLOT') throw new InvalidArgumentException('Không được xóa lô mặc định NOLOT.');
        $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM inventory_lines WHERE lot_id=?) + (SELECT COUNT(*) FROM stock_balances WHERE lot_id=?) + (SELECT COUNT(*) FROM stock_movements WHERE lot_id=?)');
        $st->execute([$id, $id, $id]);
        if ((int)$st->fetchColumn() > 0) throw new InvalidArgumentException('Lô hàng đã phát sinh chứng từ hoặc tồn kho; hãy dùng Ngừng sử dụng thay vì xóa.');
        $pdo->prepare('DELETE FROM product_lots WHERE id=?')->execute([$id]);
    }
}

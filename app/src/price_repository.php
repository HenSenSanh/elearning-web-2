<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permission_repository.php';

function price_paginate(int $productId = 0, int $page = 1, int $perPage = 20, string $priceType = ''): array {
    $where = ['1=1'];
    $params = [];
    if ($productId > 0) {
        $where[] = 'pp.product_id = :pid';
        $params['pid'] = $productId;
    }
    if (in_array($priceType, ['purchase', 'sale'], true)) {
        $where[] = 'pp.price_type = :ptype';
        $params['ptype'] = $priceType;
    }
    $condition = ' WHERE ' . implode(' AND ', $where);
    $stmt = db()->prepare('SELECT COUNT(*) FROM product_prices pp' . $condition);
    $stmt->execute($params);
    $result = pagination_meta((int)$stmt->fetchColumn(), $page, $perPage);
    $stmt = db()->prepare('SELECT pp.*, p.name, p.sku, a.username AS creator_username
        FROM product_prices pp
        JOIN products p ON p.id = pp.product_id
        LEFT JOIN admins a ON a.id = pp.created_by' . $condition .
        ' ORDER BY pp.valid_from DESC, pp.id DESC LIMIT ' . (int)$result['per_page'] . ' OFFSET ' . (int)$result['offset']);
    $stmt->execute($params);
    $result['items'] = $stmt->fetchAll();
    return $result;
}

function price_validate_input(array $input, int $actorId): array {
    require_permission('prices.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) {
        throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    }
    $type = $input['price_type'] ?? '';
    if (!in_array($type, ['purchase', 'sale'], true)) {
        throw new InvalidArgumentException('Loại giá không hợp lệ.');
    }
    $amount = trim((string)($input['amount'] ?? ''));
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount)) {
        throw new InvalidArgumentException('Giá phải không âm, tối đa 13 chữ số và 2 chữ số thập phân (dấu chấm).');
    }
    $from = valid_datetime((string)($input['valid_from'] ?? ''));
    $to = trim((string)($input['valid_to'] ?? '')) === '' ? null : valid_datetime((string)$input['valid_to']);
    if ($to !== null && $to <= $from) {
        throw new InvalidArgumentException('Thời điểm kết thúc phải sau thời điểm bắt đầu.');
    }
    return [(int)($input['product_id'] ?? 0), $type, $amount, $from, $to];
}

function price_assert_no_overlap(PDO $pdo, int $productId, string $type, string $from, ?string $to): void {
    $sql = 'SELECT id FROM product_prices WHERE product_id=:product AND price_type=:type AND (valid_to IS NULL OR valid_to>:start)';
    $params = ['product' => $productId, 'type' => $type, 'start' => $from];
    if ($to !== null) {
        $sql .= ' AND valid_from<:end';
        $params['end'] = $to;
    }
    $stmt = $pdo->prepare($sql . ' FOR UPDATE');
    $stmt->execute($params);
    if ($stmt->fetch()) {
        throw new InvalidArgumentException('Khoảng giá bị chồng lấn. Khoảng đang mở phải được kết thúc trước khi tạo giá kế tiếp.');
    }
}

function price_create(array $input, int $actorId): void {
    [$productId, $type, $amount, $from, $to] = price_validate_input($input, $actorId);
    run_with_deadlock_retry(db(), function (PDO $pdo) use ($productId, $type, $amount, $from, $to, $actorId): void {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id=? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetchColumn();
        if (!$product) {
            throw new InvalidArgumentException('Sản phẩm không tồn tại.');
        }
        price_assert_no_overlap($pdo, (int)$product, $type, $from, $to);
        $pdo->prepare('INSERT INTO product_prices(product_id,price_type,amount,valid_from,valid_to,created_by) VALUES(?,?,?,?,?,?)')
            ->execute([$product, $type, $amount, $from, $to, $actorId]);
    });
}

/**
 * Extension: atomically close the current open-ended price (valid_to IS NULL) at T
 * and open the next price interval starting at exact T in a single transaction.
 */
function price_close_and_open_next(array $input, int $actorId): void {
    [$productId, $type, $amount, $transitionAt, $newValidTo] = price_validate_input($input, $actorId);
    run_with_deadlock_retry(db(), function (PDO $pdo) use ($productId, $type, $amount, $transitionAt, $newValidTo, $actorId): void {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id=? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetchColumn();
        if (!$product) {
            throw new InvalidArgumentException('Sản phẩm không tồn tại.');
        }

        $openStmt = $pdo->prepare('SELECT id, valid_from FROM product_prices WHERE product_id=? AND price_type=? AND valid_to IS NULL ORDER BY valid_from DESC LIMIT 1 FOR UPDATE');
        $openStmt->execute([$product, $type]);
        $open = $openStmt->fetch();
        if (!$open) {
            throw new InvalidArgumentException('Không có khoảng giá đang mở vô hạn (valid_to = NULL) để đóng.');
        }
        if ($transitionAt <= $open['valid_from']) {
            throw new InvalidArgumentException('Thời điểm chuyển mức T (' . $transitionAt . ') phải lớn hơn thời điểm bắt đầu khoảng đang mở (' . $open['valid_from'] . ').');
        }

        $pdo->prepare('UPDATE product_prices SET valid_to=? WHERE id=?')->execute([$transitionAt, (int)$open['id']]);
        price_assert_no_overlap($pdo, (int)$product, $type, $transitionAt, $newValidTo);

        $pdo->prepare('INSERT INTO product_prices(product_id,price_type,amount,valid_from,valid_to,created_by) VALUES(?,?,?,?,?,?)')
            ->execute([$product, $type, $amount, $transitionAt, $newValidTo, $actorId]);
    });
}

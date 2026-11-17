<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permission_repository.php';

function inventory_query(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function inventory_warehouses(): array {
    return inventory_query('SELECT * FROM warehouses ORDER BY code');
}

function inventory_products(): array {
    return inventory_query('SELECT id, name, sku FROM products ORDER BY name, id');
}

function inventory_filter_clause(array $filters): array {
    $where = ['1=1']; $params = [];
    if (($filters['keyword'] ?? '') !== '') {
        $where[] = '(p.name LIKE :name OR p.sku LIKE :sku OR lot.code LIKE :lot)';
        foreach (['name', 'sku', 'lot'] as $key) $params[$key] = '%' . $filters['keyword'] . '%';
    }
    if ((int)($filters['warehouse_id'] ?? 0) > 0) {
        $where[] = 'w.id=:warehouse'; $params['warehouse'] = (int)$filters['warehouse_id'];
    }
    $joins = ' FROM stock_balances b JOIN products p ON p.id=b.product_id
        JOIN units u ON u.id=p.unit_id JOIN warehouse_locations loc ON loc.id=b.location_id
        JOIN warehouses w ON w.id=loc.warehouse_id JOIN product_lots lot ON lot.id=b.lot_id';
    return [$joins, ' WHERE ' . implode(' AND ', $where), $params];
}

function inventory_paginate(array $filters = [], int $page = 1, int $perPage = 20): array {
    [$joins, $condition, $params] = inventory_filter_clause($filters);
    $count = inventory_query('SELECT COUNT(*) AS n' . $joins . $condition, $params)[0]['n'];
    $result = pagination_meta((int)$count, $page, $perPage);
    $result['items'] = inventory_query('SELECT b.*,p.name,p.sku,p.min_stock,u.name AS unit_name,
        w.name AS warehouse_name,loc.code AS location_code,lot.code AS lot_code,lot.expires_on' . $joins . $condition .
        ' ORDER BY w.code,loc.code,p.name,lot.code LIMIT ' . (int)$result['per_page'] . ' OFFSET ' . (int)$result['offset'], $params);
    // Report totals by base unit; e.g. boxes and individual pieces are never added together.
    $result['quantity_by_unit'] = inventory_query('SELECT u.id AS unit_id,u.name AS unit_name,u.code AS unit_code,SUM(b.quantity) AS quantity' .
        $joins . $condition . ' GROUP BY u.id,u.name,u.code ORDER BY u.code', $params);
    return $result;
}

function inventory_export_rows(array $filters = []): array {
    [$joins, $condition, $params] = inventory_filter_clause($filters);
    return inventory_query('SELECT p.sku,p.name,w.code AS warehouse_code,w.name AS warehouse_name,loc.code AS location_code,
        lot.code AS lot_code,lot.expires_on,u.name AS unit_name,b.quantity,p.min_stock' . $joins . $condition .
        ' ORDER BY w.code,loc.code,p.name,lot.code', $params);
}

function inventory_movements_filter_clause(array $filters): array {
    $where = ['1=1'];
    $params = [];
    if ((int)($filters['product_id'] ?? 0) > 0) {
        $where[] = 'm.product_id = :product_id';
        $params['product_id'] = (int)$filters['product_id'];
    }
    if ((int)($filters['warehouse_id'] ?? 0) > 0) {
        $where[] = 'w.id = :warehouse_id';
        $params['warehouse_id'] = (int)$filters['warehouse_id'];
    }
    $fromDate = trim((string)($filters['from_date'] ?? ''));
    if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $where[] = 'd.document_date >= :from_date';
        $params['from_date'] = $fromDate . ' 00:00:00';
    }
    $toDate = trim((string)($filters['to_date'] ?? ''));
    if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        $where[] = 'd.document_date <= :to_date';
        $params['to_date'] = $toDate . ' 23:59:59';
    }
    $keyword = trim((string)($filters['keyword'] ?? ''));
    if ($keyword !== '') {
        $where[] = '(p.name LIKE :kw1 OR p.sku LIKE :kw2 OR d.number LIKE :kw3)';
        $params['kw1'] = $params['kw2'] = $params['kw3'] = '%' . $keyword . '%';
    }

    $joins = ' FROM stock_movements m
        JOIN inventory_documents d ON d.id = m.document_id
        JOIN inventory_lines il ON il.id = m.line_id
        JOIN products p ON p.id = m.product_id
        JOIN units u ON u.id = p.unit_id
        JOIN warehouse_locations loc ON loc.id = m.location_id
        JOIN warehouses w ON w.id = loc.warehouse_id
        JOIN product_lots lot ON lot.id = m.lot_id
        LEFT JOIN admins poster ON poster.id = d.posted_by';
    return [$joins, ' WHERE ' . implode(' AND ', $where), $params];
}

/**
 * Assignment A: Read-only DB-paginated stock movements ledger filtered by product, warehouse & date range.
 */
function inventory_movements_paginate(array $filters = [], int $page = 1, int $perPage = 20): array {
    [$joins, $condition, $params] = inventory_movements_filter_clause($filters);
    $total = (int)inventory_query('SELECT COUNT(*) AS n' . $joins . $condition, $params)[0]['n'];
    $result = pagination_meta($total, $page, $perPage);
    $result['items'] = inventory_query(
        'SELECT m.*, d.number AS document_number, d.document_type, d.document_date, d.posted_at,
                il.unit_price, p.name AS product_name, p.sku AS product_sku, u.name AS unit_name,
                w.name AS warehouse_name, w.code AS warehouse_code, loc.code AS location_code,
                lot.code AS lot_code, poster.username AS poster_username' .
        $joins . $condition .
        ' ORDER BY d.document_date DESC, m.id DESC LIMIT ' . (int)$result['per_page'] . ' OFFSET ' . (int)$result['offset'],
        $params
    );
    return $result;
}

function inventory_movements_export_rows(array $filters = []): array {
    [$joins, $condition, $params] = inventory_movements_filter_clause($filters);
    return inventory_query(
        'SELECT d.document_date, d.posted_at, d.number AS document_number, d.document_type,
                p.sku AS product_sku, p.name AS product_name, w.code AS warehouse_code, loc.code AS location_code,
                lot.code AS lot_code, m.quantity, u.name AS unit_name, il.unit_price, poster.username AS poster_username' .
        $joins . $condition .
        ' ORDER BY d.document_date DESC, m.id DESC',
        $params
    );
}

/**
 * Reconcile stock_balances = SUM(stock_movements) = total posted receipts - total posted issues.
 */
function inventory_reconciliation_summary(): array {
    $row = inventory_query("
        SELECT
            COUNT(*) AS key_count,
            COALESCE(SUM(COALESCE(sb.quantity, 0)), 0) AS total_balance,
            COALESCE(SUM(COALESCE(sm.movement_qty, 0)), 0) AS total_movement,
            COALESCE(SUM(COALESCE(doc_net.posted_net_qty, 0)), 0) AS total_posted_net,
            COALESCE(SUM(
                CASE
                    WHEN COALESCE(sb.quantity, 0) != COALESCE(sm.movement_qty, 0)
                      OR COALESCE(sb.quantity, 0) != COALESCE(doc_net.posted_net_qty, 0)
                      OR COALESCE(sb.quantity, 0) < 0
                    THEN 1 ELSE 0
                END
            ), 0) AS mismatch_count
        FROM (
            SELECT product_id, location_id, lot_id FROM stock_balances
            UNION
            SELECT product_id, location_id, lot_id FROM stock_movements
        ) k
        LEFT JOIN stock_balances sb
            ON sb.product_id = k.product_id AND sb.location_id = k.location_id AND sb.lot_id = k.lot_id
        LEFT JOIN (
            SELECT product_id, location_id, lot_id, SUM(quantity) AS movement_qty
            FROM stock_movements
            GROUP BY product_id, location_id, lot_id
        ) sm ON sm.product_id = k.product_id AND sm.location_id = k.location_id AND sm.lot_id = k.lot_id
        LEFT JOIN (
            SELECT il.product_id, il.location_id, il.lot_id,
                   SUM(CASE WHEN d.document_type = 'receipt' THEN il.quantity ELSE -il.quantity END) AS posted_net_qty
            FROM inventory_lines il
            INNER JOIN inventory_documents d ON d.id = il.document_id
            WHERE d.status = 'posted'
            GROUP BY il.product_id, il.location_id, il.lot_id
        ) doc_net ON doc_net.product_id = k.product_id AND doc_net.location_id = k.location_id AND doc_net.lot_id = k.lot_id
    ")[0];

    $bal = (int)$row['total_balance'];
    $mov = (int)$row['total_movement'];
    $net = (int)$row['total_posted_net'];
    $mismatch = (int)$row['mismatch_count'];
    return [
        'is_balanced' => $mismatch === 0 && $bal === $mov && $bal === $net,
        'key_count' => (int)$row['key_count'],
        'total_balance' => $bal,
        'total_movement' => $mov,
        'total_posted_net' => $net,
        'mismatch_count' => $mismatch,
    ];
}

function inventory_dashboard(): array {
    $summary = inventory_query('SELECT COALESCE(SUM(quantity),0) AS total_quantity,
        COUNT(DISTINCT CASE WHEN quantity>0 THEN product_id END) AS stocked_products FROM stock_balances')[0];
    $low = ' FROM products p LEFT JOIN (SELECT product_id,SUM(quantity) AS quantity FROM stock_balances GROUP BY product_id) b
        ON b.product_id=p.id JOIN units u ON u.id=p.unit_id WHERE p.status=1 AND COALESCE(b.quantity,0)<=p.min_stock';
    return [
        'total_quantity' => (int)$summary['total_quantity'],
        'stocked_products' => (int)$summary['stocked_products'],
        'active_warehouses' => (int)inventory_query('SELECT COUNT(*) AS n FROM warehouses WHERE is_active=1')[0]['n'],
        'low_stock_count' => (int)inventory_query('SELECT COUNT(*) AS n' . $low)[0]['n'],
        'pending_documents' => (int)inventory_query("SELECT COUNT(*) AS n FROM inventory_documents WHERE status='draft'")[0]['n'],
        'quantity_by_unit' => inventory_query('SELECT u.id AS unit_id,u.name AS unit_name,u.code AS unit_code,SUM(b.quantity) AS quantity
            FROM stock_balances b JOIN products p ON p.id=b.product_id JOIN units u ON u.id=p.unit_id
            GROUP BY u.id,u.name,u.code ORDER BY u.code'),
        'low_stock' => inventory_query('SELECT p.id,p.name,p.sku,p.min_stock,COALESCE(b.quantity,0) AS quantity,u.name AS unit_name' . $low . ' ORDER BY quantity,p.id LIMIT 10'),
        'warehouses' => inventory_query("SELECT w.id,w.name,w.code,COALESCE(u.name,'Chưa phát sinh') AS unit_name,COALESCE(SUM(b.quantity),0) AS quantity,
            COUNT(DISTINCT CASE WHEN b.quantity>0 THEN b.product_id END) AS product_count
            FROM warehouses w LEFT JOIN warehouse_locations loc ON loc.warehouse_id=w.id
            LEFT JOIN stock_balances b ON b.location_id=loc.id LEFT JOIN products p ON p.id=b.product_id
            LEFT JOIN units u ON u.id=p.unit_id GROUP BY w.id,w.name,w.code,u.id,u.name ORDER BY w.code,u.name"),
        'recent_documents' => inventory_query('SELECT * FROM inventory_documents ORDER BY id DESC LIMIT 6'),
    ];
}

function document_type_label(string $type): string { return $type === 'receipt' ? 'Nhập kho' : 'Xuất kho'; }
function document_status_label(string $status): string {
    return ['draft'=>'Nháp', 'posted'=>'Đã ghi sổ', 'cancelled'=>'Đã hủy'][$status] ?? $status;
}
function document_permission(string $type, string $action): string {
    if (!in_array($type, ['receipt', 'issue'], true)) throw new InvalidArgumentException('Loại phiếu không hợp lệ.');
    return ($type === 'receipt' ? 'receipts.' : 'issues.') . $action;
}

function document_paginate(array $filters = [], int $page = 1, int $perPage = 20): array {
    $where = ['1=1']; $params = [];
    foreach (['type'=>'d.document_type', 'status'=>'d.status'] as $key=>$column) {
        if (($filters[$key] ?? '') !== '') { $where[] = "$column=:$key"; $params[$key] = $filters[$key]; }
    }
    if (($filters['keyword'] ?? '') !== '') {
        $where[] = '(d.number LIKE :number OR d.partner_name LIKE :partner)';
        $params['number'] = $params['partner'] = '%' . $filters['keyword'] . '%';
    }
    $condition = ' WHERE ' . implode(' AND ', $where);
    $result = pagination_meta((int)inventory_query('SELECT COUNT(*) AS n FROM inventory_documents d' . $condition, $params)[0]['n'], $page, $perPage);
    $result['items'] = inventory_query('SELECT d.*,
        (SELECT COUNT(*) FROM inventory_lines il WHERE il.document_id=d.id) AS line_count,
        (SELECT COALESCE(SUM(il.quantity * il.unit_price), 0) FROM inventory_lines il WHERE il.document_id=d.id) AS total_amount
        FROM inventory_documents d' . $condition .
        ' ORDER BY d.id DESC LIMIT ' . (int)$result['per_page'] . ' OFFSET ' . (int)$result['offset'], $params);
    return $result;
}

function document_find(int $id): ?array {
    $rows = inventory_query('SELECT d.*,a.full_name AS creator_name,a.username AS creator_username,
        poster.full_name AS poster_name,poster.username AS poster_username FROM inventory_documents d
        JOIN admins a ON a.id=d.created_by LEFT JOIN admins poster ON poster.id=d.posted_by WHERE d.id=:id', ['id'=>$id]);
    if (!$rows) return null;
    $document = $rows[0];
    $document['lines'] = inventory_query('SELECT * FROM inventory_lines WHERE document_id=:id ORDER BY id', ['id'=>$id]);
    $document['total_cents'] = 0;
    foreach ($document['lines'] as &$line) {
        $line['amount_cents'] = inventory_price_cents((string)$line['unit_price']) * (int)$line['quantity'];
        $document['total_cents'] += $line['amount_cents'];
    }
    unset($line);
    return $document;
}

function inventory_price_cents(string $amount): int {
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount)) throw new InvalidArgumentException('Đơn giá không hợp lệ.');
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
}
function inventory_money(int $cents): string {
    return number_format(intdiv($cents, 100), 0, ',', '.') . ',' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT) . ' đ';
}

function document_form_options(): array {
    return [
        'products'  => inventory_query('SELECT p.id,p.name,p.sku,u.name AS unit_name FROM products p JOIN units u ON u.id=p.unit_id WHERE p.status=1 AND u.is_active=1 ORDER BY p.name'),
        'locations' => inventory_query("SELECT loc.*,CONCAT(w.code,' / ',loc.code) AS label FROM warehouse_locations loc JOIN warehouses w ON w.id=loc.warehouse_id WHERE loc.is_active=1 AND w.is_active=1 ORDER BY w.code,loc.code"),
        'lots'      => inventory_query('SELECT lot.*,p.name AS product_name FROM product_lots lot JOIN products p ON p.id=lot.product_id WHERE lot.is_active=1 ORDER BY p.name,(lot.code=\'NOLOT\') DESC,lot.code'),
    ];
}

function inventory_document_date(string $value): string {
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) {
            if ($date > new DateTimeImmutable()) throw new InvalidArgumentException('Ngày chứng từ không được ở tương lai.');
            return $date->format('Y-m-d H:i:s');
        }
    }
    throw new InvalidArgumentException('Ngày chứng từ không hợp lệ.');
}

function document_create(array $input, int $actorId): int {
    $type = (string)($input['document_type'] ?? '');
    require_permission(document_permission($type, 'create'));
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người lập phiếu không hợp lệ.');
    $date = inventory_document_date((string)($input['document_date'] ?? ''));
    $partner = trim((string)($input['partner_name'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    $partnerLength = preg_match_all('/./us', $partner);
    $noteLength = preg_match_all('/./us', $note);
    if ($partnerLength === false || $partnerLength < 1 || $partnerLength > 200) throw new InvalidArgumentException('Đối tác/người giao nhận phải có từ 1 đến 200 ký tự.');
    if ($noteLength === false || $noteLength > 5000) throw new InvalidArgumentException('Ghi chú tối đa 5.000 ký tự.');
    $lines = $input['lines'] ?? [];
    if (!is_array($lines) || count($lines) < 1 || count($lines) > 100) throw new InvalidArgumentException('Phiếu cần từ 1 đến 100 dòng hàng.');

    return run_with_deadlock_retry(db(), function (PDO $pdo) use ($type, $date, $partner, $note, $lines, $actorId): int {
        $number = ($type === 'receipt' ? 'PN-' : 'PX-') . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare('INSERT INTO inventory_documents(number,document_type,status,document_date,partner_name,note,created_by) VALUES(:number,:type,\'draft\',:date,:partner,:note,:actor)');
        $stmt->execute(['number'=>$number,'type'=>$type,'date'=>$date,'partner'=>$partner,'note'=>$note,'actor'=>$actorId]);
        $id = (int)$pdo->lastInsertId();
        $documentTotal = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) throw new InvalidArgumentException('Dòng hàng không hợp lệ.');
            $quantity = filter_var($line['quantity'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>1000000]]);
            if ($quantity === false) throw new InvalidArgumentException('Số lượng phải là số nguyên từ 1 đến 1.000.000.');
            $productId = (int)($line['product_id'] ?? 0); $locationId = (int)($line['location_id'] ?? 0); $lotId = (int)($line['lot_id'] ?? 0);
            // Product lock serializes price scheduling with quote creation and prevents a unit change mid-document.
            $products = inventory_query('SELECT p.*,u.name AS unit_name,u.is_active AS unit_active FROM products p JOIN units u ON u.id=p.unit_id WHERE p.id=:id AND p.status=1 FOR UPDATE', ['id'=>$productId]);
            if (!$products) throw new InvalidArgumentException('Sản phẩm phải đang hoạt động và có đơn vị cơ sở.');
            $product = $products[0];
            if ((int)$product['unit_active'] !== 1) throw new InvalidArgumentException('Đơn vị cơ sở của sản phẩm đã ngừng sử dụng.');
            $locations = inventory_query("SELECT CONCAT(w.code,' / ',loc.code) AS label, loc.is_active AS loc_active, w.is_active AS wh_active FROM warehouse_locations loc JOIN warehouses w ON w.id=loc.warehouse_id WHERE loc.id=:id", ['id'=>$locationId]);
            if (!$locations) throw new InvalidArgumentException('Vị trí kho không tồn tại.');
            if ((int)$locations[0]['loc_active'] !== 1 || (int)$locations[0]['wh_active'] !== 1) throw new InvalidArgumentException('Kho hoặc vị trí đã ngừng sử dụng.');
            $lots = inventory_query('SELECT * FROM product_lots WHERE id=:id AND product_id=:product', ['id'=>$lotId,'product'=>$productId]);
            if (!$lots) throw new InvalidArgumentException('Lô hàng phải thuộc đúng sản phẩm.');
            if ((int)$lots[0]['is_active'] !== 1) throw new InvalidArgumentException('Lô hàng đã ngừng sử dụng.');
            if ($type === 'issue') inventory_assert_lot_usable($lots[0]['expires_on'], $date);
            $prices = inventory_query('SELECT * FROM product_prices WHERE product_id=:product AND price_type=:type
                AND valid_from<=:start AND (valid_to IS NULL OR valid_to>:end) ORDER BY valid_from DESC,id DESC LIMIT 2',
                ['product'=>$productId,'type'=>$type === 'receipt' ? 'purchase' : 'sale','start'=>$date,'end'=>$date]);
            if (count($prices) !== 1) throw new InvalidArgumentException('Sản phẩm phải có đúng một giá hiệu lực tại ngày chứng từ. Hãy thiết lập giá trước.');
            $price = $prices[0]; $lineTotal = inventory_price_cents((string)$price['amount']) * $quantity;
            if ($lineTotal > 900000000000000 || $documentTotal + $lineTotal > 900000000000000) throw new InvalidArgumentException('Tổng tiền phiếu vượt giới hạn 9.000.000.000.000 đ.');
            $documentTotal += $lineTotal;
            $stmt = $pdo->prepare('INSERT INTO inventory_lines(document_id,product_id,location_id,lot_id,quantity,unit_id,unit_price,price_id,product_name,sku,unit_name,location_label,lot_code)
                VALUES(:document,:product,:location,:lot,:quantity,:unit,:price,:price_id,:name,:sku,:unit_name,:location_label,:lot_code)');
            $stmt->execute(['document'=>$id,'product'=>$productId,'location'=>$locationId,'lot'=>$lotId,'quantity'=>$quantity,
                'unit'=>$product['unit_id'],'price'=>$price['amount'],'price_id'=>$price['id'],'name'=>$product['name'],'sku'=>$product['sku'],
                'unit_name'=>$product['unit_name'],'location_label'=>$locations[0]['label'],'lot_code'=>$lots[0]['code']]);
        }
        return $id;
    });
}

function inventory_assert_lot_usable(?string $expiry, string $documentDate): void {
    if ($expiry !== null && ($expiry < substr($documentDate, 0, 10) || $expiry < date('Y-m-d'))) {
        throw new InvalidArgumentException('Không được xuất lô đã hết hạn theo ngày chứng từ hoặc ngày hiện tại.');
    }
}

function document_post(int $id, int $actorId): void {
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người ghi sổ không hợp lệ.');
    run_with_deadlock_retry(db(), function (PDO $pdo) use ($id, $actorId): void {
        $rows = inventory_query('SELECT * FROM inventory_documents WHERE id=:id FOR UPDATE', ['id'=>$id]);
        if (!$rows) throw new InvalidArgumentException('Không tìm thấy phiếu.');
        $document = $rows[0];
        require_permission(document_permission($document['document_type'], 'post'));
        if ($document['status'] !== 'draft') throw new InvalidArgumentException('Chỉ phiếu nháp mới được ghi sổ.');
        $lines = inventory_query('SELECT * FROM inventory_lines WHERE document_id=:id ORDER BY product_id,location_id,lot_id,id', ['id'=>$id]);
        if (!$lines) throw new InvalidArgumentException('Phiếu không có dòng hàng.');
        // Lock master data before balances, in product/lot order. Unit or expiry edits cannot
        // race with posting. Document lock above already protects its immutable draft lines.
        $productIds = array_values(array_unique(array_map('intval', array_column($lines, 'product_id'))));
        sort($productIds, SORT_NUMERIC);
        $products = [];
        foreach ($productIds as $productId) {
            $rows = inventory_query('SELECT unit_id FROM products WHERE id=:id FOR UPDATE', ['id'=>$productId]);
            if (!$rows) throw new InvalidArgumentException('Sản phẩm không còn tồn tại.');
            $products[$productId] = $rows[0];
        }
        $lotIds = array_values(array_unique(array_map('intval', array_column($lines, 'lot_id'))));
        sort($lotIds, SORT_NUMERIC);
        $lots = [];
        foreach ($lotIds as $lotId) {
            $rows = inventory_query('SELECT product_id,expires_on FROM product_lots WHERE id=:id FOR UPDATE', ['id'=>$lotId]);
            if (!$rows) throw new InvalidArgumentException('Lô hàng không còn tồn tại.');
            $lots[$lotId] = $rows[0];
        }
        $keys = [];
        foreach ($lines as $line) {
            if ((int)$products[$line['product_id']]['unit_id'] !== (int)$line['unit_id']) throw new InvalidArgumentException('Đơn vị sản phẩm đã thay đổi; không thể ghi sổ phiếu.');
            if ((int)$lots[$line['lot_id']]['product_id'] !== (int)$line['product_id']) throw new InvalidArgumentException('Lô không thuộc sản phẩm.');
            if ($document['document_type'] === 'issue') inventory_assert_lot_usable($lots[$line['lot_id']]['expires_on'], $document['document_date']);
            $key = sprintf('%010d:%010d:%010d', (int)$line['product_id'], (int)$line['location_id'], (int)$line['lot_id']);
            if (!isset($keys[$key])) $keys[$key] = ['product_id'=>(int)$line['product_id'],'location_id'=>(int)$line['location_id'],'lot_id'=>(int)$line['lot_id'],'quantity'=>0];
            $keys[$key]['quantity'] += (int)$line['quantity'];
        }
        ksort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $ids = ['product'=>$key['product_id'],'location'=>$key['location_id'],'lot'=>$key['lot_id']];
            $stmt = $pdo->prepare('INSERT INTO stock_balances(product_id,location_id,lot_id,quantity) VALUES(:product,:location,:lot,0) ON DUPLICATE KEY UPDATE quantity=quantity');
            $stmt->execute($ids);
            $balances = inventory_query('SELECT quantity FROM stock_balances WHERE product_id=:product AND location_id=:location AND lot_id=:lot FOR UPDATE', $ids);
            $change = $key['quantity'] * ($document['document_type'] === 'receipt' ? 1 : -1);
            $next = (int)$balances[0]['quantity'] + $change;
            if ($next < 0) throw new InvalidArgumentException('Tồn kho không đủ tại vị trí và lô đã chọn. Toàn bộ phiếu chưa được ghi sổ.');
            if ($next > 2147483647) throw new InvalidArgumentException('Tồn kho vượt giới hạn lưu trữ.');
            $stmt = $pdo->prepare('UPDATE stock_balances SET quantity=:quantity WHERE product_id=:product AND location_id=:location AND lot_id=:lot');
            $stmt->execute($ids + ['quantity'=>$next]);
        }
        $stmt = $pdo->prepare('INSERT INTO stock_movements(document_id,line_id,product_id,location_id,lot_id,quantity) VALUES(:document,:line,:product,:location,:lot,:quantity)');
        foreach ($lines as $line) $stmt->execute(['document'=>$id,'line'=>$line['id'],'product'=>$line['product_id'],'location'=>$line['location_id'],'lot'=>$line['lot_id'],
            'quantity'=>(int)$line['quantity'] * ($document['document_type'] === 'receipt' ? 1 : -1)]);
        $stmt = $pdo->prepare("UPDATE inventory_documents SET status='posted',posted_by=:actor,posted_at=NOW() WHERE id=:id");
        $stmt->execute(['actor'=>$actorId,'id'=>$id]);
    });
}

function document_cancel(int $id, int $actorId): void {
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người hủy phiếu không hợp lệ.');
    run_with_deadlock_retry(db(), function (PDO $pdo) use ($id): void {
        $rows = inventory_query('SELECT * FROM inventory_documents WHERE id=:id FOR UPDATE', ['id'=>$id]);
        if (!$rows) throw new InvalidArgumentException('Không tìm thấy phiếu.');
        require_permission(document_permission($rows[0]['document_type'], 'create'));
        if ($rows[0]['status'] !== 'draft') throw new InvalidArgumentException('Chỉ được hủy phiếu nháp. Phiếu ghi sổ không được sửa hay hủy.');
        $stmt = $pdo->prepare("UPDATE inventory_documents SET status='cancelled' WHERE id=:id");
        $stmt->execute(['id'=>$id]);
    });
}

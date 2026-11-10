<?php
require_once __DIR__ . "/db.php";

function order_create(int $customerId, array $items, string $notes = ""): int {
    if (empty($items)) {
        throw new InvalidArgumentException("Đơn hàng phải có ít nhất một sản phẩm.");
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $orderCode = "ORD-" . date("Ymd") . "-" . strtoupper(bin2hex(random_bytes(3)));
        $totalAmount = 0.0;
        $orderItemsData = [];

        foreach ($items as $item) {
            $productId = (int) ($item["product_id"] ?? 0);
            $qty = (int) ($item["quantity"] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                throw new InvalidArgumentException("Thông tin sản phẩm hoặc số lượng không hợp lệ.");
            }

            // Lock product row for update
            $stmt = $pdo->prepare("SELECT id, name, unit_price, quantity FROM products WHERE id = :id FOR UPDATE");
            $stmt->execute(["id" => $productId]);
            $prod = $stmt->fetch();
            if (!$prod) {
                throw new RuntimeException("Sản phẩm ID {$productId} không tồn tại.");
            }

            $currentStock = (int) $prod["quantity"];
            if ($qty > $currentStock) {
                throw new RuntimeException("Sản phẩm '{$prod['name']}' không đủ tồn kho (Còn $currentStock, yêu cầu $qty).");
            }

            // Deduct stock
            $upStmt = $pdo->prepare("UPDATE products SET quantity = quantity - :q WHERE id = :id");
            $upStmt->execute(["q" => $qty, "id" => $productId]);

            $unitPrice = (float) $prod["unit_price"];
            $subtotal = $unitPrice * $qty;
            $totalAmount += $subtotal;

            $orderItemsData[] = [
                "product_id" => $productId,
                "quantity" => $qty,
                "unit_price" => $unitPrice,
                "subtotal" => $subtotal
            ];
        }

        // Insert Order
        $orderStmt = $pdo->prepare("
            INSERT INTO orders (order_code, customer_id, total_amount, status, notes) 
            VALUES (:code, :cid, :total, 'pending', :notes)
        ");
        $orderStmt->execute([
            "code" => $orderCode,
            "cid" => $customerId,
            "total" => $totalAmount,
            "notes" => $notes
        ]);
        $orderId = (int) $pdo->lastInsertId();

        // Insert Order Items
        $itemStmt = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) 
            VALUES (:oid, :pid, :qty, :price, :subtotal)
        ");
        foreach ($orderItemsData as $oi) {
            $itemStmt->execute([
                "oid" => $orderId,
                "pid" => $oi["product_id"],
                "qty" => $oi["quantity"],
                "price" => $oi["unit_price"],
                "subtotal" => $oi["subtotal"]
            ]);
        }

        $pdo->commit();
        return $orderId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function order_find(int $id): ?array {
    $stmt = db()->prepare("
        SELECT o.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, c.address AS customer_address 
        FROM orders o 
        INNER JOIN customers c ON c.id = o.customer_id 
        WHERE o.id = :id LIMIT 1
    ");
    $stmt->execute(["id" => $id]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function order_find_by_code(string $code): ?array {
    $stmt = db()->prepare("
        SELECT o.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, c.address AS customer_address 
        FROM orders o 
        INNER JOIN customers c ON c.id = o.customer_id 
        WHERE o.order_code = :code LIMIT 1
    ");
    $stmt->execute(["code" => $code]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function order_items_find(int $orderId): array {
    $stmt = db()->prepare("
        SELECT oi.*, p.name AS product_name, p.sku AS product_sku, p.image_path AS product_image 
        FROM order_items oi 
        INNER JOIN products p ON p.id = oi.product_id 
        WHERE oi.order_id = :oid
    ");
    $stmt->execute(["oid" => $orderId]);
    return $stmt->fetchAll();
}

function order_update_status(int $id, string $newStatus): void {
    $validStatuses = ["pending", "processing", "completed", "cancelled"];
    if (!in_array($newStatus, $validStatuses, true)) {
        throw new InvalidArgumentException("Trạng thái đơn hàng không hợp lệ.");
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id = :id FOR UPDATE");
        $stmt->execute(["id" => $id]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new RuntimeException("Không tìm thấy đơn hàng ID {$id}.");
        }

        $oldStatus = $order["status"];

        // If transitioning from non-cancelled to cancelled, restore product stock!
        if ($oldStatus !== "cancelled" && $newStatus === "cancelled") {
            $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = :oid");
            $itemsStmt->execute(["oid" => $id]);
            $items = $itemsStmt->fetchAll();

            $restoreStmt = $pdo->prepare("UPDATE products SET quantity = quantity + :q WHERE id = :pid");
            foreach ($items as $item) {
                $restoreStmt->execute(["q" => $item["quantity"], "pid" => $item["product_id"]]);
            }
        }

        // If transitioning from cancelled back to pending/processing/completed, re-deduct stock!
        if ($oldStatus === "cancelled" && $newStatus !== "cancelled") {
            $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = :oid");
            $itemsStmt->execute(["oid" => $id]);
            $items = $itemsStmt->fetchAll();

            $deductStmt = $pdo->prepare("UPDATE products SET quantity = quantity - :q WHERE id = :pid");
            foreach ($items as $item) {
                $deductStmt->execute(["q" => $item["quantity"], "pid" => $item["product_id"]]);
            }
        }

        $upStmt = $pdo->prepare("UPDATE orders SET status = :st WHERE id = :id");
        $upStmt->execute(["st" => $newStatus, "id" => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function order_paginate(string $status = "", string $keyword = "", int $page = 1, int $perPage = 10): array {
    $where = "WHERE 1=1";
    $params = [];

    if ($status !== "") {
        $where .= " AND o.status = :st";
        $params["st"] = $status;
    }

    if (trim($keyword) !== "") {
        $where .= " AND (o.order_code LIKE :kw OR c.name LIKE :kw OR c.email LIKE :kw OR c.phone LIKE :kw)";
        $params["kw"] = "%" . trim($keyword) . "%";
    }

    $countSql = "SELECT COUNT(*) FROM orders o INNER JOIN customers c ON c.id = o.customer_id " . $where;
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($params);
    $totalItems = (int) $countStmt->fetchColumn();

    $perPage = max(1, $perPage);
    $totalPages = (int) ceil($totalItems / $perPage);
    $totalPages = max(1, $totalPages);
    $currentPage = min(max(1, $page), $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $sql = "SELECT o.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone 
            FROM orders o 
            INNER JOIN customers c ON c.id = o.customer_id 
            " . $where . " 
            ORDER BY o.id DESC LIMIT :limit OFFSET :offset";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(":limit", $perPage, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    return [
        "items" => $items,
        "totalItems" => $totalItems,
        "totalPages" => $totalPages,
        "currentPage" => $currentPage,
        "perPage" => $perPage,
    ];
}

function order_count(): int {
    return (int) db()->query("SELECT COUNT(*) FROM orders")->fetchColumn();
}

function order_total_revenue(): float {
    return (float) db()->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status != 'cancelled'")->fetchColumn();
}

function order_all(): array {
    $sql = "SELECT o.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone 
            FROM orders o 
            INNER JOIN customers c ON c.id = o.customer_id 
            ORDER BY o.id DESC";
    return db()->query($sql)->fetchAll();
}

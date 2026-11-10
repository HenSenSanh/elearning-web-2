<?php
require_once __DIR__ . "/db.php";

function inventory_log_create(int $productId, ?int $adminId, string $type, int $quantity, string $note = ""): int {
    $type = strtoupper($type);
    if (!in_array($type, ["IN", "OUT"], true)) {
        throw new InvalidArgumentException("Loại phiếu kho không hợp lệ (chỉ chấp nhận IN hoặc OUT).");
    }
    if ($quantity <= 0) {
        throw new InvalidArgumentException("Số lượng phải lớn hơn 0.");
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        // Lock product row for update
        $stmt = $pdo->prepare("SELECT id, quantity, name FROM products WHERE id = :id FOR UPDATE");
        $stmt->execute(["id" => $productId]);
        $prod = $stmt->fetch();
        if (!$prod) {
            throw new RuntimeException("Không tìm thấy sản phẩm ID {$productId}.");
        }

        $currentQty = (int) $prod["quantity"];
        if ($type === "IN") {
            $newQty = $currentQty + $quantity;
        } else {
            if ($quantity > $currentQty) {
                throw new RuntimeException("Số lượng xuất kho ($quantity) vượt quá số lượng tồn hiện có ($currentQty).");
            }
            $newQty = $currentQty - $quantity;
        }

        // Update product quantity
        $upStmt = $pdo->prepare("UPDATE products SET quantity = :q WHERE id = :id");
        $upStmt->execute(["q" => $newQty, "id" => $productId]);

        // Insert log
        $logStmt = $pdo->prepare("
            INSERT INTO inventory_logs (product_id, admin_id, type, quantity, note) 
            VALUES (:pid, :aid, :t, :q, :note)
        ");
        $logStmt->execute([
            "pid" => $productId,
            "aid" => $adminId,
            "t" => $type,
            "q" => $quantity,
            "note" => $note
        ]);
        $logId = (int) $pdo->lastInsertId();

        $pdo->commit();
        return $logId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function inventory_log_paginate(int $page = 1, int $perPage = 10, ?int $productId = null): array {
    $where = "WHERE 1=1";
    $params = [];
    if ($productId !== null && $productId > 0) {
        $where .= " AND il.product_id = :pid";
        $params["pid"] = $productId;
    }

    $countSql = "SELECT COUNT(*) FROM inventory_logs il " . $where;
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($params);
    $totalItems = (int) $countStmt->fetchColumn();

    $perPage = max(1, $perPage);
    $totalPages = (int) ceil($totalItems / $perPage);
    $totalPages = max(1, $totalPages);
    $currentPage = min(max(1, $page), $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $sql = "SELECT il.*, p.name AS product_name, p.sku AS product_sku, a.username AS admin_username 
            FROM inventory_logs il 
            INNER JOIN products p ON p.id = il.product_id 
            LEFT JOIN admins a ON a.id = il.admin_id 
            " . $where . " 
            ORDER BY il.id DESC LIMIT :limit OFFSET :offset";
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

function inventory_log_all(): array {
    $sql = "SELECT il.*, p.name AS product_name, p.sku AS product_sku, a.username AS admin_username 
            FROM inventory_logs il 
            INNER JOIN products p ON p.id = il.product_id 
            LEFT JOIN admins a ON a.id = il.admin_id 
            ORDER BY il.id DESC";
    return db()->query($sql)->fetchAll();
}

function inventory_total_in(): int {
    return (int) db()->query("SELECT COALESCE(SUM(quantity),0) FROM inventory_logs WHERE type = 'IN'")->fetchColumn();
}

function inventory_total_out(): int {
    return (int) db()->query("SELECT COALESCE(SUM(quantity),0) FROM inventory_logs WHERE type = 'OUT'")->fetchColumn();
}

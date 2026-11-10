<?php
require_once __DIR__ . "/db.php";

function customer_all(): array {
    return db()->query("SELECT * FROM customers ORDER BY id DESC")->fetchAll();
}

function customer_active_all(): array {
    return db()->query("SELECT * FROM customers WHERE status = 1 ORDER BY name ASC")->fetchAll();
}

function customer_find(int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
    $stmt->execute(["id" => $id]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function customer_create(array $data): int {
    $stmt = db()->prepare("
        INSERT INTO customers (name, email, phone, address, status) 
        VALUES (:name, :email, :phone, :address, :status)
    ");
    $stmt->execute([
        "name" => trim($data["name"]),
        "email" => trim($data["email"]),
        "phone" => trim($data["phone"] ?? ""),
        "address" => trim($data["address"] ?? ""),
        "status" => isset($data["status"]) ? (int)$data["status"] : 1
    ]);
    return (int) db()->lastInsertId();
}

function customer_update(int $id, array $data): void {
    $stmt = db()->prepare("
        UPDATE customers 
        SET name = :name, email = :email, phone = :phone, address = :address, status = :status 
        WHERE id = :id
    ");
    $stmt->execute([
        "id" => $id,
        "name" => trim($data["name"]),
        "email" => trim($data["email"]),
        "phone" => trim($data["phone"] ?? ""),
        "address" => trim($data["address"] ?? ""),
        "status" => isset($data["status"]) ? (int)$data["status"] : 1
    ]);
}

function customer_delete(int $id): void {
    $stmt = db()->prepare("DELETE FROM customers WHERE id = :id");
    $stmt->execute(["id" => $id]);
}

function customer_exists_email(string $email, int $excludeId = 0): bool {
    $email = trim($email);
    if ($email === "") return false;
    $sql = "SELECT COUNT(*) FROM customers WHERE LOWER(email) = LOWER(:e)";
    $params = ["e" => $email];
    if ($excludeId > 0) {
        $sql .= " AND id != :id";
        $params["id"] = $excludeId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function customer_count(): int {
    return (int) db()->query("SELECT COUNT(*) FROM customers")->fetchColumn();
}

function customer_paginate(string $keyword = "", int $page = 1, int $perPage = 10): array {
    $where = "WHERE 1=1";
    $params = [];
    if (trim($keyword) !== "") {
        $where .= " AND (name LIKE :kw OR email LIKE :kw OR phone LIKE :kw)";
        $params["kw"] = "%" . trim($keyword) . "%";
    }

    $countSql = "SELECT COUNT(*) FROM customers " . $where;
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($params);
    $totalItems = (int) $countStmt->fetchColumn();

    $perPage = max(1, $perPage);
    $totalPages = (int) ceil($totalItems / $perPage);
    $totalPages = max(1, $totalPages);
    $currentPage = min(max(1, $page), $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $sql = "SELECT * FROM customers " . $where . " ORDER BY id DESC LIMIT :limit OFFSET :offset";
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

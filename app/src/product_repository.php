<?php
require_once __DIR__ . "/db.php";

function product_paginate(string $keyword = "", int $categoryId = 0, int $page = 1, int $perPage = 5, int $brandId = 0): array {
    $where = "WHERE 1=1";
    $params = [];
    if ($keyword !== "") {
        $where .= " AND (p.name LIKE :kw OR p.sku LIKE :kw)";
        $params["kw"] = "%" . $keyword . "%";
    }
    if ($categoryId > 0) {
        $where .= " AND p.category_id = :cid";
        $params["cid"] = $categoryId;
    }
    if ($brandId > 0) {
        $where .= " AND p.brand_id = :bid";
        $params["bid"] = $brandId;
    }

    $countSql = "SELECT COUNT(*) FROM products p 
                 INNER JOIN categories c ON c.id=p.category_id 
                 LEFT JOIN brands b ON b.id=p.brand_id " . $where;
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($params);
    $totalItems = (int) $countStmt->fetchColumn();

    $perPage = max(1, $perPage);
    $totalPages = (int) ceil($totalItems / $perPage);
    $totalPages = max(1, $totalPages);
    $currentPage = min(max(1, $page), $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $sql = "SELECT p.*, c.name AS category_name, b.name AS brand_name 
            FROM products p 
            INNER JOIN categories c ON c.id=p.category_id 
            LEFT JOIN brands b ON b.id=p.brand_id " . $where . " 
            ORDER BY p.id DESC LIMIT :limit OFFSET :offset";
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

function product_find(int $id): ?array {
    $stmt = db()->prepare("
        SELECT p.*, c.name AS category_name, b.name AS brand_name 
        FROM products p 
        INNER JOIN categories c ON c.id=p.category_id 
        LEFT JOIN brands b ON b.id=p.brand_id 
        WHERE p.id=:id LIMIT 1
    ");
    $stmt->execute(["id" => $id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function product_create(array $d): int {
    if (!isset($d["brand_id"])) $d["brand_id"] = null;
    $stmt = db()->prepare("
        INSERT INTO products(category_id, brand_id, name, sku, description, quantity, unit_price, image_path, status) 
        VALUES(:category_id, :brand_id, :name, :sku, :description, :quantity, :unit_price, :image_path, :status)
    ");
    $stmt->execute($d);
    return (int) db()->lastInsertId();
}

function product_update(int $id, array $d): void {
    $d["id"] = $id;
    if (!isset($d["brand_id"])) $d["brand_id"] = null;
    $stmt = db()->prepare("
        UPDATE products 
        SET category_id=:category_id, brand_id=:brand_id, name=:name, sku=:sku, description=:description, quantity=:quantity, unit_price=:unit_price, image_path=:image_path, status=:status 
        WHERE id=:id
    ");
    $stmt->execute($d);
}

function product_delete(int $id): void {
    $stmt = db()->prepare("DELETE FROM products WHERE id=:id");
    $stmt->execute(["id" => $id]);
}

function product_count(): int {
    return (int) db()->query("SELECT COUNT(*) FROM products")->fetchColumn();
}

function product_total_quantity(): int {
    return (int) db()->query("SELECT COALESCE(SUM(quantity),0) FROM products")->fetchColumn();
}

function product_total_inventory_value(): float {
    return (float) db()->query("SELECT COALESCE(SUM(quantity*unit_price),0) FROM products")->fetchColumn();
}

function product_exists_sku(string $sku, int $excludeId = 0): bool {
    if (trim($sku) === "") return false;
    $stmt = db()->prepare("SELECT COUNT(*) FROM products WHERE LOWER(sku) = LOWER(:sku) AND id != :id");
    $stmt->execute(["sku" => trim($sku), "id" => $excludeId]);
    return (int) $stmt->fetchColumn() > 0;
}

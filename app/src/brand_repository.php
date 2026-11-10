<?php
require_once __DIR__ . "/db.php";

function brand_all(): array {
    return db()->query("SELECT * FROM brands ORDER BY id DESC")->fetchAll();
}

function brand_active_all(): array {
    return db()->query("SELECT * FROM brands WHERE status = 1 ORDER BY name ASC")->fetchAll();
}

function brand_find(int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM brands WHERE id = :id LIMIT 1");
    $stmt->execute(["id" => $id]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function brand_create(string $name, string $description, int $status): void {
    $stmt = db()->prepare("INSERT INTO brands (name, description, status) VALUES (:name, :description, :status)");
    $stmt->execute(["name" => $name, "description" => $description, "status" => $status]);
}

function brand_update(int $id, string $name, string $description, int $status): void {
    $stmt = db()->prepare("UPDATE brands SET name = :name, description = :description, status = :status WHERE id = :id");
    $stmt->execute(["name" => $name, "description" => $description, "status" => $status, "id" => $id]);
}

function brand_delete(int $id): void {
    $stmt = db()->prepare("DELETE FROM brands WHERE id = :id");
    $stmt->execute(["id" => $id]);
}

function brand_exists_name(string $name, int $excludeId = 0): bool {
    $sql = "SELECT COUNT(*) FROM brands WHERE name = :name";
    $params = ["name" => $name];
    if ($excludeId > 0) {
        $sql .= " AND id != :excludeId";
        $params["excludeId"] = $excludeId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function brand_has_products(int $id): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM products WHERE brand_id = :id");
    $stmt->execute(["id" => $id]);
    return (int) $stmt->fetchColumn() > 0;
}

function brand_count(): int {
    return (int) db()->query("SELECT COUNT(*) FROM brands")->fetchColumn();
}

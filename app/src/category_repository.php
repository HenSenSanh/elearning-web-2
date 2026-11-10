<?php
require_once __DIR__ . "/db.php";
function category_all(): array { return db()->query("SELECT * FROM categories ORDER BY id DESC")->fetchAll(); }
function category_active_all(): array { return db()->query("SELECT * FROM categories WHERE status=1 ORDER BY name ASC")->fetchAll(); }
function category_find(int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM categories WHERE id=:id LIMIT 1");
    $stmt->execute(["id"=>$id]); $r=$stmt->fetch(); return $r ?: null;
}
function category_create(string $name, ?string $description, int $status): void {
    $stmt=db()->prepare("INSERT INTO categories(name,description,status) VALUES(:n,:d,:s)");
    $stmt->execute(["n"=>$name,"d"=>$description,"s"=>$status]);
}
function category_update(int $id, string $name, ?string $description, int $status): void {
    $stmt=db()->prepare("UPDATE categories SET name=:n, description=:d, status=:s WHERE id=:id");
    $stmt->execute(["id"=>$id,"n"=>$name,"d"=>$description,"s"=>$status]);
}
function category_delete(int $id): void {
    $stmt=db()->prepare("DELETE FROM categories WHERE id=:id");
    $stmt->execute(["id"=>$id]);
}
function category_count(): int { return (int) db()->query("SELECT COUNT(*) FROM categories")->fetchColumn(); }
function category_exists_name(string $name, int $excludeId = 0): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(:n) AND id != :id");
    $stmt->execute(["n" => trim($name), "id" => $excludeId]);
    return (int) $stmt->fetchColumn() > 0;
}
function category_has_products(int $id): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM products WHERE category_id = :id");
    $stmt->execute(["id" => $id]);
    return (int) $stmt->fetchColumn() > 0;
}

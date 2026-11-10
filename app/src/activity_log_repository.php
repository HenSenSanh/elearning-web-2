<?php
require_once __DIR__ . "/db.php";

function log_activity(?int $adminId, string $action, string $description = "", ?string $ipAddress = null): void {
    if ($ipAddress === null) {
        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "127.0.0.1";
    }
    if ($adminId === null && !empty($_SESSION["admin_id"])) {
        $adminId = (int)$_SESSION["admin_id"];
    }

    $stmt = db()->prepare("
        INSERT INTO activity_logs (admin_id, action, description, ip_address) 
        VALUES (:aid, :act, :desc, :ip)
    ");
    $stmt->execute([
        "aid" => $adminId,
        "act" => trim($action),
        "desc" => trim($description),
        "ip" => trim($ipAddress)
    ]);
}

function activity_log_paginate(int $page = 1, int $perPage = 15): array {
    $countSql = "SELECT COUNT(*) FROM activity_logs";
    $totalItems = (int) db()->query($countSql)->fetchColumn();

    $perPage = max(1, $perPage);
    $totalPages = (int) ceil($totalItems / $perPage);
    $totalPages = max(1, $totalPages);
    $currentPage = min(max(1, $page), $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $sql = "SELECT l.*, a.username AS admin_username, a.full_name AS admin_full_name 
            FROM activity_logs l 
            LEFT JOIN admins a ON a.id = l.admin_id 
            ORDER BY l.id DESC LIMIT :limit OFFSET :offset";
    $stmt = db()->prepare($sql);
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

function activity_log_all(): array {
    $sql = "SELECT l.*, a.username AS admin_username 
            FROM activity_logs l 
            LEFT JOIN admins a ON a.id = l.admin_id 
            ORDER BY l.id DESC";
    return db()->query($sql)->fetchAll();
}

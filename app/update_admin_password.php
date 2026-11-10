<?php
require_once __DIR__ . "/src/config.php";
require_once __DIR__ . "/src/db.php";

$db = db();
$db->exec("ALTER TABLE admins MODIFY COLUMN password_hash VARCHAR(255) NOT NULL");
$hash = password_hash("admin123", PASSWORD_BCRYPT);
$stmt = $db->prepare("UPDATE admins SET password_hash = :h WHERE username = 'admin'");
$stmt->execute(["h" => $hash]);

echo "Admin password updated to BCRYPT successfully: " . $hash . "\n";

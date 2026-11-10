<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/helpers.php";
$config = require __DIR__ . "/config.php";
if (session_status() === PHP_SESSION_NONE) {
    session_name($config["app"]["session_name"]);
    session_start();
}
function admin_login(string $username, string $password): array {
    if ($username === "" || $password === "") return ["success"=>false,"message"=>"Vui lòng nhập tài khoản và mật khẩu."];
    $stmt = db()->prepare("SELECT * FROM admins WHERE username = :u OR email = :u LIMIT 1");
    $stmt->execute(["u"=>$username]);
    $admin = $stmt->fetch();
    if (!$admin) return ["success"=>false,"message"=>"Tài khoản không tồn tại."];

    $isValid = false;
    if (password_verify($password, $admin["password_hash"])) {
        $isValid = true;
    } elseif (hash_equals($admin["password_hash"], hash("sha256", $password))) {
        // Tự động nâng cấp hash SHA256 cũ sang chuẩn bảo mật BCRYPT
        $isValid = true;
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $upStmt = db()->prepare("UPDATE admins SET password_hash = :h WHERE id = :id");
        $upStmt->execute(["h" => $newHash, "id" => $admin["id"]]);
    }

    if (!$isValid) return ["success"=>false,"message"=>"Mật khẩu không chính xác."];

    $_SESSION["admin_id"] = $admin["id"];
    $_SESSION["admin_username"] = $admin["username"];
    $_SESSION["admin_full_name"] = $admin["full_name"];
    return ["success"=>true,"message"=>"Đăng nhập thành công."];
}
function require_admin(): void {
    if (empty($_SESSION["admin_id"])) redirect("/login");
}
function admin_logout(): void {
    $_SESSION = [];
    session_destroy();
    redirect("/login");
}

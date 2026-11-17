<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/helpers.php";
$config = require __DIR__ . "/config.php";
if (session_status() === PHP_SESSION_NONE) {
    session_name($config["app"]["session_name"]);
    if (!headers_sent()) {
        session_set_cookie_params([
            "lifetime" => 0,
            "path" => "/",
            "httponly" => true,
            "samesite" => "Lax",
        ]);
    }
    session_start();
}
function admin_login(string $username, string $password): array {
    if ($username === "" || $password === "") return ["success"=>false,"message"=>"Vui lòng nhập tài khoản và mật khẩu."];
    $stmt = db()->prepare("SELECT * FROM admins WHERE username = :u OR email = :email LIMIT 1");
    $stmt->execute(["u"=>$username,"email"=>$username]);
    $admin = $stmt->fetch();
    if (!$admin) return ["success"=>false,"message"=>"Tài khoản không tồn tại."];
    if (array_key_exists("is_active", $admin) && (int)$admin["is_active"] !== 1) {
        return ["success"=>false,"message"=>"Tài khoản đã bị khóa. Vui lòng liên hệ quản trị viên."];
    }
    $legacy = strlen($admin['password_hash']) === 64 && ctype_xdigit($admin['password_hash']);
    $valid = $legacy ? hash_equals($admin['password_hash'], hash('sha256', $password)) : password_verify($password, $admin['password_hash']);
    if (!$valid) return ["success"=>false,"message"=>"Mật khẩu không chính xác."];
    if ($legacy || password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION["admin_id"] = (int)$admin["id"];
    $_SESSION["admin_username"] = $admin["username"];
    $_SESSION["admin_full_name"] = $admin["full_name"];
    return ["success"=>true,"message"=>"Đăng nhập thành công."];
}
function current_user(): ?array {
    if (empty($_SESSION["admin_id"])) return null;
    $stmt = db()->prepare("SELECT id, username, email, full_name, is_active FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION["admin_id"]]);
    $user = $stmt->fetch();
    if (!$user || (int)$user["is_active"] !== 1) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            if (!headers_sent()) {
                session_start();
            }
        }
        return null;
    }
    $_SESSION["admin_username"] = $user["username"];
    $_SESSION["admin_full_name"] = $user["full_name"];
    return $user;
}
function require_admin(): void {
    $hadSession = !empty($_SESSION["admin_id"]);
    if (!current_user()) {
        if ($hadSession) {
            $_SESSION["flash_error"] = "Phiên làm việc đã hết hiệu lực hoặc tài khoản đã bị khóa.";
        }
        redirect("/login");
    }
}
function admin_logout(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    redirect("/login");
}

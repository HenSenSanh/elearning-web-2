<?php
require_once __DIR__ . "/db.php";

function password_reset_create_token(string $email): string {
    $email = trim($email);
    // Verify admin exists
    $stmt = db()->prepare("SELECT id FROM admins WHERE email = :e LIMIT 1");
    $stmt->execute(["e" => $email]);
    if (!$stmt->fetch()) {
        throw new RuntimeException("Không tìm thấy quản trị viên với địa chỉ email '{$email}'.");
    }

    $token = bin2hex(random_bytes(32)); // 64 chars
    $expiresAt = date("Y-m-d H:i:s", time() + 3600); // 1 hour

    // Clean any prior pending tokens for this email
    $del = db()->prepare("DELETE FROM password_resets WHERE email = :e");
    $del->execute(["e" => $email]);

    $ins = db()->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (:e, :t, :exp)");
    $ins->execute(["e" => $email, "t" => $token, "exp" => $expiresAt]);

    return $token;
}

function password_reset_verify_token(string $token): ?array {
    $token = trim($token);
    if ($token === "") return null;

    $stmt = db()->prepare("
        SELECT * FROM password_resets 
        WHERE token = :t AND expires_at > NOW() 
        LIMIT 1
    ");
    $stmt->execute(["t" => $token]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function password_reset_apply(string $token, string $newPassword): bool {
    $reset = password_reset_verify_token($token);
    if (!$reset) {
        return false;
    }

    if (strlen($newPassword) < 6) {
        throw new InvalidArgumentException("Mật khẩu mới phải có ít nhất 6 ký tự.");
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $up = db()->prepare("UPDATE admins SET password_hash = :h WHERE email = :e");
    $up->execute(["h" => $hash, "e" => $reset["email"]]);

    // Delete token after successful reset
    $del = db()->prepare("DELETE FROM password_resets WHERE token = :t");
    $del->execute(["t" => $token]);

    return true;
}

function password_reset_cleanup(): void {
    db()->exec("DELETE FROM password_resets WHERE expires_at < NOW()");
}

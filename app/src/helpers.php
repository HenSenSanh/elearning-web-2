<?php
function e(?string $value): string { return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8"); }
function redirect(string $path): void { header("Location: " . $path); exit; }
function is_post(): bool { return $_SERVER["REQUEST_METHOD"] === "POST"; }

function set_flash(string $type, string $message): void {
    $_SESSION["_flash"][$type] = $message;
}

function get_flash(string $type): ?string {
    if (!empty($_SESSION["_flash"][$type])) {
        $msg = $_SESSION["_flash"][$type];
        unset($_SESSION["_flash"][$type]);
        return $msg;
    }
    return null;
}

function csrf_token(): string {
    if (empty($_SESSION["_csrf_token"])) $_SESSION["_csrf_token"] = bin2hex(random_bytes(32));
    return $_SESSION["_csrf_token"];
}
function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
}
function verify_csrf(): void {
    $token = $_POST["_csrf_token"] ?? "";
    if (empty($_SESSION["_csrf_token"]) || !hash_equals($_SESSION["_csrf_token"], $token)) {
        http_response_code(419); die("CSRF token không hợp lệ.");
    }
}
function upload_product_image(array $file): ?string {
    $config = require __DIR__ . "/config.php";
    if (!isset($file["error"]) || $file["error"] === UPLOAD_ERR_NO_FILE) return null;
    if ($file["error"] !== UPLOAD_ERR_OK) throw new RuntimeException("Upload hình ảnh thất bại.");
    if ($file["size"] > $config["app"]["upload_max_size"]) throw new RuntimeException("Dung lượng hình ảnh vượt quá giới hạn.");
    $allowed = ["image/jpeg"=>"jpg","image/png"=>"png","image/webp"=>"webp","image/gif"=>"gif"];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
    if (!array_key_exists($mime, $allowed)) throw new RuntimeException("Chỉ cho phép JPG, PNG, WEBP hoặc GIF.");
    $name = date("YmdHis") . "_" . bin2hex(random_bytes(8)) . "." . $allowed[$mime];
    $dir = __DIR__ . "/../public/uploads/products";
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (!move_uploaded_file($file["tmp_name"], $dir . "/" . $name)) throw new RuntimeException("Không thể lưu hình ảnh.");
    return "uploads/products/" . $name;
}

function upload_product_gallery_images(array $files): array {
    $paths = [];
    if (!isset($files["name"]) || !is_array($files["name"])) return $paths;
    $count = count($files["name"]);
    for ($i = 0; $i < $count; $i++) {
        if ($files["error"][$i] === UPLOAD_ERR_NO_FILE) continue;
        $file = [
            "name" => $files["name"][$i],
            "type" => $files["type"][$i],
            "tmp_name" => $files["tmp_name"][$i],
            "error" => $files["error"][$i],
            "size" => $files["size"][$i]
        ];
        $path = upload_product_image($file);
        if ($path) {
            $paths[] = $path;
        }
    }
    return $paths;
}

function delete_product_image(?string $imagePath): void {
    if (!$imagePath) return;
    $fullPath = __DIR__ . "/../public/" . $imagePath;
    if (is_file($fullPath)) unlink($fullPath);
}

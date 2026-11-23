<?php
date_default_timezone_set("Asia/Ho_Chi_Minh");
if (!function_exists('env_value')) {
function env_value(string $key, string $default = ""): string {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}
}
return [
    "app" => [
        "name" => env_value("APP_NAME", "PHP Admin Product Manager"),
        "url" => env_value("APP_URL", "http://localhost:8090"),
        "session_name" => env_value("SESSION_NAME", "ADMIN_PRODUCT_SESSION"),
        "upload_max_size" => (int) env_value("UPLOAD_MAX_SIZE", "2097152"),
    ],
    "db" => [
        "host" => env_value("DB_HOST", "mysql"),
        "port" => env_value("DB_PORT", "3306"),
        "database" => env_value("DB_DATABASE", "admin_product_db"),
        "username" => env_value("DB_USERNAME", "admin_user"),
        "password" => env_value("DB_PASSWORD", "admin_password"),
    ],
    "ai_db" => [
        "host" => env_value("AI_DB_HOST", env_value("DB_HOST", "mysql")),
        "port" => env_value("AI_DB_PORT", env_value("DB_PORT", "3306")),
        "database" => env_value("AI_DB_DATABASE", env_value("DB_DATABASE", "admin_product_db")),
        "username" => env_value("AI_DB_USERNAME", "ai_reader"),
        "password" => env_value("AI_DB_PASSWORD", ""),
    ],
    "ollama" => [
        "url" => rtrim(env_value("OLLAMA_URL", "http://ollama:11434"), "/"),
        "model" => env_value("OLLAMA_MODEL", "qwen2.5:3b"),
        "timeout" => 240,
    ],
    "rabbitmq" => [
        "host" => env_value("RABBITMQ_HOST", "rabbitmq"),
        "port" => (int) env_value("RABBITMQ_PORT", "5672"),
        "user" => env_value("RABBITMQ_USER", "report_worker"),
        "password" => env_value("RABBITMQ_PASSWORD", ""),
        "queue" => env_value("RABBITMQ_QUEUE", "ai_jobs"),
    ],
    "reports" => [
        "templates_dir" => env_value("REPORT_TEMPLATE_DIR", "/var/www/reports/templates"),
        "exports_dir" => env_value("REPORT_DIR", "/var/www/reports/exports"),
    ],
];

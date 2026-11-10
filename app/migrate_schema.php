<?php
/**
 * Safe Database Migration Script
 * Runs idempotent schema updates on the live running MySQL database.
 */

require_once __DIR__ . "/src/config.php";
require_once __DIR__ . "/src/db.php";

echo "--- Starting Database Migration ---\n";

$pdo = db();

// 1. Create brands table
$pdo->exec("
CREATE TABLE IF NOT EXISTS brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
echo "[OK] Table 'brands' verified.\n";

// 2. Add brand_id column to products if not exists
$checkCol = $pdo->query("SHOW COLUMNS FROM products LIKE 'brand_id'")->fetch();
if (!$checkCol) {
    $pdo->exec("ALTER TABLE products ADD COLUMN brand_id INT DEFAULT NULL AFTER category_id");
    echo "[OK] Column 'brand_id' added to table 'products'.\n";
    try {
        $pdo->exec("ALTER TABLE products ADD CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE RESTRICT ON UPDATE CASCADE");
        $pdo->exec("ALTER TABLE products ADD INDEX idx_product_brand (brand_id)");
        echo "[OK] Foreign key 'fk_products_brand' added.\n";
    } catch (PDOException $e) {
        echo "[INFO] FK constraint already exists or skipped: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] Column 'brand_id' already present in table 'products'.\n";
}

// 3. Create product_images table
$pdo->exec("
CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
echo "[OK] Table 'product_images' verified.\n";

// 4. Create inventory_logs table
$pdo->exec("
CREATE TABLE IF NOT EXISTS inventory_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    admin_id INT DEFAULT NULL,
    type ENUM('IN', 'OUT') NOT NULL,
    quantity INT NOT NULL,
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_inv_product (product_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
echo "[OK] Table 'inventory_logs' verified.\n";

// 5. Create customers table
$pdo->exec("
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
echo "[OK] Table 'customers' verified.\n";

// 6. Create orders table
$pdo->exec("
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
echo "[OK] Table 'orders' verified.\n";

// 7. Create order_items table
$pdo->exec("
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
echo "[OK] Table 'order_items' verified.\n";

// 8. Create activity_logs table
$pdo->exec("
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    ip_address VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_admin (admin_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
echo "[OK] Table 'activity_logs' verified.\n";

// 9. Create password_resets table
$pdo->exec("
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    token VARCHAR(100) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_token (token)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
echo "[OK] Table 'password_resets' verified.\n";

// Seed default brands if empty
$brandCount = (int) $pdo->query("SELECT COUNT(*) FROM brands")->fetchColumn();
if ($brandCount === 0) {
    $pdo->exec("
        INSERT INTO brands (id, name, description, status) VALUES
        (1, 'Dell', 'Thương hiệu máy tính xách tay và máy trạm từ Mỹ', 1),
        (2, 'Apple', 'Thiết bị công nghệ cao cấp iPhone, iPad, Mac', 1),
        (3, 'Logitech', 'Phụ kiện máy tính, bàn phím chuột hàng đầu Thụy Sĩ', 1),
        (4, 'Samsung', 'Tập đoàn điện tử công nghệ đa quốc gia Hàn Quốc', 1);
    ");
    echo "[OK] Seeded 4 default brands.\n";

    // Link existing sample products to brands if they exist
    $pdo->exec("UPDATE products SET brand_id = 1 WHERE id = 1 AND brand_id IS NULL");
    $pdo->exec("UPDATE products SET brand_id = 2 WHERE id = 2 AND brand_id IS NULL");
    $pdo->exec("UPDATE products SET brand_id = 3 WHERE id = 3 AND brand_id IS NULL");
    echo "[OK] Linked default products to brands.\n";
}

// Seed default customers if empty
$custCount = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
if ($custCount === 0) {
    $pdo->exec("
        INSERT INTO customers (id, name, email, phone, address, status) VALUES
        (1, 'Nguyễn Văn A', 'vana@example.com', '0901234567', 'Số 10 Nguyễn Huệ, Quận 1, TP.HCM', 1),
        (2, 'Trần Thị B', 'thib@example.com', '0912345678', 'Số 25 Tràng Thi, Hoàn Kiếm, Hà Nội', 1);
    ");
    echo "[OK] Seeded 2 default customers.\n";
}

echo "--- Migration Completed Successfully ---\n";

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE DATABASE IF NOT EXISTS admin_product_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE admin_product_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    brand_id INT DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    sku VARCHAR(100) DEFAULT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    quantity INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    image_path VARCHAR(255) DEFAULT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_product_name (name),
    INDEX idx_product_category (category_id),
    INDEX idx_product_brand (brand_id)
);

CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS inventory_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    admin_id INT DEFAULT NULL,
    type ENUM('IN', 'OUT') NOT NULL,
    quantity INT NOT NULL,
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE,
    INDEX idx_inv_product (product_id)
);

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    ip_address VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_admin (admin_id)
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    token VARCHAR(100) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_token (token)
);

-- Seed Data
INSERT INTO admins (username, email, password_hash, full_name)
VALUES ('admin', 'admin@example.com', '$2y$10$p3F7IZ859DPL7TA7nl0X8OFNZ6pbLoajtQ7YEJA5oj4PBnDkIFe1m', 'System Administrator')
ON DUPLICATE KEY UPDATE username=username;

INSERT INTO categories (id, name, description, status) VALUES
(1, 'Laptop', 'Các dòng máy tính xách tay', 1),
(2, 'Điện thoại', 'Các dòng điện thoại thông minh', 1),
(3, 'Phụ kiện', 'Chuột, bàn phím, tai nghe, sạc', 1)
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO brands (id, name, description, status) VALUES
(1, 'Dell', 'Thương hiệu máy tính xách tay và máy trạm từ Mỹ', 1),
(2, 'Apple', 'Thiết bị công nghệ cao cấp iPhone, iPad, Mac', 1),
(3, 'Logitech', 'Phụ kiện máy tính, bàn phím chuột hàng đầu Thụy Sĩ', 1),
(4, 'Samsung', 'Tập đoàn điện tử công nghệ đa quốc gia Hàn Quốc', 1)
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO products (id, category_id, brand_id, name, sku, description, quantity, unit_price, image_path, status) VALUES
(1, 1, 1, 'Laptop Dell Inspiron 15', 'LAP-DELL-001', 'Laptop văn phòng cơ bản', 10, 15500000, NULL, 1),
(2, 2, 2, 'iPhone Demo 128GB', 'PHONE-IP-001', 'Sản phẩm demo', 5, 21900000, NULL, 1),
(3, 3, 3, 'Chuột không dây Logitech', 'ACC-MOUSE-001', 'Chuột không dây dùng cho văn phòng', 30, 350000, NULL, 1)
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO customers (id, name, email, phone, address, status) VALUES
(1, 'Nguyễn Văn A', 'vana@example.com', '0901234567', 'Số 10 Nguyễn Huệ, Quận 1, TP.HCM', 1),
(2, 'Trần Thị B', 'thib@example.com', '0912345678', 'Số 25 Tràng Thi, Hoàn Kiếm, Hà Nội', 1)
ON DUPLICATE KEY UPDATE email=email;

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

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
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
    INDEX idx_product_name (name),
    INDEX idx_product_category (category_id)
);

INSERT INTO admins (username, email, password_hash, full_name)
VALUES ('admin', 'admin@example.com', '$2y$10$p3F7IZ859DPL7TA7nl0X8OFNZ6pbLoajtQ7YEJA5oj4PBnDkIFe1m', 'System Administrator');

INSERT INTO categories (name, description, status) VALUES
('Laptop', 'Các dòng máy tính xách tay', 1),
('Điện thoại', 'Các dòng điện thoại thông minh', 1),
('Phụ kiện', 'Chuột, bàn phím, tai nghe, sạc', 1);

INSERT INTO products (category_id, name, sku, description, quantity, unit_price, image_path, status) VALUES
(1, 'Laptop Dell Inspiron 15', 'LAP-DELL-001', 'Laptop văn phòng cơ bản', 10, 15500000, NULL, 1),
(2, 'iPhone Demo 128GB', 'PHONE-IP-001', 'Sản phẩm demo', 5, 21900000, NULL, 1),
(3, 'Chuột không dây Logitech', 'ACC-MOUSE-001', 'Chuột không dây dùng cho văn phòng', 30, 350000, NULL, 1);

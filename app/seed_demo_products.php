<?php
require_once __DIR__ . "/src/config.php";
require_once __DIR__ . "/src/db.php";
require_once __DIR__ . "/src/product_repository.php";

$prods = [
    [
        "category_id" => 3,
        "name" => "Chuột Gaming Logitech G102",
        "sku" => "GAM-MOUSE-002",
        "description" => "Chuột có dây LED RGB cho game thủ",
        "quantity" => 15,
        "unit_price" => 450000,
        "image_path" => null,
        "status" => 1
    ],
    [
        "category_id" => 3,
        "name" => "Bàn phím cơ DareU EK87",
        "sku" => "KEY-DAREU-001",
        "description" => "Bàn phím cơ Tenkeyless switch Blue",
        "quantity" => 20,
        "unit_price" => 690000,
        "image_path" => null,
        "status" => 1
    ],
    [
        "category_id" => 1,
        "name" => "Laptop ASUS Zenbook 14",
        "sku" => "LAP-ASUS-001",
        "description" => "Laptop mỏng nhẹ màn hình OLED cao cấp",
        "quantity" => 8,
        "unit_price" => 24500000,
        "image_path" => null,
        "status" => 1
    ],
    [
        "category_id" => 2,
        "name" => "Samsung Galaxy S24 Ultra",
        "sku" => "PHONE-SS-001",
        "description" => "Flagship màn hình 120Hz hỗ trợ bút S-Pen",
        "quantity" => 6,
        "unit_price" => 28900000,
        "image_path" => null,
        "status" => 1
    ],
];

foreach ($prods as $p) {
    if (!product_exists_sku($p["sku"])) {
        product_create($p);
        echo "Created: {$p['name']}\n";
    }
}
echo "Total products in DB: " . product_count() . "\n";

<?php
require_once __DIR__ . "/src/config.php";
require_once __DIR__ . "/src/db.php";

$db = db();
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// Fix categories
$categories = [
    1 => [
        "name" => "Laptop",
        "description" => "Các dòng máy tính xách tay"
    ],
    2 => [
        "name" => "Điện thoại",
        "description" => "Các dòng điện thoại thông minh"
    ],
    3 => [
        "name" => "Phụ kiện",
        "description" => "Chuột, bàn phím, tai nghe, sạc"
    ]
];

$stmtCat = $db->prepare("UPDATE categories SET name = :name, description = :description WHERE id = :id");
foreach ($categories as $id => $cat) {
    $stmtCat->execute([
        "name" => $cat["name"],
        "description" => $cat["description"],
        "id" => $id
    ]);
}

// Fix products
$products = [
    1 => [
        "name" => "Laptop Dell Inspiron 15",
        "description" => "Laptop văn phòng cơ bản"
    ],
    2 => [
        "name" => "iPhone Demo 128GB",
        "description" => "Sản phẩm demo"
    ],
    3 => [
        "name" => "Chuột không dây Logitech",
        "description" => "Chuột không dây dùng cho văn phòng"
    ]
];

$stmtProd = $db->prepare("UPDATE products SET name = :name, description = :description WHERE id = :id");
foreach ($products as $id => $prod) {
    $stmtProd->execute([
        "name" => $prod["name"],
        "description" => $prod["description"],
        "id" => $id
    ]);
}

echo "UTF-8 Data Fix Completed Successfully!\n";
$rowsCat = $db->query("SELECT id, name, description FROM categories ORDER BY id ASC")->fetchAll();
foreach ($rowsCat as $r) {
    echo "Category [{$r['id']}]: {$r['name']} - {$r['description']}\n";
}
$rowsProd = $db->query("SELECT id, name, description FROM products ORDER BY id ASC")->fetchAll();
foreach ($rowsProd as $r) {
    echo "Product [{$r['id']}]: {$r['name']} - {$r['description']}\n";
}

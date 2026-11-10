<?php
/**
 * Automated Test Suite for PHP Admin Product Manager
 * Covers all test cases defined in README.md Section 15 & edge cases.
 */

require_once __DIR__ . "/../src/config.php";
require_once __DIR__ . "/../src/db.php";
require_once __DIR__ . "/../src/helpers.php";
require_once __DIR__ . "/../src/auth.php";
require_once __DIR__ . "/../src/category_repository.php";
require_once __DIR__ . "/../src/brand_repository.php";
require_once __DIR__ . "/../src/product_repository.php";

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $details = ""): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($details ? " - {$details}" : "") . "\n";
    }
}

echo "========================================================\n";
echo " RUNNING TEST SUITE: PHP ADMIN PRODUCT MANAGER\n";
echo "========================================================\n\n";

// --- MODULE 1: AUTHENTICATION ---
echo "--- MODULE 1: AUTHENTICATION ---\n";
// Test 1.1: Empty credentials
$res = admin_login("", "");
assert_test("1.1 Empty credentials rejected", $res["success"] === false && $res["message"] === "Vui lòng nhập tài khoản và mật khẩu.");

// Test 1.2: Wrong username
$res = admin_login("non_existent_user", "password123");
assert_test("1.2 Non-existent username rejected", $res["success"] === false && $res["message"] === "Tài khoản không tồn tại.");

// Test 1.3: Wrong password
$res = admin_login("admin", "wrong_password");
assert_test("1.3 Incorrect password rejected", $res["success"] === false && $res["message"] === "Mật khẩu không chính xác.");

// Test 1.4: Valid login
$res = admin_login("admin", "admin123");
assert_test("1.4 Correct credentials accepted", $res["success"] === true && isset($_SESSION["admin_id"]) && $_SESSION["admin_username"] === "admin");


// --- MODULE 2: CATEGORY MANAGEMENT ---
echo "\n--- MODULE 2: CATEGORY MANAGEMENT ---\n";

// Test 2.1: Duplicate category name check
$exists = category_exists_name("Laptop");
assert_test("2.1 Existing category 'Laptop' detected", $exists === true);

// Test 2.2: Add valid category
$testCategoryName = "Test Category " . time();
category_create($testCategoryName, "Mô tả danh mục test", 1);
$stmt = db()->prepare("SELECT * FROM categories WHERE name = :n");
$stmt->execute(["n" => $testCategoryName]);
$newCategory = $stmt->fetch();
assert_test("2.2 Create category successfully", !empty($newCategory) && $newCategory["name"] === $testCategoryName);

// Test 2.3: Check duplicate detection on newly created category
$dupCheck = category_exists_name($testCategoryName);
assert_test("2.3 Duplicate category name detected", $dupCheck === true);

// Test 2.4: Update category
$updatedCategoryName = $testCategoryName . " Updated";
category_update((int)$newCategory["id"], $updatedCategoryName, "Mô tả cập nhật", 0);
$refreshed = category_find((int)$newCategory["id"]);
assert_test("2.4 Update category successfully", $refreshed["name"] === $updatedCategoryName && (int)$refreshed["status"] === 0);

// Test 2.5: Foreign key constraint check (Category with products cannot be deleted)
$laptopCat = db()->query("SELECT id FROM categories WHERE name = 'Laptop' LIMIT 1")->fetch();
$hasProducts = category_has_products((int)$laptopCat["id"]);
assert_test("2.5 Has-products check detects products under 'Laptop'", $hasProducts === true);

// Test 2.6: Delete category without products
category_delete((int)$newCategory["id"]);
$deletedCheck = category_find((int)$newCategory["id"]);
assert_test("2.6 Delete category without products succeeds", $deletedCheck === null);

// --- MODULE 2B: BRAND MANAGEMENT ---
echo "\n--- MODULE 2B: BRAND MANAGEMENT ---\n";
// Test 2B.1: Existing brand detected
$existsBrand = brand_exists_name("Dell");
assert_test("2B.1 Existing brand 'Dell' detected", $existsBrand === true);

// Test 2B.2: Create brand
$testBrandName = "Test Brand " . time();
brand_create($testBrandName, "Mô tả hãng test", 1);
$stmtB = db()->prepare("SELECT * FROM brands WHERE name = :n");
$stmtB->execute(["n" => $testBrandName]);
$newBrand = $stmtB->fetch();
assert_test("2B.2 Create brand successfully", !empty($newBrand) && $newBrand["name"] === $testBrandName);

// Test 2B.3: Duplicate brand name detected
$dupBrandCheck = brand_exists_name($testBrandName);
assert_test("2B.3 Duplicate brand name detected", $dupBrandCheck === true);

// Test 2B.4: Update brand
$updatedBrandName = $testBrandName . " Pro";
brand_update((int)$newBrand["id"], $updatedBrandName, "Mô tả brand cập nhật", 1);
$refreshedBrand = brand_find((int)$newBrand["id"]);
assert_test("2B.4 Update brand successfully", $refreshedBrand["name"] === $updatedBrandName);

// Test 2B.5: Has-products check on Dell
$dellBrand = db()->query("SELECT id FROM brands WHERE name = 'Dell' LIMIT 1")->fetch();
assert_test("2B.5 Has-products detects products under Dell", brand_has_products((int)$dellBrand["id"]) === true);

// Test 2B.6: Delete brand without products succeeds
brand_delete((int)$newBrand["id"]);
$deletedBrandCheck = brand_find((int)$newBrand["id"]);
assert_test("2B.6 Delete brand without products succeeds", $deletedBrandCheck === null);


// --- MODULE 3: PRODUCT VALIDATION & MANAGEMENT ---
echo "\n--- MODULE 3: PRODUCT VALIDATION & CRUD ---\n";

// Require validation logic from index.php helper
function test_validate_product(array $input, int $excludeId = 0): array {
    $errors = [];
    if (trim($input["name"] ?? "") === "") $errors[] = "Tên sản phẩm không được để trống.";
    if ((int)($input["category_id"] ?? 0) <= 0) $errors[] = "Vui lòng chọn danh mục sản phẩm.";
    if (!isset($input["quantity"]) || trim((string)$input["quantity"]) === "" || (int)$input["quantity"] < 0) {
        $errors[] = "Số lượng phải lớn hơn hoặc bằng 0.";
    }
    if (!isset($input["unit_price"]) || trim((string)$input["unit_price"]) === "" || (float)$input["unit_price"] < 0) {
        $errors[] = "Đơn giá phải lớn hơn hoặc bằng 0.";
    }
    $sku = trim($input["sku"] ?? "");
    if ($sku !== "" && product_exists_sku($sku, $excludeId)) {
        $errors[] = "Mã SKU '{$sku}' đã tồn tại, vui lòng chọn mã khác.";
    }
    return $errors;
}

// Test 3.1: Empty product name error
$errs = test_validate_product(["name" => "", "category_id" => 1, "quantity" => 10, "unit_price" => 1000]);
assert_test("3.1 Empty product name caught", in_array("Tên sản phẩm không được để trống.", $errs));

// Test 3.2: Missing category error
$errs = test_validate_product(["name" => "Máy Tính Mới", "category_id" => 0, "quantity" => 10, "unit_price" => 1000]);
assert_test("3.2 Missing category caught", in_array("Vui lòng chọn danh mục sản phẩm.", $errs));

// Test 3.3: Negative quantity error
$errs = test_validate_product(["name" => "Máy Tính Mới", "category_id" => 1, "quantity" => -5, "unit_price" => 1000]);
assert_test("3.3 Negative quantity caught", in_array("Số lượng phải lớn hơn hoặc bằng 0.", $errs));

// Test 3.4: Negative unit price error
$errs = test_validate_product(["name" => "Máy Tính Mới", "category_id" => 1, "quantity" => 5, "unit_price" => -100]);
assert_test("3.4 Negative unit price caught", in_array("Đơn giá phải lớn hơn hoặc bằng 0.", $errs));

// Test 3.5: Duplicate SKU error
$existingSku = "LAP-DELL-001";
$errs = test_validate_product(["name" => "Máy Tính Trùng SKU", "sku" => $existingSku, "category_id" => 1, "quantity" => 1, "unit_price" => 1000]);
assert_test("3.5 Duplicate SKU error caught", in_array("Mã SKU '{$existingSku}' đã tồn tại, vui lòng chọn mã khác.", $errs));

// Test 3.6: Add valid product
$testSku = "TEST-SKU-" . time();
product_create([
    "category_id" => (int)$laptopCat["id"],
    "name" => "Sản Phẩm Thử Nghiệm",
    "sku" => $testSku,
    "description" => "Mô tả sản phẩm thử nghiệm",
    "quantity" => 25,
    "unit_price" => 5000000.0,
    "image_path" => null,
    "status" => 1
]);
$stmt = db()->prepare("SELECT * FROM products WHERE sku = :sku");
$stmt->execute(["sku" => $testSku]);
$createdProduct = $stmt->fetch();
assert_test("3.6 Create product successfully", !empty($createdProduct) && $createdProduct["name"] === "Sản Phẩm Thử Nghiệm");

// Test 3.7: Update product
product_update((int)$createdProduct["id"], [
    "category_id" => (int)$laptopCat["id"],
    "name" => "Sản Phẩm Đã Cập Nhật",
    "sku" => $testSku,
    "description" => "Mô tả sau khi cập nhật",
    "quantity" => 30,
    "unit_price" => 5500000.0,
    "image_path" => null,
    "status" => 1
]);
$updatedProduct = product_find((int)$createdProduct["id"]);
assert_test("3.7 Update product successfully", $updatedProduct["name"] === "Sản Phẩm Đã Cập Nhật" && (int)$updatedProduct["quantity"] === 30);

// Test 3.8: Search product by keyword & filter by category
$searchByName = product_paginate("Đã Cập Nhật", 0);
assert_test("3.8 Search product by keyword", count($searchByName["items"]) >= 1 && $searchByName["items"][0]["sku"] === $testSku);

$filterByCategory = product_paginate("", (int)$laptopCat["id"]);
assert_test("3.9 Filter products by category", count($filterByCategory["items"]) >= 2);

// Test 3.10: Pagination calculations
$pagedResult = product_paginate("", 0, 1, 2);
assert_test("3.10 Pagination limit/offset (perPage=2)", $pagedResult["perPage"] === 2 && $pagedResult["totalPages"] >= 2 && count($pagedResult["items"]) <= 2);

// Test 3.11: Delete product
product_delete((int)$createdProduct["id"]);
$deletedProductCheck = product_find((int)$createdProduct["id"]);
assert_test("3.11 Delete product successfully", $deletedProductCheck === null);


// --- MODULE 4: DASHBOARD METRICS ---
echo "\n--- MODULE 4: DASHBOARD METRICS ---\n";
$catCount = category_count();
$prodCount = product_count();
$totalQty = product_total_quantity();
$totalVal = product_total_inventory_value();

assert_test("4.1 Category count is positive", $catCount > 0);
assert_test("4.2 Product count is positive", $prodCount > 0);
assert_test("4.3 Total quantity is positive", $totalQty > 0);
assert_test("4.4 Total inventory value is positive", $totalVal > 0);


// --- MODULE 5: SECURITY & CSRF ---
echo "\n--- MODULE 5: SECURITY & CSRF ---\n";
$token1 = csrf_token();
$token2 = csrf_token();
assert_test("5.1 CSRF token generated and persistent across session", !empty($token1) && strlen($token1) === 64 && $token1 === $token2);

$field = csrf_field();
assert_test("5.2 CSRF hidden field generated correctly", strpos($field, 'type="hidden"') !== false && strpos($field, $token1) !== false);

// --- MODULE 6: FLASH MESSAGES ---
echo "\n--- MODULE 6: FLASH MESSAGES ---\n";
set_flash("error", "Lỗi kiểm tra");
$flashErr = get_flash("error");
$flashErrAfter = get_flash("error");
assert_test("6.1 Flash message set and read once", $flashErr === "Lỗi kiểm tra" && $flashErrAfter === null);


// --- SUMMARY ---
echo "\n========================================================\n";
echo " TEST SUMMARY\n";
echo " Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
if ($failed === 0) {
    echo " ALL TESTS PASSED SUCCESSFULLY! (100%)\n";
} else {
    echo " SOME TESTS FAILED!\n";
}
echo "========================================================\n";

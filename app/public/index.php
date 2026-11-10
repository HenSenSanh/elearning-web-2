<?php
require_once __DIR__ . "/../src/auth.php";
require_once __DIR__ . "/../src/category_repository.php";
require_once __DIR__ . "/../src/product_repository.php";

$path = rtrim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
$path = $path === "" ? "/" : $path;

function view(string $view, array $data = []): void {
    extract($data);
    require __DIR__ . "/../views/layouts/header.php";
    require __DIR__ . "/../views/" . $view . ".php";
    require __DIR__ . "/../views/layouts/footer.php";
}
function validate_product_input(array $input, int $excludeId = 0): array {
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

try {
    switch ($path) {
        case "/":
            require_admin(); redirect("/dashboard"); break;

        case "/login":
            if (is_post()) {
                verify_csrf();
                $result = admin_login(trim($_POST["username"] ?? ""), $_POST["password"] ?? "");
                if ($result["success"]) redirect("/dashboard");
                view("auth/login", ["title"=>"Đăng nhập Admin","error"=>$result["message"]]);
            } else {
                view("auth/login", ["title"=>"Đăng nhập Admin","error"=>""]);
            }
            break;

        case "/logout":
            admin_logout(); break;

        case "/dashboard":
            require_admin();
            view("dashboard/index", [
                "title"=>"Dashboard",
                "productCount"=>product_count(),
                "categoryCount"=>category_count(),
                "totalQuantity"=>product_total_quantity(),
                "totalValue"=>product_total_inventory_value()
            ]);
            break;

        case "/categories":
            require_admin();
            view("categories/index", ["title"=>"Quản lý danh mục","categories"=>category_all()]);
            break;

        case "/categories/create":
            require_admin();
            if (is_post()) {
                verify_csrf();
                $name = trim($_POST["name"] ?? "");
                $description = trim($_POST["description"] ?? "");
                $status = isset($_POST["status"]) ? 1 : 0;
                $error = "";
                if ($name === "") {
                    $error = "Tên danh mục không được để trống.";
                } elseif (category_exists_name($name)) {
                    $error = "Tên danh mục đã tồn tại, vui lòng chọn tên khác.";
                }
                if ($error !== "") {
                    view("categories/create", [
                        "title" => "Thêm danh mục",
                        "error" => $error,
                        "category" => ["name" => $name, "description" => $description, "status" => $status]
                    ]);
                    break;
                }
                category_create($name, $description, $status);
                set_flash("success", "Thêm danh mục thành công.");
                redirect("/categories");
            }
            view("categories/create", ["title"=>"Thêm danh mục","error"=>""]);
            break;

        case "/categories/edit":
            require_admin();
            $id = (int)($_GET["id"] ?? 0);
            $category = category_find($id);
            if (!$category) { http_response_code(404); die("Không tìm thấy danh mục."); }
            if (is_post()) {
                verify_csrf();
                $name = trim($_POST["name"] ?? "");
                $description = trim($_POST["description"] ?? "");
                $status = isset($_POST["status"]) ? 1 : 0;
                $error = "";
                if ($name === "") {
                    $error = "Tên danh mục không được để trống.";
                } elseif (category_exists_name($name, $id)) {
                    $error = "Tên danh mục đã tồn tại, vui lòng chọn tên khác.";
                }
                if ($error !== "") {
                    view("categories/edit", [
                        "title" => "Sửa danh mục",
                        "category" => ["id" => $id, "name" => $name, "description" => $description, "status" => $status],
                        "error" => $error
                    ]);
                    break;
                }
                category_update($id, $name, $description, $status);
                set_flash("success", "Cập nhật danh mục thành công.");
                redirect("/categories");
            }
            view("categories/edit", ["title"=>"Sửa danh mục","category"=>$category,"error"=>""]);
            break;

        case "/categories/delete":
            require_admin(); verify_csrf();
            $id = (int)($_POST["id"] ?? 0);
            if (category_has_products($id)) {
                set_flash("error", "Không thể xóa danh mục này vì vẫn còn sản phẩm thuộc danh mục.");
            } else {
                try {
                    category_delete($id);
                    set_flash("success", "Xóa danh mục thành công.");
                } catch (PDOException $e) {
                    set_flash("error", "Lỗi khi xóa danh mục: Ràng buộc cơ sở dữ liệu.");
                }
            }
            redirect("/categories");
            break;

        case "/products":
            require_admin();
            $keyword = trim($_GET["keyword"] ?? "");
            $categoryId = (int)($_GET["category_id"] ?? 0);
            $page = max(1, (int)($_GET["page"] ?? 1));
            $perPage = 5;
            $pagination = product_paginate($keyword, $categoryId, $page, $perPage);
            view("products/index", [
                "title" => "Quản lý sản phẩm",
                "products" => $pagination["items"],
                "categories" => category_all(),
                "keyword" => $keyword,
                "categoryId" => $categoryId,
                "pagination" => $pagination,
            ]);
            break;

        case "/products/create":
            require_admin();
            if (is_post()) {
                verify_csrf();
                $errors = validate_product_input($_POST);
                $imagePath = null;
                try {
                    $imagePath = upload_product_image($_FILES["image"] ?? []);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
                if (!empty($errors)) {
                    view("products/create", [
                        "title" => "Thêm sản phẩm",
                        "categories" => category_active_all(),
                        "errors" => $errors,
                        "old" => $_POST
                    ]);
                    break;
                }
                product_create([
                    "category_id"=>(int)$_POST["category_id"],
                    "name"=>trim($_POST["name"]),
                    "sku"=>trim($_POST["sku"] ?? "") ?: null,
                    "description"=>trim($_POST["description"] ?? ""),
                    "quantity"=>(int)$_POST["quantity"],
                    "unit_price"=>(float)$_POST["unit_price"],
                    "image_path"=>$imagePath,
                    "status"=>isset($_POST["status"])?1:0
                ]);
                set_flash("success", "Thêm sản phẩm thành công.");
                redirect("/products");
            }
            view("products/create", ["title"=>"Thêm sản phẩm","categories"=>category_active_all(),"errors"=>[],"old"=>[]]);
            break;

        case "/products/edit":
            require_admin();
            $id=(int)($_GET["id"] ?? 0); $product=product_find($id);
            if (!$product) { http_response_code(404); die("Không tìm thấy sản phẩm."); }
            if (is_post()) {
                verify_csrf();
                $errors = validate_product_input($_POST, $id);
                $imagePath = $product["image_path"];
                try {
                    $newImage = upload_product_image($_FILES["image"] ?? []);
                    if ($newImage) {
                        delete_product_image($product["image_path"]);
                        $imagePath = $newImage;
                    }
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
                if (!empty($errors)) {
                    $mergedProduct = array_merge($product, $_POST);
                    view("products/edit", [
                        "title" => "Sửa sản phẩm",
                        "categories" => category_active_all(),
                        "product" => $mergedProduct,
                        "errors" => $errors
                    ]);
                    break;
                }
                product_update($id, [
                    "category_id"=>(int)$_POST["category_id"],
                    "name"=>trim($_POST["name"]),
                    "sku"=>trim($_POST["sku"] ?? "") ?: null,
                    "description"=>trim($_POST["description"] ?? ""),
                    "quantity"=>(int)$_POST["quantity"],
                    "unit_price"=>(float)$_POST["unit_price"],
                    "image_path"=>$imagePath,
                    "status"=>isset($_POST["status"])?1:0
                ]);
                set_flash("success", "Cập nhật sản phẩm thành công.");
                redirect("/products");
            }
            view("products/edit", ["title"=>"Sửa sản phẩm","categories"=>category_active_all(),"product"=>$product,"errors"=>[]]);
            break;

        case "/products/delete":
            require_admin(); verify_csrf();
            $product=product_find((int)($_POST["id"] ?? 0));
            if ($product) {
                delete_product_image($product["image_path"]);
                product_delete((int)$product["id"]);
                set_flash("success", "Xóa sản phẩm thành công.");
            }
            redirect("/products"); break;

        default:
            http_response_code(404); echo "404 - Không tìm thấy trang.";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h2>Lỗi hệ thống</h2><pre>" . e($e->getMessage()) . "</pre>";
}

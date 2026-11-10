<?php
require_once __DIR__ . "/../src/auth.php";
require_once __DIR__ . "/../src/category_repository.php";
require_once __DIR__ . "/../src/brand_repository.php";
require_once __DIR__ . "/../src/inventory_repository.php";
require_once __DIR__ . "/../src/customer_repository.php";
require_once __DIR__ . "/../src/order_repository.php";
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

        case "/brands":
            require_admin();
            view("brands/index", ["title"=>"Quản lý thương hiệu","brands"=>brand_all()]);
            break;

        case "/brands/create":
            require_admin();
            if (is_post()) {
                verify_csrf();
                $name = trim($_POST["name"] ?? "");
                $description = trim($_POST["description"] ?? "");
                $status = isset($_POST["status"]) ? 1 : 0;
                $error = "";
                if ($name === "") {
                    $error = "Tên thương hiệu không được để trống.";
                } elseif (brand_exists_name($name)) {
                    $error = "Tên thương hiệu đã tồn tại, vui lòng chọn tên khác.";
                }
                if ($error !== "") {
                    view("brands/create", [
                        "title" => "Thêm thương hiệu",
                        "error" => $error,
                        "brand" => ["name" => $name, "description" => $description, "status" => $status]
                    ]);
                    break;
                }
                brand_create($name, $description, $status);
                set_flash("success", "Thêm thương hiệu thành công.");
                redirect("/brands");
            }
            view("brands/create", ["title"=>"Thêm thương hiệu","error"=>""]);
            break;

        case "/brands/edit":
            require_admin();
            $id = (int)($_GET["id"] ?? 0);
            $brand = brand_find($id);
            if (!$brand) { http_response_code(404); die("Không tìm thấy thương hiệu."); }
            if (is_post()) {
                verify_csrf();
                $name = trim($_POST["name"] ?? "");
                $description = trim($_POST["description"] ?? "");
                $status = isset($_POST["status"]) ? 1 : 0;
                $error = "";
                if ($name === "") {
                    $error = "Tên thương hiệu không được để trống.";
                } elseif (brand_exists_name($name, $id)) {
                    $error = "Tên thương hiệu đã tồn tại, vui lòng chọn tên khác.";
                }
                if ($error !== "") {
                    view("brands/edit", [
                        "title" => "Sửa thương hiệu",
                        "brand" => ["id" => $id, "name" => $name, "description" => $description, "status" => $status],
                        "error" => $error
                    ]);
                    break;
                }
                brand_update($id, $name, $description, $status);
                set_flash("success", "Cập nhật thương hiệu thành công.");
                redirect("/brands");
            }
            view("brands/edit", ["title"=>"Sửa thương hiệu","brand"=>$brand,"error"=>""]);
            break;

        case "/brands/delete":
            require_admin(); verify_csrf();
            $id = (int)($_POST["id"] ?? 0);
            if (brand_has_products($id)) {
                set_flash("error", "Không thể xóa thương hiệu này vì vẫn còn sản phẩm thuộc thương hiệu.");
            } else {
                try {
                    brand_delete($id);
                    set_flash("success", "Xóa thương hiệu thành công.");
                } catch (PDOException $e) {
                    set_flash("error", "Lỗi khi xóa thương hiệu: Ràng buộc cơ sở dữ liệu.");
                }
            }
            redirect("/brands");
            break;

        case "/inventory":
            require_admin();
            $page = max(1, (int)($_GET["page"] ?? 1));
            $pagination = inventory_log_paginate($page, 10);
            view("inventory/index", [
                "title" => "Quản lý Nhập / Xuất kho",
                "logs" => $pagination["items"],
                "pagination" => $pagination,
                "totalIn" => inventory_total_in(),
                "totalOut" => inventory_total_out()
            ]);
            break;

        case "/inventory/create":
            require_admin();
            if (is_post()) {
                verify_csrf();
                $productId = (int)($_POST["product_id"] ?? 0);
                $type = trim($_POST["type"] ?? "IN");
                $quantity = (int)($_POST["quantity"] ?? 0);
                $note = trim($_POST["note"] ?? "");
                $error = "";
                if ($productId <= 0) $error = "Vui lòng chọn sản phẩm.";
                elseif ($quantity <= 0) $error = "Số lượng phải lớn hơn 0.";
                if ($error === "") {
                    try {
                        inventory_log_create($productId, $_SESSION["admin_id"], $type, $quantity, $note);
                        set_flash("success", "Tạo phiếu " . ($type === "IN" ? "nhập kho" : "xuất kho") . " thành công.");
                        redirect("/inventory");
                    } catch (Throwable $e) {
                        $error = $e->getMessage();
                    }
                }
                $products = db()->query("SELECT id, name, quantity FROM products ORDER BY name ASC")->fetchAll();
                view("inventory/create", [
                    "title" => "Tạo phiếu Nhập / Xuất kho",
                    "products" => $products,
                    "error" => $error,
                    "old" => $_POST
                ]);
                break;
            }
            $products = db()->query("SELECT id, name, quantity FROM products ORDER BY name ASC")->fetchAll();
            break;

        case "/customers":
            require_admin();
            $keyword = trim($_GET["keyword"] ?? "");
            $page = max(1, (int)($_GET["page"] ?? 1));
            $pagination = customer_paginate($keyword, $page, 10);
            view("customers/index", [
                "title" => "Quản lý khách hàng",
                "customers" => $pagination["items"],
                "keyword" => $keyword,
                "pagination" => $pagination
            ]);
            break;

        case "/customers/create":
            require_admin();
            if (is_post()) {
                verify_csrf();
                $name = trim($_POST["name"] ?? "");
                $email = trim($_POST["email"] ?? "");
                $phone = trim($_POST["phone"] ?? "");
                $address = trim($_POST["address"] ?? "");
                $status = isset($_POST["status"]) ? 1 : 0;
                $error = "";
                if ($name === "") $error = "Họ tên khách hàng không được để trống.";
                elseif ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = "Email không hợp lệ.";
                elseif (customer_exists_email($email)) $error = "Email này đã được sử dụng.";
                if ($error !== "") {
                    view("customers/create", [
                        "title" => "Thêm khách hàng",
                        "error" => $error,
                        "old" => $_POST
                    ]);
                    break;
                }
                customer_create([
                    "name" => $name,
                    "email" => $email,
                    "phone" => $phone,
                    "address" => $address,
                    "status" => $status
                ]);
                set_flash("success", "Thêm khách hàng thành công.");
                redirect("/customers");
            }
            view("customers/create", ["title" => "Thêm khách hàng", "error" => "", "old" => []]);
            break;

        case "/customers/edit":
            require_admin();
            $id = (int)($_GET["id"] ?? 0);
            $customer = customer_find($id);
            if (!$customer) { http_response_code(404); die("Không tìm thấy khách hàng."); }
            if (is_post()) {
                verify_csrf();
                $name = trim($_POST["name"] ?? "");
                $email = trim($_POST["email"] ?? "");
                $phone = trim($_POST["phone"] ?? "");
                $address = trim($_POST["address"] ?? "");
                $status = isset($_POST["status"]) ? 1 : 0;
                $error = "";
                if ($name === "") $error = "Họ tên khách hàng không được để trống.";
                elseif ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = "Email không hợp lệ.";
                elseif (customer_exists_email($email, $id)) $error = "Email này đã được khách hàng khác sử dụng.";
                if ($error !== "") {
                    view("customers/edit", [
                        "title" => "Sửa khách hàng",
                        "error" => $error,
                        "customer" => array_merge($customer, $_POST)
                    ]);
                    break;
                }
                customer_update($id, [
                    "name" => $name,
                    "email" => $email,
                    "phone" => $phone,
                    "address" => $address,
                    "status" => $status
                ]);
                set_flash("success", "Cập nhật khách hàng thành công.");
                redirect("/customers");
            }
            view("customers/edit", ["title" => "Sửa khách hàng", "customer" => $customer, "error" => ""]);
            break;

        case "/customers/delete":
            require_admin(); verify_csrf();
            $id = (int)($_POST["id"] ?? 0);
            try {
                customer_delete($id);
                set_flash("success", "Xóa khách hàng thành công.");
            } catch (PDOException $e) {
                set_flash("error", "Không thể xóa khách hàng do có đơn hàng liên kết.");
            }
            redirect("/customers");
            break;

        case "/orders":
            require_admin();
            $status = trim($_GET["status"] ?? "");
            $keyword = trim($_GET["keyword"] ?? "");
            $page = max(1, (int)($_GET["page"] ?? 1));
            $pagination = order_paginate($status, $keyword, $page, 10);
            view("orders/index", [
                "title" => "Quản lý đơn hàng",
                "orders" => $pagination["items"],
                "status" => $status,
                "keyword" => $keyword,
                "pagination" => $pagination
            ]);
            break;

        case "/orders/create":
            require_admin();
            if (is_post()) {
                verify_csrf();
                $customerId = (int)($_POST["customer_id"] ?? 0);
                $notes = trim($_POST["notes"] ?? "");
                $rawItems = $_POST["items"] ?? [];
                $items = [];
                foreach ($rawItems as $raw) {
                    $pid = (int)($raw["product_id"] ?? 0);
                    $qty = (int)($raw["quantity"] ?? 0);
                    if ($pid > 0 && $qty > 0) {
                        $items[] = ["product_id" => $pid, "quantity" => $qty];
                    }
                }

                $error = "";
                if ($customerId <= 0) $error = "Vui lòng chọn khách hàng đặt mua.";
                elseif (empty($items)) $error = "Vui lòng chọn ít nhất một sản phẩm hợp lệ.";

                if ($error === "") {
                    try {
                        $orderId = order_create($customerId, $items, $notes);
                        set_flash("success", "Tạo đơn hàng thành công.");
                        redirect("/orders/detail?id=" . $orderId);
                    } catch (Throwable $e) {
                        $error = $e->getMessage();
                    }
                }
                $customers = customer_active_all();
                $products = db()->query("SELECT id, name, unit_price, quantity FROM products WHERE status = 1 ORDER BY name ASC")->fetchAll();
                view("orders/create", [
                    "title" => "Tạo đơn hàng mới",
                    "customers" => $customers,
                    "products" => $products,
                    "error" => $error,
                    "old" => $_POST
                ]);
                break;
            }
            $customers = customer_active_all();
            $products = db()->query("SELECT id, name, unit_price, quantity FROM products WHERE status = 1 ORDER BY name ASC")->fetchAll();
            view("orders/create", [
                "title" => "Tạo đơn hàng mới",
                "customers" => $customers,
                "products" => $products,
                "error" => "",
                "old" => []
            ]);
            break;

        case "/orders/detail":
            require_admin();
            $id = (int)($_GET["id"] ?? 0);
            $order = order_find($id);
            if (!$order) { http_response_code(404); die("Không tìm thấy đơn hàng."); }
            $items = order_items_find($id);
            view("orders/detail", [
                "title" => "Chi tiết đơn hàng " . $order["order_code"],
                "order" => $order,
                "items" => $items
            ]);
            break;

        case "/orders/update-status":
            require_admin(); verify_csrf();
            $id = (int)($_POST["id"] ?? 0);
            $newStatus = trim($_POST["status"] ?? "");
            try {
                order_update_status($id, $newStatus);
                set_flash("success", "Cập nhật trạng thái đơn hàng thành công.");
            } catch (Throwable $e) {
                set_flash("error", "Lỗi: " . $e->getMessage());
            }
            redirect("/orders/detail?id=" . $id);
            break;

        case "/products":
            require_admin();
            $keyword = trim($_GET["keyword"] ?? "");
            $categoryId = (int)($_GET["category_id"] ?? 0);
            $brandId = (int)($_GET["brand_id"] ?? 0);
            $page = max(1, (int)($_GET["page"] ?? 1));
            $perPage = 5;
            $pagination = product_paginate($keyword, $categoryId, $page, $perPage, $brandId);
            view("products/index", [
                "title" => "Quản lý sản phẩm",
                "products" => $pagination["items"],
                "categories" => category_all(),
                "brands" => brand_all(),
                "keyword" => $keyword,
                "categoryId" => $categoryId,
                "brandId" => $brandId,
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
                        "brands" => brand_active_all(),
                        "errors" => $errors,
                        "old" => $_POST
                    ]);
                    break;
                }
                $newProdId = product_create([
                    "category_id"=>(int)$_POST["category_id"],
                    "brand_id"=>((int)($_POST["brand_id"] ?? 0)) ?: null,
                    "name"=>trim($_POST["name"]),
                    "sku"=>trim($_POST["sku"] ?? "") ?: null,
                    "description"=>trim($_POST["description"] ?? ""),
                    "quantity"=>(int)$_POST["quantity"],
                    "unit_price"=>(float)$_POST["unit_price"],
                    "image_path"=>$imagePath,
                    "status"=>isset($_POST["status"])?1:0
                ]);
                if (!empty($_FILES["gallery_images"])) {
                    $galleryPaths = upload_product_gallery_images($_FILES["gallery_images"]);
                    foreach ($galleryPaths as $gPath) {
                        product_image_add($newProdId, $gPath);
                    }
                }
                set_flash("success", "Thêm sản phẩm thành công.");
                redirect("/products");
            }
            view("products/create", ["title"=>"Thêm sản phẩm","categories"=>category_active_all(),"brands"=>brand_active_all(),"errors"=>[],"old"=>[]]);
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
                        "brands" => brand_active_all(),
                        "product" => $mergedProduct,
                        "errors" => $errors
                    ]);
                    break;
                }
                product_update($id, [
                    "category_id"=>(int)$_POST["category_id"],
                    "brand_id"=>((int)($_POST["brand_id"] ?? 0)) ?: null,
                    "name"=>trim($_POST["name"]),
                    "sku"=>trim($_POST["sku"] ?? "") ?: null,
                    "description"=>trim($_POST["description"] ?? ""),
                    "quantity"=>(int)$_POST["quantity"],
                    "unit_price"=>(float)$_POST["unit_price"],
                    "image_path"=>$imagePath,
                    "status"=>isset($_POST["status"])?1:0
                ]);
                if (!empty($_FILES["gallery_images"])) {
                    $galleryPaths = upload_product_gallery_images($_FILES["gallery_images"]);
                    foreach ($galleryPaths as $gPath) {
                        product_image_add($id, $gPath);
                    }
                }
                set_flash("success", "Cập nhật sản phẩm thành công.");
                redirect("/products/edit?id=" . $id);
            }
            view("products/edit", [
                "title"=>"Sửa sản phẩm",
                "categories"=>category_active_all(),
                "brands"=>brand_active_all(),
                "product"=>$product,
                "galleryImages"=>product_images_find($id),
                "errors"=>[]
            ]);
            break;

        case "/products/delete-image":
            require_admin(); verify_csrf();
            $imgId = (int)($_POST["image_id"] ?? 0);
            $prodId = (int)($_POST["product_id"] ?? 0);
            if ($imgId > 0) {
                product_image_delete($imgId);
                set_flash("success", "Đã xóa hình ảnh khỏi bộ sưu tập.");
            }
            redirect("/products/edit?id=" . $prodId);
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

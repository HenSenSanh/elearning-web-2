<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/category_repository.php";
require_once __DIR__ . "/brand_repository.php";
require_once __DIR__ . "/product_repository.php";
require_once __DIR__ . "/customer_repository.php";
require_once __DIR__ . "/order_repository.php";

function send_json(mixed $data, int $statusCode = 200): void {
    if (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function execute_api_route(string $method, string $path, array $params = []): array {
    $method = strtoupper($method);
    $path = rtrim($path, "/");

    // Endpoint: /api/categories
    if ($path === "/api/categories") {
        if ($method === "GET") {
            return ["status" => 200, "data" => category_all()];
        }
        return ["status" => 405, "data" => ["error" => "Method Not Allowed"]];
    }

    // Endpoint: /api/brands
    if ($path === "/api/brands") {
        if ($method === "GET") {
            return ["status" => 200, "data" => brand_all()];
        }
        return ["status" => 405, "data" => ["error" => "Method Not Allowed"]];
    }

    // Endpoint: /api/products
    if ($path === "/api/products") {
        if ($method === "GET") {
            $id = (int) ($params["id"] ?? 0);
            if ($id > 0) {
                $product = product_find($id);
                if (!$product) {
                    return ["status" => 404, "data" => ["error" => "Không tìm thấy sản phẩm ID {$id}."]];
                }
                $product["gallery_images"] = product_images_find($id);
                return ["status" => 200, "data" => $product];
            }

            $keyword = trim($params["keyword"] ?? "");
            $categoryId = (int) ($params["category_id"] ?? 0);
            $brandId = (int) ($params["brand_id"] ?? 0);
            $page = max(1, (int) ($params["page"] ?? 1));
            $perPage = max(1, min(100, (int) ($params["per_page"] ?? 10)));

            $res = product_paginate($keyword, $categoryId, $page, $perPage, $brandId);
            return ["status" => 200, "data" => $res];
        }

        if ($method === "POST") {
            $name = trim($params["name"] ?? "");
            $catId = (int) ($params["category_id"] ?? 0);
            if ($name === "") {
                return ["status" => 400, "data" => ["error" => "Tên sản phẩm không được để trống."]];
            }
            if ($catId <= 0) {
                return ["status" => 400, "data" => ["error" => "Vui lòng chọn danh mục hợp lệ."]];
            }

            $sku = trim($params["sku"] ?? "");
            if ($sku !== "" && product_exists_sku($sku)) {
                return ["status" => 400, "data" => ["error" => "Mã SKU '{$sku}' đã tồn tại."]];
            }

            $prodId = product_create([
                "category_id" => $catId,
                "brand_id" => ((int) ($params["brand_id"] ?? 0)) ?: null,
                "name" => $name,
                "sku" => $sku ?: null,
                "description" => trim($params["description"] ?? ""),
                "quantity" => max(0, (int) ($params["quantity"] ?? 0)),
                "unit_price" => max(0.0, (float) ($params["unit_price"] ?? 0.0)),
                "image_path" => $params["image_path"] ?? null,
                "status" => isset($params["status"]) ? (int) $params["status"] : 1
            ]);

            return [
                "status" => 201,
                "data" => [
                    "success" => true,
                    "id" => $prodId,
                    "message" => "Tạo sản phẩm thành công qua API."
                ]
            ];
        }

        if ($method === "PUT") {
            $id = (int) ($params["id"] ?? 0);
            $product = product_find($id);
            if (!$product) {
                return ["status" => 404, "data" => ["error" => "Không tìm thấy sản phẩm để cập nhật."]];
            }

            $name = isset($params["name"]) ? trim($params["name"]) : $product["name"];
            $catId = isset($params["category_id"]) ? (int) $params["category_id"] : (int) $product["category_id"];
            $sku = isset($params["sku"]) ? trim($params["sku"]) : $product["sku"];

            if ($sku !== "" && product_exists_sku($sku, $id)) {
                return ["status" => 400, "data" => ["error" => "Mã SKU '{$sku}' đã tồn tại."]];
            }

            product_update($id, [
                "category_id" => $catId,
                "brand_id" => isset($params["brand_id"]) ? (((int) $params["brand_id"]) ?: null) : $product["brand_id"],
                "name" => $name,
                "sku" => $sku ?: null,
                "description" => isset($params["description"]) ? trim($params["description"]) : $product["description"],
                "quantity" => isset($params["quantity"]) ? max(0, (int) $params["quantity"]) : (int) $product["quantity"],
                "unit_price" => isset($params["unit_price"]) ? max(0.0, (float) $params["unit_price"]) : (float) $product["unit_price"],
                "image_path" => $params["image_path"] ?? $product["image_path"],
                "status" => isset($params["status"]) ? (int) $params["status"] : (int) $product["status"]
            ]);

            return ["status" => 200, "data" => ["success" => true, "message" => "Cập nhật sản phẩm thành công."]];
        }

        if ($method === "DELETE") {
            $id = (int) ($params["id"] ?? 0);
            $product = product_find($id);
            if (!$product) {
                return ["status" => 404, "data" => ["error" => "Không tìm thấy sản phẩm để xóa."]];
            }
            try {
                product_delete($id);
                return ["status" => 200, "data" => ["success" => true, "message" => "Xóa sản phẩm thành công."]];
            } catch (Throwable $e) {
                return ["status" => 400, "data" => ["error" => "Không thể xóa sản phẩm: " . $e->getMessage()]];
            }
        }

        return ["status" => 405, "data" => ["error" => "Method Not Allowed"]];
    }

    // Endpoint: /api/orders
    if ($path === "/api/orders") {
        if ($method === "GET") {
            return ["status" => 200, "data" => order_all()];
        }
        if ($method === "POST") {
            $customerId = (int) ($params["customer_id"] ?? 0);
            $items = $params["items"] ?? [];
            if ($customerId <= 0 || empty($items)) {
                return ["status" => 400, "data" => ["error" => "customer_id và items không được để trống."]];
            }
            try {
                $orderId = order_create($customerId, $items, trim($params["notes"] ?? ""));
                $createdOrder = order_find($orderId);
                return ["status" => 201, "data" => $createdOrder];
            } catch (Throwable $e) {
                return ["status" => 400, "data" => ["error" => $e->getMessage()]];
            }
        }
        return ["status" => 405, "data" => ["error" => "Method Not Allowed"]];
    }

    // Endpoint: /api/customers
    if ($path === "/api/customers") {
        if ($method === "GET") {
            return ["status" => 200, "data" => customer_all()];
        }
        return ["status" => 405, "data" => ["error" => "Method Not Allowed"]];
    }

    return ["status" => 404, "data" => ["error" => "API Endpoint not found."]];
}

function handle_api_http_request(): void {
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
    if ($method === "OPTIONS") {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
        http_response_code(204);
        exit;
    }

    $path = rtrim(parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH), "/");

    // Parse input
    $params = $_GET;
    $rawInput = file_get_contents("php://input");
    if (!empty($rawInput)) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $params = array_merge($params, $json);
        }
    }
    if (!empty($_POST)) {
        $params = array_merge($params, $_POST);
    }

    $res = execute_api_route($method, $path, $params);
    send_json($res["data"], $res["status"]);
}

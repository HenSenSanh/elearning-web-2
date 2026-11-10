# Hướng dẫn xây dựng trang Admin quản lý sản phẩm bằng PHP, MySQL và Docker

Dự án này là bộ source PHP thuần hoàn chỉnh cho trang quản trị thương mại điện tử / quản lý sản phẩm. Hệ thống đáp ứng đầy đủ nghiệp vụ quản lý bán hàng, kho vận, khách hàng, bảo mật và cung cấp REST API.

## 1. Chức năng chính

- **Xác thực & Bảo mật**:
  - Đăng nhập/Đăng xuất admin.
  - Mã hóa mật khẩu chuẩn **BCRYPT** bằng `password_hash()` & `password_verify()` (tự động nâng cấp hash SHA-256 cũ).
  - Quên mật khẩu qua email token bảo mật (hết hạn sau 1 giờ).
  - Bảo vệ mọi form chống tấn công CSRF bằng CSRF token.
  - Chống SQL Injection bằng PDO Prepared Statements.
  - Nhật ký hoạt động Admin (Activity Audit Logs) ghi nhận lịch sử mọi thao tác kèm địa chỉ IP.
- **Dashboard**:
  - Thống kê tổng danh mục, thương hiệu, tổng sản phẩm, số lượng tồn kho và tổng giá trị tồn kho.
- **Quản lý danh mục (Categories)**:
  - Thêm, sửa, xóa danh mục (chặn xóa an toàn nếu có sản phẩm).
  - Bật/tắt trạng thái hiển thị.
- **Quản lý thương hiệu (Brands)**:
  - Thêm, sửa, xóa thương hiệu (chặn xóa nếu có sản phẩm liên kết).
  - Liên kết thương hiệu với sản phẩm và bộ lọc.
- **Quản lý sản phẩm (Products)**:
  - Thêm, sửa, xóa sản phẩm kèm phân trang (5 sản phẩm/trang).
  - Tìm kiếm theo tên sản phẩm hoặc SKU; lọc theo danh mục và thương hiệu.
  - Upload ảnh đại diện chính (JPG, PNG, WEBP, GIF tối đa 2MB; tự xóa ảnh cũ).
  - **Bộ sưu tập nhiều hình ảnh (Gallery)**: Upload nhiều ảnh bổ sung, quản lý và xóa từng ảnh trong gallery.
- **Quản lý Nhập / Xuất kho (Inventory)**:
  - Tạo phiếu nhập kho (+) và xuất kho (-) có kiểm tra ràng buộc tồn kho.
  - Tự động đồng bộ số lượng tồn kho sản phẩm theo transaction CSDL.
  - Theo dõi lịch sử biến động kho và thống kê tổng lượng nhập/xuất.
- **Quản lý Khách hàng (Customers)**:
  - Quản lý thông tin khách hàng (họ tên, email duy nhất, số điện thoại, địa chỉ).
  - Tìm kiếm và phân trang danh sách khách hàng.
- **Quản lý Đơn hàng (Orders & Items)**:
  - Tạo đơn hàng cho khách hàng với nhiều sản phẩm.
  - Tự động trừ tồn kho khi tạo đơn và hoàn kho khi hủy đơn (`cancelled`).
  - Cập nhật trạng thái đơn: Chờ xử lý (`pending`), Đang xử lý (`processing`), Hoàn thành (`completed`), Đã hủy (`cancelled`).
- **Xuất dữ liệu Excel / CSV**:
  - Xuất danh sách sản phẩm, lịch sử kho, danh sách đơn hàng ra file CSV chuẩn UTF-8 BOM hiển thị tiếng Việt hoàn hảo trên Microsoft Excel.
- **REST API Subsystem**:
  - Cung cấp API JSON chuẩn RESTful cho bên thứ ba hoặc Mobile App:
    - `GET /api/categories`
    - `GET /api/brands`
    - `GET /api/products` (kèm lọc, tìm kiếm, phân trang)
    - `GET /api/products?id={id}`
    - `POST /api/products`
    - `PUT /api/products?id={id}`
    - `DELETE /api/products?id={id}`
    - `GET /api/orders`
    - `POST /api/orders`
    - `GET /api/customers`
  - Hỗ trợ CORS đầy đủ cho frontend React/Vue/Flutter.

---

## 2. Cấu trúc thư mục

```text
php_admin_product_manager/
├── app/
│   ├── public/
│   │   ├── index.php                 # Front controller & router chính
│   │   ├── .htaccess                 # Cấu hình rewrite URL cho Apache
│   │   ├── assets/
│   │   │   ├── css/admin.css         # Giao diện responsive
│   │   │   └── js/admin.js           # Client-side validation
│   │   └── uploads/products/         # Thư mục lưu trữ hình ảnh tải lên
│   ├── src/
│   │   ├── config.php                # Cấu hình hệ thống & biến môi trường
│   │   ├── db.php                    # Kết nối MySQL PDO Singleton
│   │   ├── helpers.php               # Helper CSRF, Flash, Upload file
│   │   ├── auth.php                  # Xử lý xác thực admin (BCRYPT)
│   │   ├── category_repository.php   # Nghiệp vụ danh mục
│   │   ├── brand_repository.php      # Nghiệp vụ thương hiệu
│   │   ├── product_repository.php    # Nghiệp vụ sản phẩm & gallery
│   │   ├── inventory_repository.php  # Nghiệp vụ nhập/xuất kho
│   │   ├── customer_repository.php   # Nghiệp vụ khách hàng
│   │   ├── order_repository.php      # Nghiệp vụ đơn hàng & chi tiết đơn
│   │   ├── export_service.php        # Dịch vụ xuất file CSV / Excel UTF-8 BOM
│   │   ├── activity_log_repository.php # Ghi nhận nhật ký audit log
│   │   ├── password_reset_repository.php # Xử lý token quên mật khẩu
│   │   └── api_controller.php        # Xử lý và điều phối REST API JSON
│   ├── views/
│   │   ├── layouts/ (header, footer)
│   │   ├── auth/ (login, forgot_password, reset_password)
│   │   ├── dashboard/
│   │   ├── categories/
│   │   ├── brands/
│   │   ├── products/
│   │   ├── inventory/
│   │   ├── customers/
│   │   ├── orders/
│   │   └── activity_logs/
│   ├── tests/
│   │   └── test_all_cases.php        # Bộ kiểm thử tự động 65 Test Cases
│   └── migrate_schema.php            # Script tự động di chuyển CSDL
├── database/
│   └── init.sql                      # CSDL ban đầu & seed data
├── docker/
│   └── php/Dockerfile
├── .env                              # Biến môi trường
├── docker-compose.yml
└── README.md
```

---

## 3. Thiết kế database

Database gồm 9 bảng chính:

```text
admins
categories
brands
products
product_images
inventory_logs
customers
orders
order_items
activity_logs
password_resets
```

### 3.1. Bảng admins
Lưu trữ tài khoản quản trị:
- `id`, `username`, `email`, `password_hash` (BCRYPT), `full_name`, `created_at`, `updated_at`.
- Tài khoản mặc định: `admin` / `admin123`.

### 3.2. Bảng categories
Lưu trữ danh mục:
- `id`, `name` (UNIQUE), `description`, `status`, `created_at`, `updated_at`.

### 3.3. Bảng brands
Lưu trữ thương hiệu / hãng sản xuất:
- `id`, `name` (UNIQUE), `description`, `status`, `created_at`, `updated_at`.

### 3.4. Bảng products
Lưu trữ sản phẩm:
- `id`, `category_id` (FK categories), `brand_id` (FK brands), `name`, `sku` (UNIQUE), `description`, `quantity`, `unit_price`, `image_path`, `status`, `created_at`, `updated_at`.

### 3.5. Bảng product_images
Bộ sưu tập nhiều ảnh cho sản phẩm:
- `id`, `product_id` (FK products ON DELETE CASCADE), `image_path`, `sort_order`, `created_at`.

### 3.6. Bảng inventory_logs
Nhật ký nhập / xuất kho:
- `id`, `product_id` (FK products), `admin_id` (FK admins), `type` ('IN' hoặc 'OUT'), `quantity`, `note`, `created_at`.

### 3.7. Bảng customers
Quản lý khách hàng:
- `id`, `name`, `email` (UNIQUE), `phone`, `address`, `status`, `created_at`, `updated_at`.

### 3.8. Bảng orders & order_items
Quản lý đơn hàng và từng dòng sản phẩm:
- `orders`: `id`, `order_code` (UNIQUE), `customer_id` (FK customers), `total_amount`, `status` ('pending', 'processing', 'completed', 'cancelled'), `notes`, `created_at`, `updated_at`.
- `order_items`: `id`, `order_id` (FK orders ON DELETE CASCADE), `product_id` (FK products), `quantity`, `unit_price`, `subtotal`, `created_at`.

### 3.9. Bảng activity_logs & password_resets
- `activity_logs`: `id`, `admin_id`, `action`, `description`, `ip_address`, `created_at`.
- `password_resets`: `id`, `email`, `token` (UNIQUE 64 chars), `expires_at`, `created_at`.

---

## 4. Chạy project với Docker

Khởi động các container:
```bash
docker compose up -d
```

Kiểm tra trạng thái container:
```bash
docker ps
```

Truy cập:
- **Trang Admin**: [http://localhost:8090](http://localhost:8090)
  - Tài khoản: `admin`
  - Mật khẩu: `admin123`
- **phpMyAdmin**: [http://localhost:8091](http://localhost:8091)
  - Server: `mysql` | User: `admin_user` | Pass: `admin_password` | DB: `admin_product_db`

---

## 5. Tự động kiểm thử (Automated Test Suite)

Dự án tích hợp bộ kiểm thử tự động gồm **65 Test Cases** bao quát toàn bộ chức năng:

```bash
docker exec php_admin_product_app php /var/www/html/tests/test_all_cases.php
```

### Các nhóm kiểm thử chính:
1. **Module 1 - Authentication**: Đăng nhập trống, sai tài khoản, sai mật khẩu, đăng nhập chuẩn BCRYPT.
2. **Module 2 - Category**: Trùng tên danh mục, thêm, sửa, ràng buộc toàn vẹn khi có sản phẩm liên kết, xóa an toàn.
3. **Module 2B - Brand**: Trùng tên thương hiệu, thêm, sửa, kiểm tra sản phẩm thuộc thương hiệu, xóa thương hiệu.
4. **Module 3 - Product**: Validate tên rỗng, danh mục rỗng, số lượng âm, đơn giá âm, trùng SKU, phân trang, tìm kiếm, lọc danh mục/thương hiệu.
5. **Module 3B - Product Gallery**: Thêm nhiều ảnh vào bộ sưu tập, xóa từng ảnh, cascade khi xóa sản phẩm.
6. **Module 3C - Inventory**: Nhập kho tăng tồn, xuất kho giảm tồn, chặn xuất kho quá số lượng hiện có, phân trang lịch sử kho.
7. **Module 3D - Customer**: Kiểm tra trùng email, thêm, sửa, tìm kiếm và phân trang khách hàng, xóa khách hàng.
8. **Module 3E - Orders**: Tạo đơn tự động sinh mã `ORD-...`, trừ tồn kho, lưu chi tiết từng sản phẩm, chặn đặt hàng vượt quá tồn kho, tự động hoàn kho khi đơn bị hủy (`cancelled`).
9. **Module 3F - CSV Export**: Xuất file CSV có UTF-8 BOM, hiển thị tiếng Việt có dấu chuẩn xác trên Excel, xử lý dấu phẩy và ngoặc kép.
10. **Module 3G - Activity Logs**: Tự động ghi lại nhật ký thao tác và truy xuất lịch sử audit.
11. **Module 3H - Password Reset**: Tạo token 64 ký tự, xác thực thời hạn token, áp dụng mật khẩu mới bằng BCRYPT, thu hồi token sau khi sử dụng.
12. **Module 3I - REST API**: Kiểm tra các endpoint JSON (`/api/categories`, `/api/brands`, `/api/products`, `/api/orders`), mã trạng thái HTTP 200, 201, 400, 404.
13. **Module 4, 5, 6**: Dashboard metrics, CSRF token security, Flash messages.

---

## 6. Hướng phát triển tiếp (Trạng thái triển khai)

Toàn bộ các tính năng định hướng đã được triển khai hoàn chỉnh vào hệ thống:

- [x] **Thêm nhiều ảnh cho một sản phẩm**: Bảng `product_images`, upload ảnh gallery, quản lý và xóa từng ảnh.
- [x] **Thêm thương hiệu**: Bảng `brands`, quản lý CRUD hãng, lọc sản phẩm theo thương hiệu.
- [x] **Thêm nhập kho/xuất kho**: Bảng `inventory_logs`, giao dịch xuất nhập, cập nhật tồn kho an toàn.
- [x] **Thêm đơn hàng**: Bảng `orders` & `order_items`, tạo đơn, quản lý trạng thái, tự động trừ/hoàn kho.
- [x] **Thêm khách hàng**: Bảng `customers`, quản lý thông tin khách hàng, kiểm tra trùng lặp email.
- [x] **Thêm phân trang**: Đã triển khai linh hoạt trên toàn bộ các danh sách sản phẩm, khách hàng, đơn hàng, kho.
- [x] **Thêm export Excel**: Hỗ trợ xuất file CSV định dạng UTF-8 BOM chuẩn cho Excel.
- [x] **Thêm REST API**: Cung cấp đầy đủ các endpoint JSON cho products, categories, brands, orders kèm CORS.
- [x] **Thay SHA256 bằng password_hash()**: Sử dụng chuẩn BCRYPT hiện đại nhất kèm cơ chế auto-upgrade.
- [x] **Thêm quên mật khẩu qua email**: Hệ thống sinh token bảo mật 64 ký tự có hạn dùng 1 giờ.
- [x] **Thêm log hoạt động admin**: Bảng `activity_logs`, ghi nhận mọi hành động nhạy cảm trên hệ thống.

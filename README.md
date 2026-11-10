# Hướng dẫn xây dựng trang Admin quản lý sản phẩm bằng PHP, MySQL và Docker

Dự án này là bộ source PHP thuần cơ bản cho trang quản trị sản phẩm. Hệ thống phù hợp cho bài thực hành môn lập trình web, cơ sở dữ liệu hoặc làm nền tảng mở rộng thành website bán hàng.

## 1. Chức năng chính

- Đăng nhập trang admin.
- Dashboard thống kê tổng danh mục, tổng sản phẩm, tổng tồn kho và giá trị tồn kho.
- Quản lý danh mục sản phẩm:
  - Thêm danh mục.
  - Sửa danh mục.
  - Xóa danh mục.
  - Bật/tắt trạng thái hiển thị.
- Quản lý sản phẩm:
  - Thêm sản phẩm.
  - Sửa sản phẩm.
  - Xóa sản phẩm.
  - Upload hình ảnh sản phẩm.
  - Quản lý tên sản phẩm, danh mục, SKU, mô tả, số lượng, đơn giá, trạng thái.
  - Tìm kiếm theo tên sản phẩm hoặc SKU.
  - Lọc theo danh mục.
- Kết nối MySQL bằng PDO.
- Validate dữ liệu bằng JavaScript và PHP.
- Bảo vệ form bằng CSRF token.
- Chạy bằng Docker Compose.
- Có phpMyAdmin để kiểm tra dữ liệu.

## 2. Cấu trúc thư mục

```text
php_admin_product_manager/
├── app/
│   ├── public/
│   │   ├── index.php
│   │   ├── .htaccess
│   │   ├── assets/
│   │   │   ├── css/admin.css
│   │   │   └── js/admin.js
│   │   └── uploads/products/
│   ├── src/
│   │   ├── auth.php
│   │   ├── config.php
│   │   ├── db.php
│   │   ├── helpers.php
│   │   ├── category_repository.php
│   │   └── product_repository.php
│   └── views/
│       ├── layouts/
│       ├── auth/
│       ├── dashboard/
│       ├── categories/
│       └── products/
├── database/init.sql
├── docker/php/Dockerfile
├── .env
├── docker-compose.yml
└── README.md
```

## 3. Thiết kế database

Database gồm 3 bảng chính:

```text
admins
categories
products
```

### 3.1. Bảng admins

Dùng để lưu tài khoản quản trị.

| Cột | Ý nghĩa |
|---|---|
| id | Khóa chính |
| username | Tên đăng nhập |
| email | Email admin |
| password_hash | Mật khẩu mã hóa BCRYPT (VARCHAR 255) |
| full_name | Họ tên admin |
| created_at | Ngày tạo |
| updated_at | Ngày cập nhật |

Tài khoản mặc định:

```text
Username: admin
Password: admin123
```

Mật khẩu trong database được mã hóa bằng chuẩn bảo mật BCRYPT:

```php
password_hash("admin123", PASSWORD_BCRYPT)
```

Trong PHP kiểm tra bằng:

```php
password_verify($password, $admin["password_hash"])
```

Hệ thống có cơ chế tự động nhận diện và nâng cấp các mật khẩu cũ từ SHA-256 lên BCRYPT ngay khi đăng nhập.

### 3.2. Bảng categories

Dùng để lưu danh mục/loại sản phẩm.

| Cột | Ý nghĩa |
|---|---|
| id | Khóa chính |
| name | Tên danh mục |
| description | Mô tả danh mục |
| status | Trạng thái hiển thị |
| created_at | Ngày tạo |
| updated_at | Ngày cập nhật |

### 3.3. Bảng products

Dùng để lưu sản phẩm.

| Cột | Ý nghĩa |
|---|---|
| id | Khóa chính |
| category_id | Khóa ngoại đến bảng categories |
| name | Tên sản phẩm |
| sku | Mã sản phẩm |
| description | Mô tả sản phẩm |
| quantity | Số lượng tồn kho |
| unit_price | Đơn giá |
| image_path | Đường dẫn hình ảnh |
| status | Trạng thái hiển thị |
| created_at | Ngày tạo |
| updated_at | Ngày cập nhật |

Quan hệ:

```text
categories 1 ----- n products
```

Một danh mục có nhiều sản phẩm. Một sản phẩm thuộc một danh mục.

## 4. Cài Docker Desktop

### Windows

1. Tải và cài Docker Desktop.
2. Bật WSL2 nếu Docker yêu cầu.
3. Mở Docker Desktop.
4. Kiểm tra:

```bash
docker --version
docker compose version
```

### macOS

1. Tải Docker Desktop đúng bản Apple Silicon hoặc Intel.
2. Kéo Docker vào Applications.
3. Mở Docker Desktop.
4. Kiểm tra:

```bash
docker --version
docker compose version
```

### Linux

Với Ubuntu, cài Docker Engine và Docker Compose plugin. Sau đó kiểm tra:

```bash
docker --version
docker compose version
```

## 5. Cấu hình biến môi trường

File `.env`:

```env
APP_NAME=PHP Admin Product Manager
APP_URL=http://localhost:8090
APP_ENV=local

DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=admin_product_db
DB_USERNAME=admin_user
DB_PASSWORD=admin_password
DB_ROOT_PASSWORD=root_password

SESSION_NAME=ADMIN_PRODUCT_SESSION
UPLOAD_MAX_SIZE=2097152
```

Trong Docker, PHP kết nối MySQL bằng hostname `mysql`, không dùng `localhost`.

## 6. Chạy project

Tại thư mục gốc:

```bash
docker compose build
docker compose up -d
```

Kiểm tra container:

```bash
docker ps
```

Truy cập trang admin:

```text
http://localhost:8090
```

Truy cập phpMyAdmin:

```text
http://localhost:8091
```

Đăng nhập admin:

```text
Username: admin
Password: admin123
```

Đăng nhập phpMyAdmin:

```text
Server: mysql
Username: admin_user
Password: admin_password
Database: admin_product_db
```

## 7. Luồng xử lý đăng nhập

```text
Admin nhập username/password
    ↓
PHP tìm admin trong bảng admins
    ↓
Mã hóa password nhập vào bằng SHA256
    ↓
So sánh với password_hash trong database
    ↓
Nếu đúng thì lưu session
    ↓
Chuyển vào dashboard
```

Session chính:

```php
$_SESSION["admin_id"]
$_SESSION["admin_username"]
$_SESSION["admin_full_name"]
```

## 8. Quản lý danh mục

Các route:

| Route | Chức năng |
|---|---|
| /categories | Danh sách danh mục |
| /categories/create | Thêm danh mục |
| /categories/edit?id=1 | Sửa danh mục |
| /categories/delete | Xóa danh mục |

Các hàm chính:

```php
category_all()
category_active_all()
category_find($id)
category_create($name, $description, $status)
category_update($id, $name, $description, $status)
category_delete($id)
```

## 9. Quản lý sản phẩm

Các route:

| Route | Chức năng |
|---|---|
| /products | Danh sách sản phẩm |
| /products/create | Thêm sản phẩm |
| /products/edit?id=1 | Sửa sản phẩm |
| /products/delete | Xóa sản phẩm |

Các hàm chính:

```php
product_paginate($keyword, $categoryId, $page, $perPage)
product_find($id)
product_create($data)
product_update($id, $data)
product_delete($id)
product_count()
product_total_quantity()
product_total_inventory_value()
```

Hệ thống hỗ trợ phân trang linh hoạt (mặc định 5 sản phẩm/trang) kèm giao diện chuyển trang (`« Đầu`, `‹ Trước`, `1, 2, ...`, `Sau ›`, `Cuối »`), tự động giữ nguyên các tham số tìm kiếm và bộ lọc trên URL.

## 10. Upload hình ảnh

Hình sản phẩm được lưu tại:

```text
app/public/uploads/products
```

Quy tắc upload:

| Quy tắc | Giá trị |
|---|---|
| Định dạng | JPG, PNG, WEBP, GIF |
| Dung lượng tối đa | 2MB |
| Tên file | Tạo tự động bằng thời gian + chuỗi random |
| Đường dẫn lưu DB | uploads/products/ten_file |

Khi sửa sản phẩm và upload ảnh mới, ảnh cũ sẽ được xóa khỏi thư mục upload.

## 11. Validate dữ liệu

### Client-side

File:

```text
app/public/assets/js/admin.js
```

Kiểm tra:

- Tên sản phẩm không rỗng.
- Phải chọn danh mục.
- Số lượng không âm.
- Đơn giá không âm.
- File upload phải là ảnh.
- File upload không quá 2MB.

### Server-side

File:

```text
app/public/index.php
```

Hàm:

```php
validate_product_input($_POST)
```

Server vẫn kiểm tra lại để tránh trường hợp người dùng tắt JavaScript hoặc gửi request thủ công.

## 12. CSRF token

Mỗi form có token:

```php
<?= csrf_field() ?>
```

Khi submit, hệ thống kiểm tra bằng:

```php
verify_csrf()
```

## 13. Lệnh Docker thường dùng

Build:

```bash
docker compose build
```

Chạy:

```bash
docker compose up -d
```

Xem log:

```bash
docker compose logs
```

Dừng:

```bash
docker compose down
```

Dừng và xóa database volume:

```bash
docker compose down -v
```

Chạy lại từ đầu:

```bash
docker compose down -v
docker compose up -d --build
```

## 14. Docker push image

Build image:

```bash
docker build -t php-admin-product-manager:1.0 -f docker/php/Dockerfile .
```

Đăng nhập Docker Hub:

```bash
docker login
```

Tag image:

```bash
docker tag php-admin-product-manager:1.0 yourdockerhub/php-admin-product-manager:1.0
```

Push image:

```bash
docker push yourdockerhub/php-admin-product-manager:1.0
```

## 15. Test case & Tự động kiểm thử

### Chạy kiểm thử tự động (Automated Test Suite)

Dự án tích hợp bộ kiểm thử tự động 27 test cases bao quát tất cả các kịch bản:

```bash
docker exec php_admin_product_app php /var/www/html/tests/test_all_cases.php
```

### Danh sách Test Case chi tiết

#### 15.1. Đăng nhập (Authentication)

| ID | Kịch bản kiểm thử | Dữ liệu đầu vào | Kết quả mong đợi |
|---|---|---|---|
| TC-AUTH-01 | Bỏ trống tài khoản & mật khẩu | `username=""`, `password=""` | Báo lỗi: "Vui lòng nhập tài khoản và mật khẩu." |
| TC-AUTH-02 | Tài khoản không tồn tại | `username="unknown"`, `password="123"` | Báo lỗi: "Tài khoản không tồn tại." |
| TC-AUTH-03 | Sai mật khẩu | `username="admin"`, `password="wrong"` | Báo lỗi: "Mật khẩu không chính xác." |
| TC-AUTH-04 | Đăng nhập đúng | `username="admin"`, `password="admin123"` | Vào trang Dashboard, lưu session |
| TC-AUTH-05 | Truy cập trang quản trị khi chưa đăng nhập | Truy cập `/categories` hoặc `/products` | Tự động chuyển hướng về `/login` |

#### 15.2. Quản lý Danh mục (Category)

| ID | Kịch bản kiểm thử | Dữ liệu đầu vào | Kết quả mong đợi |
|---|---|---|---|
| TC-CAT-01 | Tên danh mục rỗng (Client & Server) | `name=""` | Báo lỗi: "Tên danh mục không được để trống." |
| TC-CAT-02 | Tên danh mục đã tồn tại (Duplicate) | `name="Laptop"` (đã có) | Báo lỗi: "Tên danh mục đã tồn tại, vui lòng chọn tên khác." |
| TC-CAT-03 | Thêm danh mục hợp lệ | `name="Chuột văn phòng"`, `status=1` | Lưu vào database, thông báo "Thêm danh mục thành công." |
| TC-CAT-04 | Sửa danh mục | Cập nhật tên, mô tả hoặc trạng thái | Cập nhật database, thông báo "Cập nhật danh mục thành công." |
| TC-CAT-05 | Xóa danh mục đang có sản phẩm liên kết | Nhấn Xóa danh mục "Laptop" | Chặn xóa an toàn và báo lỗi: "Không thể xóa danh mục này vì vẫn còn sản phẩm thuộc danh mục." |
| TC-CAT-06 | Xóa danh mục trống (không có sản phẩm) | Nhấn Xóa danh mục chưa có sản phẩm | Xóa khỏi DB, thông báo "Xóa danh mục thành công." |

#### 15.3. Quản lý Sản phẩm (Product)

| ID | Kịch bản kiểm thử | Dữ liệu đầu vào | Kết quả mong đợi |
|---|---|---|---|
| TC-PROD-01 | Tên sản phẩm rỗng | `name=""` | Báo lỗi: "Tên sản phẩm không được để trống." |
| TC-PROD-02 | Chưa chọn danh mục | `category_id=""` hoặc `0` | Báo lỗi: "Vui lòng chọn danh mục sản phẩm." |
| TC-PROD-03 | Số lượng âm | `quantity="-5"` | Báo lỗi: "Số lượng phải lớn hơn hoặc bằng 0." |
| TC-PROD-04 | Đơn giá âm | `unit_price="-1000"` | Báo lỗi: "Đơn giá phải lớn hơn hoặc bằng 0." |
| TC-PROD-05 | Trùng mã SKU | `sku="LAP-DELL-001"` (đã có) | Báo lỗi: "Mã SKU 'LAP-DELL-001' đã tồn tại, vui lòng chọn mã khác." |
| TC-PROD-06 | Upload file không phải ảnh | File `.txt`, `.pdf`, `.exe` | Báo lỗi: "Chỉ cho phép JPG, PNG, WEBP hoặc GIF." |
| TC-PROD-07 | Upload ảnh vượt quá 2MB | File ảnh dung lượng > 2MB | Báo lỗi: "Dung lượng hình ảnh vượt quá giới hạn." / "tối đa là 2MB." |
| TC-PROD-08 | Thêm sản phẩm hợp lệ | Đầy đủ thông tin hợp lệ | Lưu DB và upload file ảnh vào `uploads/products/`, hiển thị thông báo thành công |
| TC-PROD-09 | Sửa sản phẩm và đổi ảnh mới | Chọn ảnh mới khi sửa | Lưu ảnh mới, tự động xóa ảnh cũ khỏi ổ đĩa, cập nhật DB |
| TC-PROD-10 | Xóa sản phẩm | Nhấn Xóa sản phẩm | Xóa record trong DB và xóa luôn file ảnh vật lý |
| TC-PROD-11 | Tìm kiếm sản phẩm | Nhập keyword tên hoặc SKU | Lọc ra đúng danh sách sản phẩm |
| TC-PROD-12 | Lọc theo danh mục | Chọn danh mục từ dropdown | Lọc ra đúng các sản phẩm thuộc danh mục |

## 16. Lỗi thường gặp

### Không kết nối được MySQL

Kiểm tra `.env`:

```env
DB_HOST=mysql
```

Không dùng `localhost` trong Docker container.

### Database không có bảng

Nếu MySQL volume đã tồn tại, `init.sql` không chạy lại. Xử lý:

```bash
docker compose down -v
docker compose up -d --build
```

### Upload ảnh lỗi

Kiểm tra thư mục:

```text
app/public/uploads/products
```

Có thể cấp quyền:

```bash
chmod -R 775 app/public/uploads
```

### Cổng bị trùng

Nếu `8090` hoặc `8091` bị trùng, sửa trong `docker-compose.yml`.

## 17. Hướng phát triển tiếp

- Thêm nhiều ảnh cho một sản phẩm.
- Thêm thương hiệu.
- Thêm nhập kho/xuất kho.
- Thêm đơn hàng.
- Thêm khách hàng.
- Thêm phân trang.
- Thêm export Excel.
- Thêm REST API.
- Thay SHA256 bằng `password_hash()`.
- Thêm quên mật khẩu qua email.
- Thêm log hoạt động admin.

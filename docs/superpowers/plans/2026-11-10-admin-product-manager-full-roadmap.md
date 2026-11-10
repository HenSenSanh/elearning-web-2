# Admin Product Manager: Full Roadmap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement all remaining roadmap features from README Section 17 into the PHP Admin Product Manager application (Brands, Multiple Product Images, Inventory In/Out Logs, Customers, Orders & Order Items, Excel/CSV Export, Admin Activity Logs, Forgot Password via Email, and REST API).

**Architecture:** Pure PHP (MVC lightweight pattern with Procedural Repositories) + MySQL (PDO with prepared statements) + Bootstrap/CSS responsive views. Follows the existing codebase patterns (`app/src/*_repository.php`, `app/views/*`, `app/public/index.php` front-controller routing, and `database/init.sql`).

**Tech Stack:** PHP 8.x, MySQL 8.0, PDO, Docker Compose, HTML5/CSS3, JavaScript, CSV (RFC 4180 with UTF-8 BOM).

**Spec:** README.md Section 17 (Hướng phát triển tiếp).

## Global Constraints

- Preserve all existing functionality, test suite (28 existing tests must remain 100% passing), and code conventions.
- Use PDO prepared statements with parameter binding for all database queries.
- CSRF protection (`csrf_field()`, `verify_csrf()`) required on all admin POST forms.
- Authentication check (`require_admin()`) required on all admin pages and sensitive actions.
- CSV exports must include UTF-8 BOM (`\xEF\xBB\xBF`) for seamless Microsoft Excel compatibility with Vietnamese diacritics.
- Safe schema migration: update both `database/init.sql` and run a non-destructive migration script `app/migrate_schema.php` on the active MySQL container.

## Review Focus

1. Foreign key integrity: Deleting a Brand with linked products must be blocked (similar to Category deletion).
2. Stock sync consistency: Creating a Stock In log increases product quantity, Stock Out decreases product quantity; creating an order reduces product stock; insufficient stock must be rejected on Stock Out.
3. Multiple image handling: Deleting a gallery image must physically remove the file from `app/public/uploads/products/` without touching the main image unless specified.
4. Export CSV formatting: Commas, line breaks, quotes, and Vietnamese UTF-8 accents in product/customer/order names must export without column breakage.
5. REST API error responses: Invalid JSON, missing parameters, or nonexistent IDs must return proper HTTP status codes (400, 404, 500) with JSON `{"error": "message"}` payload instead of HTML error pages.

---

### Task 1: Database Schema Expansion & Migration

**Files:**
- Modify: `database/init.sql`
- Create: `app/migrate_schema.php`

**Interfaces:**
- Produces: Tables `brands`, `product_images`, `inventory_logs`, `customers`, `orders`, `order_items`, `activity_logs`, `password_resets`, and column `products.brand_id`.

- [ ] **Step 1: Update `database/init.sql` with new tables and seed data**
  - Add `brands` table: `id`, `name`, `description`, `status`, `created_at`, `updated_at`.
  - Add `brand_id` column to `products` with foreign key `fk_products_brand` -> `brands(id)` ON DELETE RESTRICT.
  - Add `product_images` table: `id`, `product_id`, `image_path`, `sort_order`, `created_at`.
  - Add `inventory_logs` table: `id`, `product_id`, `admin_id`, `type` (ENUM 'IN','OUT'), `quantity`, `note`, `created_at`.
  - Add `customers` table: `id`, `name`, `email`, `phone`, `address`, `status`, `created_at`, `updated_at`.
  - Add `orders` table: `id`, `order_code`, `customer_id`, `total_amount`, `status`, `notes`, `created_at`, `updated_at`.
  - Add `order_items` table: `id`, `order_id`, `product_id`, `quantity`, `unit_price`, `subtotal`.
  - Add `activity_logs` table: `id`, `admin_id`, `action`, `description`, `ip_address`, `created_at`.
  - Add `password_resets` table: `id`, `email`, `token`, `expires_at`, `created_at`.
  - Add seed data for brands, customers, sample orders, and sample inventory logs.

- [ ] **Step 2: Create `app/migrate_schema.php` for seamless live DB migration**
  - Execute DDL queries with `CREATE TABLE IF NOT EXISTS` and safely add column `brand_id` to `products` if missing.
  - Insert initial demo seed data if tables are empty.

- [ ] **Step 3: Run migration in Docker container and verify**
  - Run `docker exec php_admin_product_app php /var/www/html/migrate_schema.php`.
  - Verify tables exist in MySQL.

---

### Task 2: Brands Management (Thương hiệu)

**Files:**
- Create: `app/src/brand_repository.php`
- Modify: `app/src/product_repository.php`
- Create: `app/views/brands/index.php`, `create.php`, `edit.php`
- Modify: `app/views/products/index.php`, `create.php`, `edit.php`
- Modify: `app/views/layouts/header.php`
- Modify: `app/public/index.php`

**Interfaces:**
- Produces: `brand_all()`, `brand_active_all()`, `brand_find(int $id)`, `brand_create(string $name, string $desc, int $status)`, `brand_update(int $id, string $name, string $desc, int $status)`, `brand_delete(int $id)`, `brand_exists_name(string $name, int $excludeId = 0)`, `brand_has_products(int $id)`, `brand_count()`.
- Updates `product_paginate`, `product_find`, `product_create`, `product_update` to handle `brand_id` and join `brands b ON b.id = p.brand_id`.

- [ ] **Step 1: Implement `app/src/brand_repository.php`**
  - Implement full CRUD operations, existence check, and product constraint check for brands.
- [ ] **Step 2: Update `app/src/product_repository.php` for `brand_id`**
  - Add `brand_id` to SELECT, INSERT, UPDATE queries and add brand filter in `product_paginate`.
- [ ] **Step 3: Create Views for Brands (`brands/index.php`, `create.php`, `edit.php`)**
  - Listing table with search/status badge, Add Form, Edit Form, CSRF tokens.
- [ ] **Step 4: Update Product Views & Layout Header**
  - Add Brands link in navigation menu.
  - Add Brand selector and filter in Product views.
- [ ] **Step 5: Add Brand Routes in `app/public/index.php`**
  - `/brands`, `/brands/create`, `/brands/edit`, `/brands/delete`.

---

### Task 3: Multiple Images for Products (Product Gallery)

**Files:**
- Modify: `app/src/product_repository.php`
- Modify: `app/views/products/edit.php`, `index.php`
- Modify: `app/public/index.php`

**Interfaces:**
- Produces: `product_images_find(int $productId)`, `product_images_add(int $productId, string $imagePath)`, `product_image_delete(int $imageId)`, `product_images_delete_all(int $productId)`.

- [ ] **Step 1: Add gallery repository methods in `app/src/product_repository.php`**
  - Fetch all gallery images for a product, insert gallery images, delete single gallery image (and unlink physical file).
- [ ] **Step 2: Update `upload_product_images_multiple()` in `app/src/helpers.php`**
  - Support uploading an array of images with validation (file type, max size 2MB).
- [ ] **Step 3: Update `products/edit.php` & `products/index.php` UI**
  - Display gallery thumbnails with delete buttons on edit page.
  - Add file input `name="gallery_images[]" multiple`.
- [ ] **Step 4: Add route `/products/delete-image` in `app/public/index.php`**
  - Delete single gallery image via POST with CSRF verification.

---

### Task 4: Stock In / Stock Out Management (Nhập kho / Xuất kho)

**Files:**
- Create: `app/src/inventory_repository.php`
- Create: `app/views/inventory/index.php`, `create.php`
- Modify: `app/views/layouts/header.php`
- Modify: `app/public/index.php`

**Interfaces:**
- Produces: `inventory_log_create(int $productId, int $adminId, string $type, int $qty, string $note)`, `inventory_log_paginate(int $page, int $perPage, ?int $productId = null)`, `inventory_total_in()`, `inventory_total_out()`.

- [ ] **Step 1: Implement `app/src/inventory_repository.php`**
  - Create transaction: Insert log record and atomically UPDATE `products.quantity = quantity + :delta`.
  - Validate stock out does not exceed current stock.
- [ ] **Step 2: Create Views `inventory/index.php` and `inventory/create.php`**
  - Display log history with Type badge (Nhập / Xuất), product name, quantity delta, admin, timestamp.
  - Creation form for stock in / stock out with product dropdown and notes.
- [ ] **Step 3: Register routes `/inventory`, `/inventory/create` in `app/public/index.php` and menu link in header**

---

### Task 5: Customer Management (Khách hàng)

**Files:**
- Create: `app/src/customer_repository.php`
- Create: `app/views/customers/index.php`, `create.php`, `edit.php`
- Modify: `app/views/layouts/header.php`
- Modify: `app/public/index.php`

**Interfaces:**
- Produces: `customer_all()`, `customer_paginate(string $kw, int $page, int $perPage)`, `customer_find(int $id)`, `customer_create(array $data)`, `customer_update(int $id, array $data)`, `customer_delete(int $id)`, `customer_exists_email(string $email, int $excludeId = 0)`, `customer_count()`.

- [ ] **Step 1: Implement `app/src/customer_repository.php`**
  - Standard CRUD with email uniqueness validation and customer count.
- [ ] **Step 2: Create Views `customers/index.php`, `create.php`, `edit.php`**
  - Table of customers, Add Customer modal/page, Edit Customer page.
- [ ] **Step 3: Add routes `/customers`, `/customers/create`, `/customers/edit`, `/customers/delete` in `app/public/index.php` and menu link in header**

---

### Task 6: Orders & Order Details Management (Đơn hàng)

**Files:**
- Create: `app/src/order_repository.php`
- Create: `app/views/orders/index.php`, `create.php`, `detail.php`
- Modify: `app/views/layouts/header.php`
- Modify: `app/public/index.php`

**Interfaces:**
- Produces: `order_paginate(string $status, string $kw, int $page, int $perPage)`, `order_find(int $id)`, `order_items_find(int $orderId)`, `order_create(int $customerId, array $items, string $notes)`, `order_update_status(int $id, string $status)`, `order_count()`, `order_total_revenue()`.

- [ ] **Step 1: Implement `app/src/order_repository.php`**
  - Order creation in DB transaction: generate unique `order_code` (e.g. `ORD-20261110-XXXX`), insert `orders`, insert `order_items`, and deduct product stock quantity.
  - Order status flow: `pending` -> `processing` -> `completed` / `cancelled`. If `cancelled`, restore product stock.
- [ ] **Step 2: Create Views `orders/index.php`, `create.php`, `detail.php`**
  - Orders list with status badges, customer info, total amount.
  - Order detail view showing line items, unit prices, subtotal, and status change form.
  - Order creation view allowing selecting customer, choosing products & quantities.
- [ ] **Step 3: Add routes `/orders`, `/orders/create`, `/orders/detail`, `/orders/update-status` in `app/public/index.php` and menu link in header**

---

### Task 7: CSV / Excel Export Service

**Files:**
- Create: `app/src/export_service.php`
- Modify: `app/public/index.php`
- Modify: `app/views/products/index.php`, `inventory/index.php`, `orders/index.php`

**Interfaces:**
- Produces: `export_to_csv(string $filename, array $headers, array $rows)` with UTF-8 BOM.
- Functions: `export_products_csv()`, `export_inventory_csv()`, `export_orders_csv()`.

- [ ] **Step 1: Implement `app/src/export_service.php`**
  - Sends appropriate HTTP headers (`Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename=...`), outputs UTF-8 BOM `\xEF\xBB\xBF`, writes rows via `fputcsv`.
- [ ] **Step 2: Add export routes in `app/public/index.php`**
  - `/products/export`, `/inventory/export`, `/orders/export`.
- [ ] **Step 3: Add "Xuất CSV" action buttons on respective view pages**

---

### Task 8: Admin Activity Logs (Nhật ký hoạt động)

**Files:**
- Create: `app/src/activity_log_repository.php`
- Create: `app/views/activity_logs/index.php`
- Modify: `app/public/index.php`
- Modify: `app/views/layouts/header.php`

**Interfaces:**
- Produces: `log_activity(int $adminId, string $action, string $description)`, `activity_log_paginate(int $page, int $perPage)`.

- [ ] **Step 1: Implement `app/src/activity_log_repository.php`**
  - Insert log entries with admin ID, action name, description, client IP address (`$_SERVER['REMOTE_ADDR']`), timestamp.
- [ ] **Step 2: Integrate `log_activity()` hooks across critical actions**
  - Admin login & logout.
  - Product create/update/delete.
  - Category create/update/delete.
  - Brand create/update/delete.
  - Inventory stock-in / stock-out.
  - Order creation / status update.
- [ ] **Step 3: Create View `activity_logs/index.php` and route `/activity-logs`**

---

### Task 9: Forgot Password via Email (Quên mật khẩu)

**Files:**
- Create: `app/src/password_reset_repository.php`
- Create: `app/views/auth/forgot_password.php`, `reset_password.php`
- Modify: `app/views/auth/login.php`
- Modify: `app/public/index.php`

**Interfaces:**
- Produces: `password_reset_create_token(string $email)`, `password_reset_verify_token(string $token)`, `password_reset_apply(string $token, string $newPassword)`, `password_reset_cleanup()`.

- [ ] **Step 1: Implement `app/src/password_reset_repository.php`**
  - Generates secure 64-char random token (`bin2hex(random_bytes(32))`), expires in 1 hour.
  - Stores in `password_resets`.
  - Sends simulated/system reset link to email (displays reset link in flash message or logs for local environment).
- [ ] **Step 2: Create Views `forgot_password.php` & `reset_password.php`**
  - Email input form with CSRF token.
  - New password input form with validation.
  - Add "Quên mật khẩu?" link on login page.
- [ ] **Step 3: Add routes `/forgot-password` and `/reset-password` in `app/public/index.php`**

---

### Task 10: REST API Subsystem

**Files:**
- Create: `app/src/api_controller.php`
- Modify: `app/public/index.php`

**Interfaces:**
- Produces: JSON RESTful endpoints:
  - `GET /api/products` (list with filters & pagination)
  - `GET /api/products?id={id}` (single product details)
  - `POST /api/products` (create product)
  - `PUT /api/products?id={id}` (update product)
  - `DELETE /api/products?id={id}` (delete product)
  - `GET /api/categories` (list all categories)
  - `GET /api/brands` (list all brands)
  - `GET /api/orders` (list orders)
  - `POST /api/orders` (create new order)
- Helper: `json_response($data, int $status = 200)` and Bearer Token / API Key check.

- [ ] **Step 1: Implement `app/src/api_controller.php`**
  - JSON serializer, HTTP method routing, error handler, input parsing.
- [ ] **Step 2: Route `/api/*` in `app/public/index.php`**
  - Intercept `/api/` prefix and dispatch to API controller without HTML layout rendering.

---

### Task 11: Expanded Test Suite & Verification

**Files:**
- Modify: `app/tests/test_all_cases.php`
- Modify: `README.md`

- [ ] **Step 1: Expand automated test suite in `app/tests/test_all_cases.php`**
  - Add tests for Brands CRUD & validation.
  - Add tests for Gallery image association.
  - Add tests for Inventory stock in/out and quantity delta.
  - Add tests for Customers CRUD & email uniqueness.
  - Add tests for Orders creation and stock deduction.
  - Add tests for Activity logs recording.
  - Add tests for Password reset token generation & verification.
  - Add tests for CSV export format and BOM headers.
  - Add tests for REST API endpoints (`/api/products`, `/api/categories`, `/api/brands`).
- [ ] **Step 2: Run test suite via Docker**
  - `docker exec php_admin_product_app php /var/www/html/tests/test_all_cases.php`
  - Ensure 100% tests pass.
- [ ] **Step 3: Update `README.md`**
  - Document all new features, new database tables, routes, and test suite.

---

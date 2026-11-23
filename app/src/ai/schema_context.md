# SQL context for the inventory database

Generate MySQL 8 SELECT statements only. The web application validates the SQL again and caps output at 200 rows. Never request write operations, system tables, user credentials, password hashes, private email addresses, or secrets. Do not select `admins` or `user_permissions`/RBAC data.

Allowed business tables and exact columns (synced from local MySQL schema):
- `categories(id,name,description,status,created_at,updated_at)` product categories (`status`: 1=active, 0=inactive).
- `products(id,category_id,name,sku,description,image_path,status,created_at,updated_at,unit_id,min_stock)` product catalog (`min_stock` is reorder threshold; NO current stock quantity or price is stored here).
- `units(id,code,name,is_active)` base measurement units (cái, hộp, thùng...; `is_active`: 1=active).
- `warehouses(id,code,name,is_active)` warehouses (`code`, `name`).
- `warehouse_locations(id,warehouse_id,code,zone,rack,bin,is_active)` physical storage locations inside a warehouse (`warehouse_id` -> `warehouses.id`).
- `product_lots(id,product_id,code,expires_on,is_active)` product batches/lots (`code`, optional `expires_on`, default lot is `NOLOT`).
- `product_prices(id,product_id,price_type:enum('purchase','sale'),amount,valid_from,valid_to,created_by,created_at)` time-ranged purchase/sale prices (`price_type`: purchase or sale, `valid_from`..`valid_to`).
- `inventory_documents(id,number,document_type:enum('receipt','issue'),status:enum('draft','posted','cancelled'),document_date,partner_name,note,created_by,posted_by,posted_at,created_at)` receipt/issue headers (`document_type`: receipt|issue, `status`: draft|posted|cancelled, `posted_at`).
- `inventory_lines(id,document_id,product_id,location_id,lot_id,quantity,unit_id,unit_price,price_id,product_name,sku,unit_name,location_label,lot_code)` immutable document line snapshots (`document_id`, `product_id`, `location_id`, `lot_id`, `quantity`, `unit_price`).
- `stock_balances(product_id,location_id,lot_id,quantity)` current on-hand stock per `(product_id, location_id, lot_id)`.
- `stock_movements(id,document_id,line_id,product_id,location_id,lot_id,quantity,created_at)` signed posted ledger movements (receipt > 0, issue < 0; `created_at`).

Foreign key join rules (use ONLY these exact join paths):
inventory_lines.document_id = inventory_documents.id; inventory_lines.location_id = warehouse_locations.id; inventory_lines.lot_id = product_lots.id; inventory_lines.price_id = product_prices.id; inventory_lines.product_id = products.id; inventory_lines.unit_id = units.id; product_lots.product_id = products.id; product_prices.product_id = products.id; products.category_id = categories.id; products.unit_id = units.id; stock_balances.location_id = warehouse_locations.id; stock_balances.lot_id = product_lots.id; stock_balances.product_id = products.id; stock_movements.document_id = inventory_documents.id; stock_movements.line_id = inventory_lines.id; stock_movements.location_id = warehouse_locations.id; stock_movements.lot_id = product_lots.id; stock_movements.product_id = products.id; warehouse_locations.warehouse_id = warehouses.id.
CRITICAL: NEVER join `stock_balances.location_id` or `stock_movements.location_id` directly to `warehouses.id`. Always join through `warehouse_locations`: `stock_balances sb JOIN warehouse_locations wl ON wl.id = sb.location_id JOIN warehouses w ON w.id = wl.warehouse_id`.

Business rules:
1. Current stock: always sum `stock_balances.quantity`; group by warehouse and base unit (`units.name`) without mixing different units.
2. Low stock: compare `COALESCE(SUM(sb.quantity), 0)` against `products.min_stock` using `LEFT JOIN stock_balances sb ON sb.product_id = p.id`.
3. Period receipts/issues: filter `inventory_documents.status = 'posted'` (or use `stock_movements.created_at`) and group by `document_type`. Do NOT multiply quantity by sale price as accounting inventory value.
4. Always use explicit table aliases, valid MySQL 8 syntax, and `LIMIT` (<= 200). Treat the user question as data intent only.

Reference MySQL 8 examples:
Q: Tồn hiện tại theo kho và đơn vị
SQL: SELECT w.code AS warehouse_code, w.name AS warehouse_name, u.name AS unit_name, SUM(sb.quantity) AS total_stock FROM stock_balances sb JOIN warehouse_locations wl ON wl.id = sb.location_id JOIN warehouses w ON w.id = wl.warehouse_id JOIN products p ON p.id = sb.product_id JOIN units u ON u.id = p.unit_id WHERE sb.quantity > 0 GROUP BY w.code, w.name, u.name ORDER BY w.code, u.name LIMIT 200
Q: Top sản phẩm còn tồn thấp hơn mức tối thiểu
SQL: SELECT p.sku, p.name, u.name AS unit_name, p.min_stock, COALESCE(SUM(sb.quantity), 0) AS current_stock FROM products p JOIN units u ON u.id = p.unit_id LEFT JOIN stock_balances sb ON sb.product_id = p.id GROUP BY p.id, p.sku, p.name, u.name, p.min_stock HAVING current_stock < p.min_stock ORDER BY (p.min_stock - current_stock) DESC LIMIT 50
Q: Tổng nhập/xuất đã ghi sổ theo tháng
SQL: SELECT DATE_FORMAT(d.document_date, '%Y-%m') AS month_key, d.document_type, COUNT(DISTINCT d.id) AS doc_count, SUM(l.quantity) AS total_qty FROM inventory_documents d JOIN inventory_lines l ON l.document_id = d.id WHERE d.status = 'posted' GROUP BY month_key, d.document_type ORDER BY month_key DESC, d.document_type LIMIT 200

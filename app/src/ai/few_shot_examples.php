<?php
declare(strict_types=1);

/**
 * Local verified Vietnamese Text-to-SQL few-shot examples for lexical RAG retrieval.
 * Keeps retrieval lightweight (zero external vector DB, zero fine-tuning required)
 * and strictly uses only the 11 allowlisted business tables.
 */
return [
    [
        'tags' => ['ton kho', 'kho', 'don vi', 'hien tai', 'stock_balances', 'warehouses', 'units'],
        'question' => 'Tồn hiện tại theo kho và đơn vị',
        'sql' => 'SELECT w.code AS warehouse_code, w.name AS warehouse_name, u.name AS unit_name, SUM(sb.quantity) AS total_stock FROM stock_balances sb JOIN warehouse_locations wl ON wl.id = sb.location_id JOIN warehouses w ON w.id = wl.warehouse_id JOIN products p ON p.id = sb.product_id JOIN units u ON u.id = p.unit_id WHERE sb.quantity > 0 GROUP BY w.code, w.name, u.name ORDER BY w.code, u.name LIMIT 200',
    ],
    [
        'tags' => ['ton thap', 'toi thieu', 'min_stock', 'thieu hut', 'nhap bo sung', 'canh bao'],
        'question' => 'Liệt kê các sản phẩm có tổng tồn hiện tại thấp hơn min_stock kèm số lượng thiếu hụt giảm dần',
        'sql' => 'SELECT p.sku, p.name, u.name AS unit_name, p.min_stock, COALESCE(SUM(sb.quantity), 0) AS current_stock, (p.min_stock - COALESCE(SUM(sb.quantity), 0)) AS shortage FROM products p JOIN units u ON u.id = p.unit_id LEFT JOIN stock_balances sb ON sb.product_id = p.id GROUP BY p.id, p.sku, p.name, u.name, p.min_stock HAVING current_stock < p.min_stock ORDER BY shortage DESC LIMIT 200',
    ],
    [
        'tags' => ['nhap xuat', 'theo thang', 'ghi so', 'posted', 'inventory_documents', 'inventory_lines'],
        'question' => 'Tổng nhập/xuất đã ghi sổ theo tháng',
        'sql' => "SELECT DATE_FORMAT(d.document_date, '%Y-%m') AS month_key, d.document_type, COUNT(DISTINCT d.id) AS doc_count, SUM(l.quantity) AS total_qty FROM inventory_documents d JOIN inventory_lines l ON l.document_id = d.id WHERE d.status = 'posted' GROUP BY month_key, d.document_type ORDER BY month_key DESC, d.document_type LIMIT 200",
    ],
    [
        'tags' => ['nhap xuat', 'trong thang hien tai', 'theo ngay', 'posted', 'document_type'],
        'question' => 'Tổng hợp số phiếu và tổng số lượng nhập/xuất đã ghi sổ theo ngày trong tháng hiện tại',
        'sql' => "SELECT DATE(d.document_date) AS doc_date, d.document_type, COUNT(DISTINCT d.id) AS doc_count, SUM(l.quantity) AS total_qty FROM inventory_documents d JOIN inventory_lines l ON l.document_id = d.id WHERE d.status = 'posted' AND YEAR(d.document_date) = YEAR(CURDATE()) AND MONTH(d.document_date) = MONTH(CURDATE()) GROUP BY doc_date, d.document_type ORDER BY doc_date ASC, d.document_type LIMIT 200",
    ],
    [
        'tags' => ['lo', 'han su dung', 'expires_on', 'fefo', 'vi tri', 'ton kho theo lo', 'product_lots'],
        'question' => 'Liệt kê tồn kho hiện tại theo kho, vị trí, mã sản phẩm, mã lô, ngày hết hạn (lô có hạn dùng lên trước)',
        'sql' => 'SELECT w.code AS warehouse_code, w.name AS warehouse_name, wl.code AS location_code, p.sku, p.name AS product_name, pl.code AS lot_code, pl.expires_on, sb.quantity FROM stock_balances sb JOIN warehouse_locations wl ON wl.id = sb.location_id JOIN warehouses w ON w.id = wl.warehouse_id JOIN products p ON p.id = sb.product_id JOIN product_lots pl ON pl.id = sb.lot_id WHERE sb.quantity > 0 ORDER BY (pl.expires_on IS NULL), pl.expires_on ASC, p.sku LIMIT 200',
    ],
    [
        'tags' => ['lo', 'sap het han', 'het han', 'truoc ngay', 'expires_on', 'product_lots'],
        'question' => 'Liệt kê các lô hàng đang còn tồn kho có hạn sử dụng trước ngày 2028-01-01',
        'sql' => "SELECT w.code AS warehouse_code, wl.code AS location_code, p.sku, p.name AS product_name, pl.code AS lot_code, pl.expires_on, sb.quantity FROM stock_balances sb JOIN product_lots pl ON pl.id = sb.lot_id JOIN products p ON p.id = sb.product_id JOIN warehouse_locations wl ON wl.id = sb.location_id JOIN warehouses w ON w.id = wl.warehouse_id WHERE sb.quantity > 0 AND pl.expires_on IS NOT NULL AND pl.expires_on < '2028-01-01' ORDER BY pl.expires_on ASC LIMIT 200",
    ],
    [
        'tags' => ['top', 'ton cao nhat', 'nhieu nhat', 'danh muc', 'categories'],
        'question' => 'Liệt kê top 5 sản phẩm có số lượng tồn kho hiện tại cao nhất kèm tên danh mục',
        'sql' => 'SELECT p.sku, p.name AS product_name, c.name AS category_name, SUM(sb.quantity) AS total_stock FROM stock_balances sb JOIN products p ON p.id = sb.product_id JOIN categories c ON c.id = p.category_id GROUP BY p.id, p.sku, p.name, c.name ORDER BY total_stock DESC LIMIT 5',
    ],
    [
        'tags' => ['het hang', 'ton bang 0', 'chua co ton', 'bao nhieu', 'danh muc', 'zero stock'],
        'question' => 'Có bao nhiêu sản phẩm thuộc danh mục Laptop hiện đang hết hàng (tồn kho bằng 0)?',
        'sql' => "SELECT COUNT(*) AS out_of_stock_count FROM (SELECT p.id FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN stock_balances sb ON sb.product_id = p.id WHERE c.name = 'Laptop' GROUP BY p.id HAVING COALESCE(SUM(sb.quantity), 0) = 0) AS t LIMIT 1",
    ],
    [
        'tags' => ['danh muc', 'don vi', 'tong ton', 'categories', 'units', 'stock_balances'],
        'question' => 'Tổng số lượng tồn kho hiện tại theo từng danh mục sản phẩm và đơn vị tính',
        'sql' => 'SELECT c.name AS category_name, u.name AS unit_name, COALESCE(SUM(sb.quantity), 0) AS total_stock FROM categories c JOIN products p ON p.category_id = c.id JOIN units u ON u.id = p.unit_id LEFT JOIN stock_balances sb ON sb.product_id = p.id GROUP BY c.id, c.name, u.id, u.name ORDER BY total_stock DESC, c.name LIMIT 200',
    ],
    [
        'tags' => ['gia ban', 'gia hien hanh', 'moi nhat', 'product_prices', 'sale', 'valid_to'],
        'question' => 'Xem giá bán hiện hành của từng sản phẩm thuộc danh mục Điện thoại',
        'sql' => "SELECT p.sku, p.name AS product_name, pp.amount AS sale_price, pp.valid_from, pp.valid_to FROM products p JOIN categories c ON c.id = p.category_id JOIN product_prices pp ON pp.product_id = p.id WHERE c.name = 'Điện thoại' AND pp.price_type = 'sale' AND pp.valid_from <= NOW() AND (pp.valid_to IS NULL OR pp.valid_to >= NOW()) ORDER BY p.sku LIMIT 200",
    ],
    [
        'tags' => ['gia nhap', 'gia ban', 'trung binh', 'so sanh gia', 'product_prices', 'danh muc'],
        'question' => 'Tính giá nhập trung bình và giá bán trung bình theo từng danh mục sản phẩm',
        'sql' => "SELECT c.name AS category_name, ROUND(AVG(CASE WHEN pp.price_type = 'purchase' THEN pp.amount END), 2) AS avg_purchase_price, ROUND(AVG(CASE WHEN pp.price_type = 'sale' THEN pp.amount END), 2) AS avg_sale_price FROM categories c JOIN products p ON p.category_id = c.id JOIN product_prices pp ON pp.product_id = p.id GROUP BY c.id, c.name ORDER BY c.name LIMIT 200",
    ],
    [
        'tags' => ['nhap', 'draft', 'phieu nhap', 'phieu xuat', 'doi tac', 'partner_name', 'inventory_documents'],
        'question' => 'Liệt kê các phiếu nhập xuất đang ở trạng thái nháp (draft) kèm tên đối tác và tổng số lượng',
        'sql' => "SELECT d.number, d.document_type, d.document_date, d.partner_name, COUNT(l.id) AS line_count, COALESCE(SUM(l.quantity), 0) AS total_qty FROM inventory_documents d LEFT JOIN inventory_lines l ON l.document_id = d.id WHERE d.status = 'draft' GROUP BY d.id, d.number, d.document_type, d.document_date, d.partner_name ORDER BY d.document_date DESC LIMIT 200",
    ],
    [
        'tags' => ['bien dong', 'lich su kho', 'stock_movements', 'ma kho', 'ma lo'],
        'question' => 'Liệt kê lịch sử biến động kho gần nhất từ stock_movements kèm mã sản phẩm, mã kho và mã lô',
        'sql' => 'SELECT sm.id, sm.created_at, p.sku, p.name AS product_name, w.code AS warehouse_code, wl.code AS location_code, pl.code AS lot_code, sm.quantity FROM stock_movements sm JOIN products p ON p.id = sm.product_id JOIN warehouse_locations wl ON wl.id = sm.location_id JOIN warehouses w ON w.id = wl.warehouse_id JOIN product_lots pl ON pl.id = sm.lot_id ORDER BY sm.created_at DESC, sm.id DESC LIMIT 200',
    ],
    [
        'tags' => ['vi tri', 'ke', 'zone', 'rack', 'bin', 'warehouse_locations', 'kho phu', 'kho chinh'],
        'question' => 'Tồn kho tại một kho cụ thể theo từng vị trí (code, zone, rack, bin)',
        'sql' => "SELECT w.code AS warehouse_code, wl.code AS location_code, wl.zone, wl.rack, wl.bin, SUM(sb.quantity) AS total_stock FROM stock_balances sb JOIN warehouse_locations wl ON wl.id = sb.location_id JOIN warehouses w ON w.id = wl.warehouse_id WHERE w.code = 'SUB' AND sb.quantity > 0 GROUP BY w.code, wl.id, wl.code, wl.zone, wl.rack, wl.bin ORDER BY wl.code LIMIT 200",
    ],
    [
        'tags' => ['tim san pham', 'chua chu', 'ten san pham', 'ton lon hon 0', 'like'],
        'question' => 'Tìm các sản phẩm có tên chứa chữ Laptop và đang có tồn kho lớn hơn 0',
        'sql' => "SELECT p.sku, p.name AS product_name, SUM(sb.quantity) AS current_stock FROM products p JOIN stock_balances sb ON sb.product_id = p.id WHERE p.name LIKE '%Laptop%' GROUP BY p.id, p.sku, p.name HAVING SUM(sb.quantity) > 0 ORDER BY current_stock DESC LIMIT 200",
    ],
    [
        'tags' => ['doi tac', 'partner_name', 'nha cung cap', 'nhap kho', 'top doi tac', 'receipt'],
        'question' => 'Top đối tác (partner_name) có tổng số lượng nhập kho đã ghi sổ nhiều nhất',
        'sql' => "SELECT d.partner_name, COUNT(DISTINCT d.id) AS doc_count, SUM(l.quantity) AS total_receipt_qty FROM inventory_documents d JOIN inventory_lines l ON l.document_id = d.id WHERE d.status = 'posted' AND d.document_type = 'receipt' GROUP BY d.partner_name ORDER BY total_receipt_qty DESC LIMIT 200",
    ],
    [
        'tags' => ['gia tri nhap', 'unit_price', 'thanh tien', 'theo thang', 'receipt', 'inventory_lines'],
        'question' => 'Tổng giá trị nhập kho đã ghi sổ (quantity * unit_price trong inventory_lines) theo từng tháng',
        'sql' => "SELECT DATE_FORMAT(d.document_date, '%Y-%m') AS month_key, COUNT(DISTINCT d.id) AS doc_count, SUM(l.quantity) AS total_qty, SUM(l.quantity * l.unit_price) AS total_receipt_value FROM inventory_documents d JOIN inventory_lines l ON l.document_id = d.id WHERE d.status = 'posted' AND d.document_type = 'receipt' GROUP BY month_key ORDER BY month_key DESC LIMIT 200",
    ],
    [
        'tags' => ['doanh thu', 'hoa hong', 'nhan vien', 'loi nhuan', 'luong', 'khach hang', 'ngoai pham vi'],
        'question' => 'Tính tổng doanh thu và hoa hồng của từng nhân viên kinh doanh',
        'sql' => null,
        'explanation' => 'Cơ sở dữ liệu kho hiện tại chỉ quản lý danh mục, sản phẩm, giá, lô, tồn kho và phiếu nhập/xuất; không có bảng nhân viên kinh doanh, đơn bán hàng hay hoa hồng.',
    ],
];

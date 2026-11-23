# Báo cáo tồn kho theo lô và hạn sử dụng

Ngày tạo: {{generated_at}}

## Tồn kho theo lô hàng đang còn số dư

<!-- ai-query: Liệt kê tồn kho hiện tại theo kho, vị trí, mã sản phẩm (sku), tên sản phẩm, mã lô, ngày hết hạn (expires_on) và số lượng tồn (lớn hơn 0), sắp xếp theo ngày hết hạn tăng dần (lô có hạn dùng lên trước). Dùng stock_balances, product_lots, products, warehouse_locations và warehouses. -->

## Ghi chú

- Các sản phẩm không quản lý lô đặc biệt sử dụng mã lô mặc định `NOLOT` (không có ngày hết hạn).
- Ưu tiên xuất kho theo nguyên tắc FEFO (hết hạn trước xuất trước) với các lô có `expires_on`.

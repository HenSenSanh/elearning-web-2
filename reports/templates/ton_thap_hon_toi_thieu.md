# Báo cáo cảnh báo tồn kho thấp hơn mức tối thiểu

Ngày tạo: {{generated_at}}

## Danh sách sản phẩm cần nhập bổ sung

<!-- ai-query: Liệt kê các sản phẩm có tổng tồn hiện tại thấp hơn min_stock (chỉ giữ lại các dòng có current_stock < min_stock bằng HAVING), hiển thị sku, tên sản phẩm, đơn vị cơ sở, mức tối thiểu (min_stock), tồn hiện tại và số lượng thiếu hụt (min_stock - current_stock); sắp xếp theo thiếu hụt giảm dần. Dùng products, units và LEFT JOIN stock_balances. -->

## Ghi chú

- Sản phẩm chưa có dòng số dư trong `stock_balances` được tính tồn hiện tại bằng `0`.
- Báo cáo chỉ mang tính tham khảo đặt hàng, không tự động tạo phiếu nhập kho.

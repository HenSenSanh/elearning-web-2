<?php

function generate_csv_string(array $headers, array $rows): string {
    $fp = fopen("php://memory", "r+");
    // Write UTF-8 BOM
    fwrite($fp, "\xEF\xBB\xBF");
    // Write header row
    fputcsv($fp, $headers);
    // Write data rows
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);
    return $csv;
}

function export_to_csv(string $filename, array $headers, array $rows): void {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    $output = fopen("php://output", "w");
    // UTF-8 BOM for Microsoft Excel on Windows
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

function export_products_csv(array $products): void {
    $filename = "danh_sach_san_pham_" . date("Ymd_His") . ".csv";
    $headers = ["ID", "Tên sản phẩm", "Mã SKU", "Danh mục", "Thương hiệu", "Số lượng tồn", "Đơn giá (VNĐ)", "Trạng thái", "Ngày tạo"];
    $rows = [];
    foreach ($products as $p) {
        $rows[] = [
            $p["id"],
            $p["name"],
            $p["sku"] ?: "N/A",
            $p["category_name"] ?? "",
            $p["brand_name"] ?? "",
            $p["quantity"],
            $p["unit_price"],
            $p["status"] ? "Hiển thị" : "Ẩn",
            $p["created_at"]
        ];
    }
    export_to_csv($filename, $headers, $rows);
}

function export_inventory_csv(array $logs): void {
    $filename = "lich_su_nhap_xuat_kho_" . date("Ymd_His") . ".csv";
    $headers = ["ID", "Thời gian", "Sản phẩm", "Mã SKU", "Loại phiếu", "Số lượng", "Người thực hiện", "Ghi chú"];
    $rows = [];
    foreach ($logs as $l) {
        $rows[] = [
            $l["id"],
            $l["created_at"],
            $l["product_name"] ?? "",
            $l["product_sku"] ?? "",
            $l["type"] === "IN" ? "Nhập kho (+)" : "Xuất kho (-)",
            $l["quantity"],
            $l["admin_username"] ?? "Hệ thống",
            $l["note"] ?: ""
        ];
    }
    export_to_csv($filename, $headers, $rows);
}

function export_orders_csv(array $orders): void {
    $filename = "danh_sach_don_hang_" . date("Ymd_His") . ".csv";
    $headers = ["ID", "Mã đơn hàng", "Khách hàng", "Email", "Số điện thoại", "Tổng tiền (VNĐ)", "Trạng thái", "Thời gian đặt"];
    $rows = [];
    foreach ($orders as $o) {
        $rows[] = [
            $o["id"],
            $o["order_code"],
            $o["customer_name"] ?? "",
            $o["customer_email"] ?? "",
            $o["customer_phone"] ?? "",
            $o["total_amount"],
            $o["status"],
            $o["created_at"]
        ];
    }
    export_to_csv($filename, $headers, $rows);
}

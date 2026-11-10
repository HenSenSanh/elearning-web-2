<div class="page-actions">
    <a class="btn secondary" href="/orders">← Quay lại danh sách đơn hàng</a>
</div>

<?php if ($msg = get_flash("error")): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = get_flash("success")): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>

<div class="grid-2">
    <div class="panel">
        <h3>Thông tin đơn hàng #<?= e($order["order_code"]) ?></h3>
        <p><strong>Ngày đặt:</strong> <?= date("d/m/Y H:i:s", strtotime($order["created_at"])) ?></p>
        <p><strong>Trạng thái:</strong> 
            <?php
            $stClass = "secondary";
            $stText = $order["status"];
            if ($order["status"] === "pending") { $stClass = "warning"; $stText = "Chờ xử lý"; }
            elseif ($order["status"] === "processing") { $stClass = "info"; $stText = "Đang xử lý"; }
            elseif ($order["status"] === "completed") { $stClass = "success"; $stText = "Hoàn thành"; }
            elseif ($order["status"] === "cancelled") { $stClass = "danger"; $stText = "Đã hủy"; }
            ?>
            <span class="badge <?= $stClass ?>"><?= $stText ?></span>
        </p>
        <p><strong>Ghi chú:</strong> <?= e($order["notes"] ?: "Không có ghi chú") ?></p>

        <hr style="margin: 16px 0; border: 0; border-top: 1px solid #e2e8f0;">
        <form method="POST" action="/orders/update-status" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($order["id"]) ?>">
            <label for="status"><strong>Cập nhật trạng thái:</strong></label>
            <div style="display: flex; gap: 8px; margin-top: 8px;">
                <select name="status" id="status" class="form-control" style="max-width: 200px;">
                    <option value="pending" <?= $order["status"] === "pending" ? "selected" : "" ?>>Chờ xử lý</option>
                    <option value="processing" <?= $order["status"] === "processing" ? "selected" : "" ?>>Đang xử lý</option>
                    <option value="completed" <?= $order["status"] === "completed" ? "selected" : "" ?>>Hoàn thành</option>
                    <option value="cancelled" <?= $order["status"] === "cancelled" ? "selected" : "" ?>>Đã hủy (Hoàn kho)</option>
                </select>
                <button type="submit" class="btn primary">Lưu trạng thái</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <h3>Thông tin khách hàng</h3>
        <p><strong>Họ tên:</strong> <?= e($order["customer_name"]) ?></p>
        <p><strong>Số điện thoại:</strong> <?= e($order["customer_phone"] ?: "—") ?></p>
        <p><strong>Email:</strong> <?= e($order["customer_email"]) ?></p>
        <p><strong>Địa chỉ nhận hàng:</strong> <?= e($order["customer_address"] ?: "—") ?></p>
    </div>
</div>

<div class="panel" style="margin-top: 20px;">
<h3>Chi tiết các sản phẩm đặt mua</h3>
<table class="table">
<thead>
    <tr>
        <th>Hình ảnh</th>
        <th>Sản phẩm / SKU</th>
        <th>Đơn giá</th>
        <th>Số lượng</th>
        <th>Thành tiền</th>
    </tr>
</thead>
<tbody>
<?php foreach ($items as $item): ?>
<tr>
    <td width="70">
        <?php if (!empty($item["product_image"])): ?>
            <img src="/<?= e($item["product_image"]) ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
        <?php else: ?>
            <div class="no-image" style="width: 50px; height: 50px; font-size: 10px;">No img</div>
        <?php endif; ?>
    </td>
    <td>
        <strong><?= e($item["product_name"]) ?></strong>
        <?php if (!empty($item["product_sku"])): ?>
            <small style="color: #64748b; display: block;">SKU: <?= e($item["product_sku"]) ?></small>
        <?php endif; ?>
    </td>
    <td><?= number_format((float)$item["unit_price"], 0, ',', '.') ?> đ</td>
    <td><strong><?= number_format((int)$item["quantity"]) ?></strong></td>
    <td><strong><?= number_format((float)$item["subtotal"], 0, ',', '.') ?> đ</strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
    <tr>
        <td colspan="4" style="text-align: right; font-weight: bold; font-size: 16px;">TỔNG CỘNG:</td>
        <td style="font-weight: bold; font-size: 18px; color: #0284c7;">
            <?= number_format((float)$order["total_amount"], 0, ',', '.') ?> đ
        </td>
    </tr>
</tfoot>
</table>
</div>

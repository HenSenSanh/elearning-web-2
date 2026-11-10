<div class="panel">
<h2>Tạo đơn hàng mới</h2>
<?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<form method="POST" action="/orders/create" id="orderForm">
<?= csrf_field() ?>

<div class="grid-2">
    <div class="form-group">
        <label for="customer_id">Khách hàng đặt <span class="required">*</span></label>
        <select id="customer_id" name="customer_id" class="form-control" required autofocus>
            <option value="">-- Chọn khách hàng --</option>
            <?php foreach ($customers as $c): ?>
                <option value="<?= e($c["id"]) ?>" <?= (int)($old["customer_id"] ?? 0) === (int)$c["id"] ? "selected" : "" ?>>
                    <?= e($c["name"]) ?> - <?= e($c["phone"]) ?> (<?= e($c["email"]) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="notes">Ghi chú đơn hàng</label>
        <input type="text" id="notes" name="notes" class="form-control" value="<?= e($old["notes"] ?? '') ?>" placeholder="Ví dụ: Giao giờ hành chính, khách quen...">
    </div>
</div>

<h3 style="margin-top: 20px;">Sản phẩm trong đơn</h3>
<p style="color: #64748b; font-size: 13px;">Chọn sản phẩm và số lượng cần đặt. Hệ thống sẽ tự kiểm tra và trừ tồn kho tương ứng.</p>

<table class="table" id="itemsTable">
<thead>
    <tr>
        <th>Sản phẩm</th>
        <th width="150">Số lượng</th>
    </tr>
</thead>
<tbody>
    <tr>
        <td>
            <select name="items[0][product_id]" class="form-control" required>
                <option value="">-- Chọn sản phẩm --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= e($p["id"]) ?>">
                        <?= e($p["name"]) ?> - Giá: <?= number_format((float)$p["unit_price"], 0, ',', '.') ?> đ (Còn tồn: <?= (int)$p["quantity"] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" name="items[0][quantity]" class="form-control" min="1" value="1" required>
        </td>
    </tr>
    <tr>
        <td>
            <select name="items[1][product_id]" class="form-control">
                <option value="">-- Chọn thêm sản phẩm (không bắt buộc) --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= e($p["id"]) ?>">
                        <?= e($p["name"]) ?> - Giá: <?= number_format((float)$p["unit_price"], 0, ',', '.') ?> đ (Còn tồn: <?= (int)$p["quantity"] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" name="items[1][quantity]" class="form-control" min="1" value="1">
        </td>
    </tr>
    <tr>
        <td>
            <select name="items[2][product_id]" class="form-control">
                <option value="">-- Chọn thêm sản phẩm (không bắt buộc) --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= e($p["id"]) ?>">
                        <?= e($p["name"]) ?> - Giá: <?= number_format((float)$p["unit_price"], 0, ',', '.') ?> đ (Còn tồn: <?= (int)$p["quantity"] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" name="items[2][quantity]" class="form-control" min="1" value="1">
        </td>
    </tr>
</tbody>
</table>

<div class="form-actions" style="margin-top: 24px;">
    <button type="submit" class="btn primary">Tạo đơn hàng</button>
    <a href="/orders" class="btn secondary">Hủy</a>
</div>
</form>
</div>

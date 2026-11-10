<div class="panel narrow">
<h2>Tạo phiếu Nhập / Xuất kho</h2>
<?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="POST" action="/inventory/create">
<?= csrf_field() ?>
<div class="form-group">
    <label for="product_id">Sản phẩm <span class="required">*</span></label>
    <select id="product_id" name="product_id" class="form-control" required autofocus>
        <option value="">-- Chọn sản phẩm --</option>
        <?php foreach ($products as $p): ?>
            <option value="<?= e($p["id"]) ?>" <?= (int)($old["product_id"] ?? 0) === (int)$p["id"] ? "selected" : "" ?>>
                <?= e($p["name"]) ?> (Tồn kho hiện tại: <?= (int)$p["quantity"] ?>)
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label for="type">Loại phiếu <span class="required">*</span></label>
    <select id="type" name="type" class="form-control" required>
        <option value="IN" <?= ($old["type"] ?? "IN") === "IN" ? "selected" : "" ?>>Nhập kho (+) - Tăng số lượng tồn</option>
        <option value="OUT" <?= ($old["type"] ?? "") === "OUT" ? "selected" : "" ?>>Xuất kho (-) - Giảm số lượng tồn</option>
    </select>
</div>

<div class="form-group">
    <label for="quantity">Số lượng <span class="required">*</span></label>
    <input type="number" id="quantity" name="quantity" class="form-control" min="1" value="<?= e($old["quantity"] ?? "1") ?>" required>
</div>

<div class="form-group">
    <label for="note">Lý do / Ghi chú</label>
    <textarea id="note" name="note" class="form-control" rows="3" placeholder="Ví dụ: Nhập hàng đợt 1 tháng 11, Xuất bảo hành, Xuất bán buôn..."><?= e($old["note"] ?? "") ?></textarea>
</div>

<div class="form-actions">
    <button type="submit" class="btn primary">Xác nhận tạo phiếu</button>
    <a href="/inventory" class="btn secondary">Hủy</a>
</div>
</form>
</div>

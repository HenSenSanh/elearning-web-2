<div class="panel narrow">
<h2>Sửa thông tin khách hàng #<?= e($customer['id']) ?></h2>
<?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="POST" action="/customers/edit?id=<?= e($customer['id']) ?>">
<?= csrf_field() ?>
<div class="form-group">
    <label for="name">Họ và tên <span class="required">*</span></label>
    <input type="text" id="name" name="name" class="form-control" value="<?= e($customer['name'] ?? '') ?>" required autofocus>
</div>
<div class="form-group">
    <label for="email">Địa chỉ Email <span class="required">*</span></label>
    <input type="email" id="email" name="email" class="form-control" value="<?= e($customer['email'] ?? '') ?>" required>
</div>
<div class="form-group">
    <label for="phone">Số điện thoại</label>
    <input type="text" id="phone" name="phone" class="form-control" value="<?= e($customer['phone'] ?? '') ?>">
</div>
<div class="form-group">
    <label for="address">Địa chỉ liên hệ</label>
    <textarea id="address" name="address" class="form-control" rows="3"><?= e($customer['address'] ?? '') ?></textarea>
</div>
<div class="form-group checkbox-group">
    <label><input type="checkbox" name="status" value="1" <?= (!empty($customer['status'])) ? 'checked' : '' ?>> Kích hoạt tài khoản</label>
</div>
<div class="form-actions">
    <button type="submit" class="btn primary">Cập nhật</button>
    <a href="/customers" class="btn secondary">Hủy</a>
</div>
</form>
</div>

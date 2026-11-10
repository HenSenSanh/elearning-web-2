<div class="panel narrow">
<h2>Sửa thương hiệu #<?= e($brand['id']) ?></h2>
<?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="POST" action="/brands/edit?id=<?= e($brand['id']) ?>">
<?= csrf_field() ?>
<div class="form-group">
    <label for="name">Tên thương hiệu <span class="required">*</span></label>
    <input type="text" id="name" name="name" class="form-control" value="<?= e($brand['name'] ?? '') ?>" required autofocus>
</div>
<div class="form-group">
    <label for="description">Mô tả</label>
    <textarea id="description" name="description" class="form-control" rows="3"><?= e($brand['description'] ?? '') ?></textarea>
</div>
<div class="form-group checkbox-group">
    <label><input type="checkbox" name="status" value="1" <?= (!empty($brand['status'])) ? 'checked' : '' ?>> Hiển thị</label>
</div>
<div class="form-actions">
    <button type="submit" class="btn primary">Cập nhật</button>
    <a href="/brands" class="btn secondary">Hủy</a>
</div>
</form>
</div>

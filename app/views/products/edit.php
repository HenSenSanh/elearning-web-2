<div class="panel form-panel">
<?php if (!empty($errors)): ?><div class="alert error"><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($msg = get_flash("success")): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>

<form method="POST" action="/products/edit?id=<?= e($product["id"]) ?>" enctype="multipart/form-data" id="productForm">
<?= csrf_field() ?>
<div class="grid-2">
<div class="form-group"><label>Tên sản phẩm</label><input type="text" name="name" id="product_name" value="<?= e($product["name"]) ?>"><small class="field-error" id="productNameError"></small></div>
<div class="form-group"><label>Danh mục</label><select name="category_id" id="category_id"><option value="">-- Chọn danh mục --</option><?php foreach ($categories as $category): ?><option value="<?= e($category["id"]) ?>" <?= (int)$product["category_id"] === (int)$category["id"] ? "selected" : "" ?>><?= e($category["name"]) ?></option><?php endforeach; ?></select><small class="field-error" id="categoryError"></small></div>
<div class="form-group"><label>Thương hiệu</label><select name="brand_id" id="brand_id"><option value="">-- Chọn thương hiệu --</option><?php foreach ($brands as $brand): ?><option value="<?= e($brand["id"]) ?>" <?= (int)($product["brand_id"] ?? 0) === (int)$brand["id"] ? "selected" : "" ?>><?= e($brand["name"]) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>SKU / Mã sản phẩm</label><input type="text" name="sku" value="<?= e($product["sku"]) ?>"></div>
<div class="form-group">
    <label>Hình ảnh chính</label>
    <input type="file" name="image" id="image" accept="image/*">
    <small class="help">Bỏ trống nếu không muốn đổi hình.</small>
    <small class="field-error" id="imageError"></small>
    <?php if (!empty($product["image_path"])): ?>
        <div class="preview-box"><img class="preview" src="/<?= e($product["image_path"]) ?>" alt="<?= e($product["name"]) ?>"></div>
    <?php endif; ?>
</div>
<div class="form-group">
    <label>Bộ sưu tập ảnh bổ sung (Gallery)</label>
    <input type="file" name="gallery_images[]" multiple accept="image/*">
    <small class="help">Chọn nhiều ảnh để thêm vào bộ sưu tập sản phẩm.</small>
</div>
<div class="form-group"><label>Số lượng</label><input type="number" name="quantity" id="quantity" min="0" value="<?= e($product["quantity"]) ?>"><small class="field-error" id="quantityError"></small></div>
<div class="form-group"><label>Đơn giá</label><input type="number" name="unit_price" id="unit_price" min="0" step="1000" value="<?= e($product["unit_price"]) ?>"><small class="field-error" id="priceError"></small></div>
</div>
<div class="form-group"><label>Mô tả</label><textarea name="description" rows="5"><?= e($product["description"]) ?></textarea></div>
<label class="checkbox"><input type="checkbox" name="status" <?= $product["status"] ? "checked" : "" ?>> Hiển thị sản phẩm</label>
<div class="actions"><button class="btn primary" type="submit">Cập nhật sản phẩm</button><a class="btn secondary" href="/products">Quay lại</a></div>
</form>

<?php if (!empty($galleryImages)): ?>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #e2e8f0;">
<h3>Bộ sưu tập ảnh hiện tại (<?= count($galleryImages) ?> ảnh)</h3>
<div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 12px;">
    <?php foreach ($galleryImages as $gImg): ?>
        <div style="position: relative; border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px; background: #fff;">
            <img src="/<?= e($gImg["image_path"]) ?>" style="width: 100px; height: 100px; object-fit: cover; border-radius: 4px; display: block;">
            <form method="POST" action="/products/delete-image" style="margin-top: 4px; text-align: center;" onsubmit="return confirm('Bạn chắc chắn muốn xóa ảnh này?')">
                <?= csrf_field() ?>
                <input type="hidden" name="image_id" value="<?= e($gImg["id"]) ?>">
                <input type="hidden" name="product_id" value="<?= e($product["id"]) ?>">
                <button type="submit" class="btn small danger" style="padding: 2px 6px; font-size: 11px;">Xóa</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

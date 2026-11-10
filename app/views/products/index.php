<div class="page-actions">
    <a class="btn primary" href="/products/create">+ Thêm sản phẩm</a>
    <a class="btn secondary" href="/products/export?<?= http_build_query($_GET) ?>">📥 Xuất CSV</a>
</div>
<?php if ($msg = get_flash("error")): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = get_flash("success")): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
<div class="panel filter-panel">
<form method="GET" action="/products" class="filter-form">
<input type="text" name="keyword" placeholder="Tìm tên sản phẩm hoặc SKU" value="<?= e($keyword) ?>">
<select name="category_id">
<option value="0">Tất cả danh mục</option>
<?php foreach ($categories as $category): ?>
<option value="<?= e($category["id"]) ?>" <?= (int)$categoryId === (int)$category["id"] ? "selected" : "" ?>><?= e($category["name"]) ?></option>
<?php endforeach; ?>
</select>
<select name="brand_id">
<option value="0">Tất cả thương hiệu</option>
<?php foreach ($brands as $brand): ?>
<option value="<?= e($brand["id"]) ?>" <?= (int)($brandId ?? 0) === (int)$brand["id"] ? "selected" : "" ?>><?= e($brand["name"]) ?></option>
<?php endforeach; ?>
</select>
<button class="btn secondary" type="submit">Tìm kiếm</button>
</form>
</div>
<div class="panel">
<table class="table product-table">
<thead><tr><th>Hình</th><th>Thông tin sản phẩm</th><th>Danh mục / Hãng</th><th>SL</th><th>Đơn giá</th><th>Trạng thái</th><th width="180">Thao tác</th></tr></thead>
<tbody>
<?php foreach ($products as $product): ?>
<tr>
<td>
<?php if (!empty($product["image_path"])): ?><img class="thumb" src="/<?= e($product["image_path"]) ?>" alt="<?= e($product["name"]) ?>">
<?php else: ?><div class="no-image">No image</div><?php endif; ?>
</td>
<td><strong><?= e($product["name"]) ?></strong><small>SKU: <?= e($product["sku"] ?: "N/A") ?></small><small><?= e($product["description"]) ?></small></td>
<td>
    <div><span class="badge secondary"><?= e($product["category_name"]) ?></span></div>
    <?php if (!empty($product["brand_name"])): ?>
        <div style="margin-top: 4px;"><span class="badge info"><?= e($product["brand_name"]) ?></span></div>
    <?php endif; ?>
</td>
<td><?= number_format((int)$product["quantity"]) ?></td>
<td><?= number_format((float)$product["unit_price"], 0, ',', '.') ?> đ</td>
<td><span class="badge <?= $product["status"] ? 'success' : 'muted' ?>"><?= $product["status"] ? "Hiển thị" : "Ẩn" ?></span></td>
<td>
<a class="btn small secondary" href="/products/edit?id=<?= e($product["id"]) ?>">Sửa</a>
<form class="inline" method="POST" action="/products/delete" onsubmit="return confirmDelete('Bạn chắc chắn muốn xóa sản phẩm này?')">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= e($product["id"]) ?>"><button class="btn small danger" type="submit">Xóa</button>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php if (empty($products)): ?><tr><td colspan="7" class="empty">Không có sản phẩm phù hợp.</td></tr><?php endif; ?>
</tbody>
</table>
<?php if (!empty($pagination) && $pagination["totalPages"] > 1): ?>
<div class="pagination-wrapper">
    <div class="pagination-info">
        Hiển thị trang <strong><?= (int)$pagination["currentPage"] ?></strong> / <strong><?= (int)$pagination["totalPages"] ?></strong> (Tổng <?= (int)$pagination["totalItems"] ?> sản phẩm)
    </div>
    <div class="pagination">
        <?php if ($pagination["currentPage"] > 1): ?>
            <a class="page-link" href="/products?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">« Đầu</a>
            <a class="page-link" href="/products?<?= http_build_query(array_merge($_GET, ['page' => $pagination["currentPage"] - 1])) ?>">‹ Trước</a>
        <?php endif; ?>

        <?php for ($p = 1; $p <= $pagination["totalPages"]; $p++): ?>
            <a class="page-link <?= $p === (int)$pagination["currentPage"] ? 'active' : '' ?>" href="/products?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
        <?php endfor; ?>

        <?php if ($pagination["currentPage"] < $pagination["totalPages"]): ?>
            <a class="page-link" href="/products?<?= http_build_query(array_merge($_GET, ['page' => $pagination["currentPage"] + 1])) ?>">Sau ›</a>
            <a class="page-link" href="/products?<?= http_build_query(array_merge($_GET, ['page' => $pagination["totalPages"]])) ?>">Cuối »</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>

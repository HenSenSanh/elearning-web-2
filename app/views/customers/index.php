<div class="page-actions"><a class="btn primary" href="/customers/create">+ Thêm khách hàng</a></div>
<?php if ($msg = get_flash("error")): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = get_flash("success")): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>

<div class="panel filter-panel">
<form method="GET" action="/customers" class="filter-form">
<input type="text" name="keyword" placeholder="Tìm tên, email hoặc số điện thoại..." value="<?= e($keyword ?? '') ?>">
<button class="btn secondary" type="submit">Tìm kiếm</button>
</form>
</div>

<div class="panel">
<table class="table">
<thead>
    <tr>
        <th>ID</th>
        <th>Họ tên</th>
        <th>Email</th>
        <th>Số điện thoại</th>
        <th>Địa chỉ</th>
        <th>Trạng thái</th>
        <th width="180">Thao tác</th>
    </tr>
</thead>
<tbody>
<?php foreach ($customers as $cust): ?>
<tr>
    <td>#<?= e($cust["id"]) ?></td>
    <td><strong><?= e($cust["name"]) ?></strong></td>
    <td><?= e($cust["email"]) ?></td>
    <td><?= e($cust["phone"] ?: "—") ?></td>
    <td><?= e($cust["address"] ?: "—") ?></td>
    <td><span class="badge <?= $cust["status"] ? 'success' : 'muted' ?>"><?= $cust["status"] ? "Hoạt động" : "Khóa" ?></span></td>
    <td>
        <a class="btn small secondary" href="/customers/edit?id=<?= e($cust["id"]) ?>">Sửa</a>
        <form class="inline" method="POST" action="/customers/delete" onsubmit="return confirmDelete('Bạn chắc chắn muốn xóa khách hàng này?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($cust["id"]) ?>">
            <button class="btn small danger" type="submit">Xóa</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($customers)): ?>
    <tr><td colspan="7" class="empty">Chưa có thông tin khách hàng nào.</td></tr>
<?php endif; ?>
</tbody>
</table>

<?php if (!empty($pagination) && $pagination["totalPages"] > 1): ?>
<div class="pagination-wrapper">
    <div class="pagination-info">
        Trang <strong><?= (int)$pagination["currentPage"] ?></strong> / <strong><?= (int)$pagination["totalPages"] ?></strong> (Tổng <?= (int)$pagination["totalItems"] ?> khách hàng)
    </div>
    <div class="pagination">
        <?php if ($pagination["currentPage"] > 1): ?>
            <a class="page-link" href="/customers?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">« Đầu</a>
            <a class="page-link" href="/customers?<?= http_build_query(array_merge($_GET, ['page' => $pagination["currentPage"] - 1])) ?>">‹ Trước</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $pagination["totalPages"]; $p++): ?>
            <a class="page-link <?= $p === (int)$pagination["currentPage"] ? 'active' : '' ?>" href="/customers?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pagination["currentPage"] < $pagination["totalPages"]): ?>
            <a class="page-link" href="/customers?<?= http_build_query(array_merge($_GET, ['page' => $pagination["currentPage"] + 1])) ?>">Sau ›</a>
            <a class="page-link" href="/customers?<?= http_build_query(array_merge($_GET, ['page' => $pagination["totalPages"]])) ?>">Cuối »</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>

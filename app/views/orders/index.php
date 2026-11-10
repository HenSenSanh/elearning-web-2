<div class="page-actions">
    <a class="btn primary" href="/orders/create">+ Tạo đơn hàng mới</a>
    <a class="btn secondary" href="/orders/export">📥 Xuất CSV đơn hàng</a>
</div>
<?php if ($msg = get_flash("error")): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = get_flash("success")): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>

<div class="panel filter-panel">
<form method="GET" action="/orders" class="filter-form">
<input type="text" name="keyword" placeholder="Mã đơn hàng, tên hoặc SĐT khách..." value="<?= e($keyword ?? '') ?>">
<select name="status">
    <option value="">Tất cả trạng thái</option>
    <option value="pending" <?= ($status ?? '') === 'pending' ? 'selected' : '' ?>>Chờ xử lý (Pending)</option>
    <option value="processing" <?= ($status ?? '') === 'processing' ? 'selected' : '' ?>>Đang xử lý (Processing)</option>
    <option value="completed" <?= ($status ?? '') === 'completed' ? 'selected' : '' ?>>Hoàn thành (Completed)</option>
    <option value="cancelled" <?= ($status ?? '') === 'cancelled' ? 'selected' : '' ?>>Đã hủy (Cancelled)</option>
</select>
<button class="btn secondary" type="submit">Tìm kiếm</button>
</form>
</div>

<div class="panel">
<table class="table">
<thead>
    <tr>
        <th>Mã đơn hàng</th>
        <th>Khách hàng</th>
        <th>Tổng tiền</th>
        <th>Trạng thái</th>
        <th>Thời gian</th>
        <th width="140">Thao tác</th>
    </tr>
</thead>
<tbody>
<?php foreach ($orders as $ord): ?>
<tr>
    <td><strong><?= e($ord["order_code"]) ?></strong></td>
    <td>
        <div><strong><?= e($ord["customer_name"]) ?></strong></div>
        <small style="color: #64748b;"><?= e($ord["customer_phone"]) ?> | <?= e($ord["customer_email"]) ?></small>
    </td>
    <td><strong style="color: #0284c7;"><?= number_format((float)$ord["total_amount"], 0, ',', '.') ?> đ</strong></td>
    <td>
        <?php
        $stClass = "secondary";
        $stText = $ord["status"];
        if ($ord["status"] === "pending") { $stClass = "warning"; $stText = "Chờ xử lý"; }
        elseif ($ord["status"] === "processing") { $stClass = "info"; $stText = "Đang xử lý"; }
        elseif ($ord["status"] === "completed") { $stClass = "success"; $stText = "Hoàn thành"; }
        elseif ($ord["status"] === "cancelled") { $stClass = "danger"; $stText = "Đã hủy"; }
        ?>
        <span class="badge <?= $stClass ?>"><?= $stText ?></span>
    </td>
    <td><?= date("d/m/Y H:i", strtotime($ord["created_at"])) ?></td>
    <td>
        <a class="btn small primary" href="/orders/detail?id=<?= e($ord["id"]) ?>">Xem chi tiết</a>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($orders)): ?>
    <tr><td colspan="6" class="empty">Chưa có đơn hàng nào.</td></tr>
<?php endif; ?>
</tbody>
</table>

<?php if (!empty($pagination) && $pagination["totalPages"] > 1): ?>
<div class="pagination-wrapper">
    <div class="pagination-info">
        Trang <strong><?= (int)$pagination["currentPage"] ?></strong> / <strong><?= (int)$pagination["totalPages"] ?></strong> (Tổng <?= (int)$pagination["totalItems"] ?> đơn)
    </div>
    <div class="pagination">
        <?php if ($pagination["currentPage"] > 1): ?>
            <a class="page-link" href="/orders?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">« Đầu</a>
            <a class="page-link" href="/orders?<?= http_build_query(array_merge($_GET, ['page' => $pagination["currentPage"] - 1])) ?>">‹ Trước</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $pagination["totalPages"]; $p++): ?>
            <a class="page-link <?= $p === (int)$pagination["currentPage"] ? 'active' : '' ?>" href="/orders?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pagination["currentPage"] < $pagination["totalPages"]): ?>
            <a class="page-link" href="/orders?<?= http_build_query(array_merge($_GET, ['page' => $pagination["currentPage"] + 1])) ?>">Sau ›</a>
            <a class="page-link" href="/orders?<?= http_build_query(array_merge($_GET, ['page' => $pagination["totalPages"]])) ?>">Cuối »</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>

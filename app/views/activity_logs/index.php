<?php if ($msg = get_flash("error")): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = get_flash("success")): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>

<div class="panel">
<h2>Nhật ký hoạt động hệ thống (Audit Logs)</h2>
<p style="color: #64748b; font-size: 13px; margin-bottom: 16px;">Ghi nhận chi tiết tất cả các thao tác quan trọng từ tài khoản quản trị viên.</p>

<table class="table">
<thead>
    <tr>
        <th>ID</th>
        <th>Thời gian</th>
        <th>Quản trị viên</th>
        <th>Hành động</th>
        <th>Chi tiết</th>
        <th>Địa chỉ IP</th>
    </tr>
</thead>
<tbody>
<?php foreach ($logs as $log): ?>
<tr>
    <td>#<?= e($log["id"]) ?></td>
    <td><?= date("d/m/Y H:i:s", strtotime($log["created_at"])) ?></td>
    <td><strong><?= e($log["admin_username"] ?? "Hệ thống") ?></strong></td>
    <td><span class="badge info"><?= e($log["action"]) ?></span></td>
    <td><?= e($log["description"]) ?></td>
    <td><small style="color: #64748b;"><?= e($log["ip_address"]) ?></small></td>
</tr>
<?php endforeach; ?>
<?php if (empty($logs)): ?>
    <tr><td colspan="6" class="empty">Chưa có nhật ký hoạt động nào.</td></tr>
<?php endif; ?>
</tbody>
</table>

<?php if (!empty($pagination) && $pagination["totalPages"] > 1): ?>
<div class="pagination-wrapper">
    <div class="pagination-info">
        Trang <strong><?= (int)$pagination["currentPage"] ?></strong> / <strong><?= (int)$pagination["totalPages"] ?></strong> (Tổng <?= (int)$pagination["totalItems"] ?> bản ghi)
    </div>
    <div class="pagination">
        <?php if ($pagination["currentPage"] > 1): ?>
            <a class="page-link" href="/activity-logs?page=1">« Đầu</a>
            <a class="page-link" href="/activity-logs?page=<?= $pagination["currentPage"] - 1 ?>">‹ Trước</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $pagination["totalPages"]; $p++): ?>
            <a class="page-link <?= $p === (int)$pagination["currentPage"] ? 'active' : '' ?>" href="/activity-logs?page=<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pagination["currentPage"] < $pagination["totalPages"]): ?>
            <a class="page-link" href="/activity-logs?page=<?= $pagination["currentPage"] + 1 ?>">Sau ›</a>
            <a class="page-link" href="/activity-logs?page=<?= $pagination["totalPages"] ?>">Cuối »</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>

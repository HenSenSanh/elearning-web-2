<div class="page-actions">
    <a class="btn primary" href="/inventory/create">+ Tạo phiếu Nhập / Xuất kho</a>
    <a class="btn secondary" href="/inventory/export">📥 Xuất CSV lịch sử kho</a>
</div>
<?php if ($msg = get_flash("error")): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = get_flash("success")): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>

<div class="metrics" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
    <div class="card" style="background: #fff; padding: 16px; border-radius: 8px; border-left: 4px solid #10b981;">
        <span style="color: #64748b; font-size: 13px;">Tổng hàng đã nhập</span>
        <h2 style="margin: 8px 0 0; color: #10b981;">+<?= number_format($totalIn) ?></h2>
    </div>
    <div class="card" style="background: #fff; padding: 16px; border-radius: 8px; border-left: 4px solid #ef4444;">
        <span style="color: #64748b; font-size: 13px;">Tổng hàng đã xuất</span>
        <h2 style="margin: 8px 0 0; color: #ef4444;">-<?= number_format($totalOut) ?></h2>
    </div>
</div>

<div class="panel">
<table class="table">
<thead>
    <tr>
        <th>ID</th>
        <th>Thời gian</th>
        <th>Sản phẩm / SKU</th>
        <th>Loại phiếu</th>
        <th>Số lượng</th>
        <th>Người thực hiện</th>
        <th>Ghi chú</th>
    </tr>
</thead>
<tbody>
<?php foreach ($logs as $log): ?>
<tr>
    <td>#<?= e($log["id"]) ?></td>
    <td><?= date("d/m/Y H:i", strtotime($log["created_at"])) ?></td>
    <td>
        <strong><?= e($log["product_name"]) ?></strong>
        <?php if (!empty($log["product_sku"])): ?>
            <small style="color: #64748b; display: block;">SKU: <?= e($log["product_sku"]) ?></small>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($log["type"] === "IN"): ?>
            <span class="badge success">Nhập kho (+)</span>
        <?php else: ?>
            <span class="badge danger" style="background: #ef4444; color: #fff;">Xuất kho (-)</span>
        <?php endif; ?>
    </td>
    <td><strong><?= number_format((int)$log["quantity"]) ?></strong></td>
    <td><?= e($log["admin_username"] ?? "Hệ thống") ?></td>
    <td><?= e($log["note"] ?: "—") ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($logs)): ?>
    <tr><td colspan="7" class="empty">Chưa có giao dịch nhập xuất kho nào.</td></tr>
<?php endif; ?>
</tbody>
</table>

<?php if (!empty($pagination) && $pagination["totalPages"] > 1): ?>
<div class="pagination-wrapper">
    <div class="pagination-info">
        Trang <strong><?= (int)$pagination["currentPage"] ?></strong> / <strong><?= (int)$pagination["totalPages"] ?></strong> (Tổng <?= (int)$pagination["totalItems"] ?> lượt)
    </div>
    <div class="pagination">
        <?php if ($pagination["currentPage"] > 1): ?>
            <a class="page-link" href="/inventory?page=1">« Đầu</a>
            <a class="page-link" href="/inventory?page=<?= $pagination["currentPage"] - 1 ?>">‹ Trước</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $pagination["totalPages"]; $p++): ?>
            <a class="page-link <?= $p === (int)$pagination["currentPage"] ? 'active' : '' ?>" href="/inventory?page=<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pagination["currentPage"] < $pagination["totalPages"]): ?>
            <a class="page-link" href="/inventory?page=<?= $pagination["currentPage"] + 1 ?>">Sau ›</a>
            <a class="page-link" href="/inventory?page=<?= $pagination["totalPages"] ?>">Cuối »</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>

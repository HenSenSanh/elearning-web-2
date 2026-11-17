<div class="page-actions actions">
    <a class="btn secondary" href="/inventory">Về chi tiết tồn kho</a>
    <a class="btn secondary" href="/inventory/movements?<?= e(http_build_query($filters + ['export'=>'csv'])) ?>">Xuất CSV sổ biến động</a>
</div>
<div class="panel">
    <p>Sổ biến động kho <strong>chỉ đọc</strong> (read-only), phân trang thực trên CSDL theo sản phẩm, kho và khoảng ngày. Xem báo cáo không làm thay đổi số dư tồn.</p>
    <form method="get" action="/inventory/movements" class="filter-form">
        <input name="keyword" placeholder="Số phiếu, tên SP hoặc SKU" value="<?= e($filters['keyword'] ?? '') ?>">
        <select name="product_id" aria-label="Sản phẩm">
            <option value="0">Tất cả sản phẩm</option>
            <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)($filters['product_id'] ?? 0)===(int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['sku'] ?: '#' . $p['id']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <select name="warehouse_id" aria-label="Kho">
            <option value="0">Tất cả kho</option>
            <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= (int)($filters['warehouse_id'] ?? 0)===(int)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from_date" aria-label="Từ ngày" value="<?= e($filters['from_date'] ?? '') ?>">
        <input type="date" name="to_date" aria-label="Đến ngày" value="<?= e($filters['to_date'] ?? '') ?>">
        <select name="per_page" aria-label="Số dòng">
            <?php foreach ([5,10,20,50] as $size): ?>
                <option value="<?= $size ?>" <?= $result['per_page']===$size ? 'selected' : '' ?>><?= $size ?> dòng</option>
            <?php endforeach; ?>
        </select>
        <button class="btn secondary">Lọc sổ</button>
    </form>
</div>
<div class="panel table-scroll">
    <table class="table">
        <thead><tr><th>Ngày chứng từ</th><th>Số phiếu</th><th>Sản phẩm</th><th>Kho / vị trí</th><th>Lô</th><th>Biến động</th><th>Đơn giá chụp</th><th>Người ghi sổ</th></tr></thead>
        <tbody>
        <?php foreach ($result['items'] as $m): $qty = (int)$m['quantity']; ?>
            <tr>
                <td><?= e($m['document_date']) ?><small>Ghi sổ: <?= e($m['posted_at'] ?: $m['created_at']) ?></small></td>
                <td>
                    <?php if (can('documents.view')): ?>
                        <a href="/documents/show?id=<?= (int)$m['document_id'] ?>"><strong><?= e($m['document_number']) ?></strong></a>
                    <?php else: ?>
                        <strong><?= e($m['document_number']) ?></strong>
                    <?php endif; ?>
                    <small><?= e(document_type_label($m['document_type'])) ?></small>
                </td>
                <td><strong><?= e($m['product_name']) ?></strong><small><?= e($m['product_sku'] ?: 'Chưa có SKU') ?></small></td>
                <td><?= e($m['warehouse_name']) ?><small><?= e($m['location_code']) ?></small></td>
                <td><?= e($m['lot_code']) ?></td>
                <td><span class="badge <?= $qty >= 0 ? 'success' : 'warning' ?>"><?= $qty >= 0 ? '+' . number_format($qty) : number_format($qty) ?> <?= e($m['unit_name']) ?></span></td>
                <td><?= inventory_money(inventory_price_cents((string)$m['unit_price'])) ?></td>
                <td><?= e($m['poster_username'] ?: 'Hệ thống') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$result['items']): ?>
            <tr><td colspan="8" class="empty">Chưa có biến động kho phù hợp.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?= pagination_links($result, '/inventory/movements', $filters) ?>
</div>

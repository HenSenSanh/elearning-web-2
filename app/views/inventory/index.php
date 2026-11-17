<div class="page-actions actions">
    <a class="btn primary" href="/inventory/movements">Sổ biến động kho (Read-only)</a>
    <a class="btn secondary" href="/inventory?<?= e(http_build_query($filters + ['export'=>'csv'])) ?>">Xuất CSV tồn kho</a>
</div>
<div class="panel">
    <p>Tồn thực tế theo <strong>sản phẩm · kho / vị trí · lô</strong>. Chỉ phiếu đã ghi sổ làm thay đổi số lượng.</p>
    <p class="hint">
        Đối soát tự động (<code>stock_balances = SUM(stock_movements) = nhập ghi sổ - xuất ghi sổ</code>):
        <?php if (!empty($reconciliation['is_balanced'])): ?>
            <span class="badge success">Khớp 100%</span>
            (Tổng tồn: <strong><?= number_format((int)$reconciliation['total_balance']) ?></strong> trên <?= (int)$reconciliation['key_count'] ?> bộ khóa)
        <?php else: ?>
            <span class="badge warning">Phát hiện lệch</span>
            (Tồn: <?= (int)$reconciliation['total_balance'] ?>, Biến động: <?= (int)$reconciliation['total_movement'] ?>, Phiếu ghi sổ: <?= (int)$reconciliation['total_posted_net'] ?>)
        <?php endif; ?>
    </p>
    <form method="get" action="/inventory" class="filter-form">
        <input name="keyword" placeholder="Tên sản phẩm, SKU hoặc mã lô" value="<?= e($filters['keyword'] ?? '') ?>">
        <select name="warehouse_id"><option value="0">Tất cả kho</option>
            <?php foreach ($warehouses as $warehouse): ?><option value="<?= (int)$warehouse['id'] ?>" <?= (int)($filters['warehouse_id'] ?? 0)===(int)$warehouse['id']?'selected':'' ?>><?= e($warehouse['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="per_page" aria-label="Số dòng"><?php foreach ([5,10,20,50] as $size): ?><option value="<?= $size ?>" <?= $result['per_page']===$size?'selected':'' ?>><?= $size ?> dòng</option><?php endforeach; ?></select>
        <button class="btn secondary">Lọc tồn kho</button>
    </form>
</div>
<div class="panel table-scroll">
    <p>Tổng theo đơn vị (toàn bộ kết quả lọc):
    <?php foreach ($result['quantity_by_unit'] as $unit): ?><span class="badge muted"><?= number_format((int)$unit['quantity']) ?> <?= e($unit['unit_name']) ?></span> <?php endforeach; ?>
    <?php if (!$result['quantity_by_unit']): ?>Chưa có số dư.<?php endif; ?></p>
    <table class="table"><thead><tr><th>Sản phẩm</th><th>Kho / vị trí</th><th>Lô</th><th>Hạn dùng</th><th>Tồn</th><th>Đơn vị</th></tr></thead><tbody>
    <?php foreach ($result['items'] as $item): ?>
        <tr><td><strong><?= e($item['name']) ?></strong><small><?= e($item['sku']) ?></small></td>
        <td><?= e($item['warehouse_name']) ?><small><?= e($item['location_code']) ?></small></td><td><?= e($item['lot_code']) ?></td>
        <td><?= e($item['expires_on'] ?? 'Không giới hạn') ?><?php if ($item['expires_on'] && $item['expires_on']<date('Y-m-d')): ?><small>Đã hết hạn</small><?php endif; ?></td>
        <td><strong><?= number_format((int)$item['quantity']) ?></strong></td><td><?= e($item['unit_name']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$result['items']): ?><tr><td colspan="6" class="empty">Chưa có số dư phù hợp. Tạo và ghi sổ phiếu nhập để có tồn kho.</td></tr><?php endif; ?>
    </tbody></table>
    <?= pagination_links($result, '/inventory', $filters) ?>
</div>

<div class="panel">
    <div class="tabs">
        <?php foreach (warehouse_tabs() as $key => $label): ?>
            <a class="btn <?= $tab === $key ? 'primary' : 'secondary' ?>" href="/warehouse-catalog?tab=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <p class="help">Vị trí thuộc một kho và gồm khu, kệ, ô. Lô gắn với sản phẩm, có thể xuất hiện tại nhiều vị trí. Danh mục đã ngừng sử dụng không thể chọn trên chứng từ/sản phẩm mới nhưng vẫn giữ nguyên toàn vẹn lịch sử; chỉ được xóa khi chưa phát sinh tham chiếu (lô mặc định <code>NOLOT</code> luôn được bảo vệ).</p>
    <?php if ($error): ?>
        <div class="alert error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
</div>

<div class="grid-2 catalog-grid">
    <div class="panel">
        <h2>Thêm <?= e(warehouse_tabs()[$tab]) ?></h2>
        <form method="POST" action="/warehouse-catalog?tab=<?= e($tab) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <?php if ($tab === 'locations'): ?>
                <div class="form-group">
                    <label for="warehouse_id">Kho</label>
                    <select name="warehouse_id" id="warehouse_id" required>
                        <?php foreach ($options['warehouses'] as $w): ?>
                            <option value="<?= e($w['id']) ?>" <?= (int)($old['warehouse_id'] ?? 0) === (int)$w['id'] ? 'selected' : '' ?>>
                                <?= e($w['code'] . ' · ' . $w['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if ($tab === 'lots'): ?>
                <div class="form-group">
                    <label for="lot_product">Sản phẩm</label>
                    <select name="product_id" id="lot_product" required>
                        <?php foreach ($options['products'] as $p): ?>
                            <option value="<?= e($p['id']) ?>" <?= (int)($old['product_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e($p['name']) ?> (<?= e($p['sku']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="code">Mã</label>
                <input name="code" id="code" required maxlength="<?= $tab === 'lots' ? 100 : ($tab === 'locations' ? 50 : 30) ?>" value="<?= e($old['code'] ?? '') ?>">
            </div>
            <?php if (in_array($tab, ['warehouses', 'units'], true)): ?>
                <div class="form-group">
                    <label for="name">Tên</label>
                    <input name="name" id="name" required maxlength="<?= $tab === 'units' ? 100 : 150 ?>" value="<?= e($old['name'] ?? '') ?>">
                </div>
            <?php endif; ?>
            <?php if ($tab === 'locations'): ?>
                <?php foreach (['zone' => 'Khu', 'rack' => 'Kệ', 'bin' => 'Ô'] as $key => $label): ?>
                    <div class="form-group">
                        <label for="<?= $key ?>"><?= $label ?></label>
                        <input name="<?= $key ?>" id="<?= $key ?>" maxlength="50" value="<?= e($old[$key] ?? '') ?>">
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($tab === 'lots'): ?>
                <div class="form-group">
                    <label for="expires_on">Hạn sử dụng (bỏ trống nếu không áp dụng)</label>
                    <input type="date" name="expires_on" id="expires_on" value="<?= e($old['expires_on'] ?? '') ?>">
                </div>
            <?php endif; ?>
            <button class="btn primary" type="submit">Thêm danh mục</button>
        </form>
    </div>

    <div class="panel table-scroll">
        <h2>Danh sách</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Mã</th>
                    <th>Thông tin</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['items'] as $item): ?>
                    <?php
                    $isActive = (int)($item['is_active'] ?? 1) === 1;
                    $refCount = (int)($item['ref_count'] ?? 0);
                    $isNoLot = $tab === 'lots' && ($item['code'] ?? '') === 'NOLOT';
                    ?>
                    <tr>
                        <td><strong><?= e($item['code']) ?></strong></td>
                        <td>
                            <?php if ($tab === 'locations'): ?>
                                <?= e($item['warehouse_name']) ?>
                                <?php if ((int)($item['warehouse_is_active'] ?? 1) === 0): ?>
                                    <span class="badge muted">Kho ngừng</span>
                                <?php endif; ?>
                                <form method="POST" action="/warehouse-catalog?tab=locations" class="actions" style="margin-top:6px">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                    <input name="zone" value="<?= e($item['zone']) ?>" placeholder="Khu" maxlength="50" style="max-width:90px" aria-label="Khu">
                                    <input name="rack" value="<?= e($item['rack']) ?>" placeholder="Kệ" maxlength="50" style="max-width:90px" aria-label="Kệ">
                                    <input name="bin" value="<?= e($item['bin']) ?>" placeholder="Ô" maxlength="50" style="max-width:90px" aria-label="Ô">
                                    <button class="btn secondary small" type="submit">Lưu</button>
                                </form>
                            <?php elseif ($tab === 'lots'): ?>
                                <?= e($item['product_name']) ?>
                                <?php if ($isNoLot): ?>
                                    <small>Hạn dùng: Không áp dụng (Mặc định)</small>
                                <?php else: ?>
                                    <form method="POST" action="/warehouse-catalog?tab=lots" class="actions" style="margin-top:6px">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <input type="date" name="expires_on" value="<?= e($item['expires_on'] ?? '') ?>" style="max-width:165px" aria-label="Hạn dùng">
                                        <button class="btn secondary small" type="submit">Lưu</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="POST" action="/warehouse-catalog?tab=<?= e($tab) ?>" class="actions">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                    <input name="name" required maxlength="<?= $tab === 'units' ? 100 : 150 ?>" value="<?= e($item['name']) ?>" aria-label="Tên">
                                    <button class="btn secondary small" type="submit">Lưu</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($refCount > 0): ?>
                                <small>Tham chiếu hiện có: <?= $refCount ?> bản ghi</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $isActive ? 'success' : 'muted' ?>">
                                <?= $isActive ? 'Đang dùng' : 'Ngừng dùng' ?>
                            </span>
                        </td>
                        <td class="row-actions">
                            <?php if ($isNoLot): ?>
                                <small>Mặc định hệ thống</small>
                            <?php else: ?>
                                <form method="POST" action="/warehouse-catalog?tab=<?= e($tab) ?>" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $isActive ? 0 : 1 ?>">
                                    <button class="btn secondary small" type="submit">
                                        <?= $isActive ? 'Ngừng sử dụng' : 'Kích hoạt lại' ?>
                                    </button>
                                </form>
                                <?php if ($refCount === 0): ?>
                                    <form method="POST" action="/warehouse-catalog?tab=<?= e($tab) ?>" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button class="btn danger small" type="submit">Xóa</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$result['items']): ?>
                    <tr><td colspan="4" class="empty">Chưa có dữ liệu.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?= pagination_links($result, '/warehouse-catalog', ['tab' => $tab]) ?>
    </div>
</div>

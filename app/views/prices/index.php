<div class="panel">
    <h2>Lịch sử giá mua / bán</h2>
    <p>Khoảng hiệu lực <strong>[Từ, Đến)</strong>: gồm thời điểm bắt đầu, không gồm thời điểm kết thúc. Giá chụp trên phiếu đã lập được giữ nguyên.</p>
    <form method="GET" action="/prices" class="filter-form">
        <select name="product_id" aria-label="Lọc sản phẩm">
            <option value="0">Tất cả sản phẩm</option>
            <?php foreach ($products as $product): ?>
                <option value="<?= e($product['id']) ?>" <?= $productId === (int)$product['id'] ? 'selected' : '' ?>>
                    <?= e($product['name']) ?> · <?= e($product['sku']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="price_type" aria-label="Lọc loại giá">
            <option value="">Tất cả loại giá</option>
            <option value="purchase" <?= ($priceType ?? '') === 'purchase' ? 'selected' : '' ?>>Giá nhập</option>
            <option value="sale" <?= ($priceType ?? '') === 'sale' ? 'selected' : '' ?>>Giá xuất</option>
        </select>
        <select name="per_page" aria-label="Số dòng">
            <?php foreach ([5, 10, 20, 50] as $size): ?>
                <option value="<?= $size ?>" <?= $result['per_page'] === $size ? 'selected' : '' ?>><?= $size ?> dòng</option>
            <?php endforeach; ?>
        </select>
        <button class="btn secondary" type="submit">Xem lịch sử</button>
    </form>
</div>

<?php if (can('prices.manage')): ?>
<?php
$oldAction = (string)($old['action'] ?? '');
$createOld = $oldAction === 'close_open' ? [] : $old;
$closeOld = $oldAction === 'close_open' ? $old : [];
?>
<?php if ($error): ?>
    <div class="alert error" role="alert"><?= e($error) ?></div>
<?php endif; ?>
<div class="grid-2">
    <div class="panel">
        <h2>Thêm khoảng giá mới</h2>
        <form method="POST" action="/prices">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label for="price_product">Sản phẩm</label>
                <select name="product_id" id="price_product" required>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= e($product['id']) ?>" <?= (int)($createOld['product_id'] ?? $productId) === (int)$product['id'] ? 'selected' : '' ?>>
                            <?= e($product['name']) ?> (<?= e($product['sku']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="price_type">Loại giá</label>
                    <select name="price_type" id="price_type">
                        <option value="purchase">Giá nhập</option>
                        <option value="sale" <?= ($createOld['price_type'] ?? $priceType) === 'sale' ? 'selected' : '' ?>>Giá xuất</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="amount">Đơn giá VND</label>
                    <input name="amount" id="amount" type="number" step="0.01" min="0" max="9999999999999.99" required value="<?= e($createOld['amount'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="valid_from">Hiệu lực từ (UTC+7)</label>
                    <input name="valid_from" id="valid_from" type="datetime-local" required value="<?= e($createOld['valid_from'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="valid_to">Hiệu lực đến (trống = vô hạn)</label>
                    <input name="valid_to" id="valid_to" type="datetime-local" value="<?= e($createOld['valid_to'] ?? '') ?>">
                </div>
            </div>
            <p class="help">Chặn mọi khoảng chồng lấn. Nếu sản phẩm đang có giá mở vô hạn, hãy dùng khung <strong>Đóng khoảng mở &amp; Lập giá kế tiếp</strong> bên cạnh.</p>
            <button class="btn secondary" type="submit">Lưu khoảng giá</button>
        </form>
    </div>

    <div class="panel">
        <h2>Đóng khoảng mở &amp; Lập mức giá kế tiếp (Nguyên tử)</h2>
        <form method="POST" action="/prices">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close_open">
            <div class="form-group">
                <label for="co_product">Sản phẩm</label>
                <select name="product_id" id="co_product" required>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= e($product['id']) ?>" <?= (int)($closeOld['product_id'] ?? $productId) === (int)$product['id'] ? 'selected' : '' ?>>
                            <?= e($product['name']) ?> (<?= e($product['sku']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="co_type">Loại giá</label>
                    <select name="price_type" id="co_type">
                        <option value="purchase">Giá nhập</option>
                        <option value="sale" <?= ($closeOld['price_type'] ?? $priceType) === 'sale' ? 'selected' : '' ?>>Giá xuất</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="co_amount">Mức giá mới VND</label>
                    <input name="amount" id="co_amount" type="number" step="0.01" min="0" max="9999999999999.99" required value="<?= e($closeOld['amount'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="co_from">Thời điểm chuyển tiếp T (UTC+7)</label>
                    <input name="valid_from" id="co_from" type="datetime-local" required value="<?= e($closeOld['valid_from'] ?? date('Y-m-d\TH:i')) ?>">
                </div>
                <div class="form-group">
                    <label for="co_to">Kết thúc mức mới (trống = vô hạn)</label>
                    <input name="valid_to" id="co_to" type="datetime-local" value="<?= e($closeOld['valid_to'] ?? '') ?>">
                </div>
            </div>
            <p class="help">Trong cùng 1 transaction (<code>FOR UPDATE</code>): tự động đóng khoảng giá đang mở (<code>valid_to IS NULL</code>) bằng <code>T</code> và mở khoảng mới từ <code>[T, Đến)</code> liền kề không khe hở.</p>
            <button class="btn primary" type="submit">Đóng giá cũ &amp; Áp dụng giá mới</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="panel table-scroll">
    <table class="table">
        <thead>
            <tr>
                <th>Sản phẩm</th>
                <th>Loại giá</th>
                <th>Giá / đơn vị</th>
                <th>Từ (gồm)</th>
                <th>Đến (không gồm)</th>
                <th>Người tạo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($result['items'] as $price): ?>
                <tr>
                    <td><strong><?= e($price['name']) ?></strong><small><?= e($price['sku']) ?></small></td>
                    <td><?= $price['price_type'] === 'purchase' ? 'Giá nhập' : 'Giá xuất' ?></td>
                    <td><?= number_format((float)$price['amount'], 2, ',', '.') ?> đ</td>
                    <td><?= e($price['valid_from']) ?></td>
                    <td><?= e($price['valid_to'] ?? 'Vô hạn') ?></td>
                    <td><?= e($price['creator_username'] ?? 'Hệ thống') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$result['items']): ?>
                <tr><td colspan="6" class="empty">Chưa thiết lập giá.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?= pagination_links($result, '/prices', ['product_id' => $productId, 'price_type' => $priceType ?? '']) ?>
</div>

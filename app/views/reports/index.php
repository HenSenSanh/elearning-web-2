<div class="page-actions actions" style="margin-top:0">
    <?php if (can('ai.ask')): ?><a class="btn secondary" href="/ai">Hỏi dữ liệu AI</a><?php endif; ?>
    <button type="button" id="btn-report-notify" class="btn secondary">Bật thông báo trình duyệt</button>
</div>

<div class="panel">
    <h2>Mẫu báo cáo Markdown → PDF (A4 ngang)</h2>
    <p class="help">Mẫu nằm trong <code>reports/templates/*.md</code>. Worker sẽ thay <code>{{generated_at}}</code> và từng marker <code>&lt;!-- ai-query: ... --&gt;</code> bằng kết quả SQL chỉ đọc, lưu file <code>.md</code> và xuất PDF qua Parsedown (Safe Mode) + Dompdf.</p>
    <?php if (!empty($error)): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <?php if (empty($templates)): ?>
        <p class="empty">Chưa có mẫu báo cáo nào trong <code>reports/templates/</code>.</p>
    <?php else: ?>
        <div class="grid-2">
            <?php foreach ($templates as $tpl): ?>
                <div class="panel" style="border:1px solid #e2e8f0;margin-bottom:0">
                    <h3><?= e($tpl['title']) ?></h3>
                    <p class="help">Slug: <code><?= e($tpl['slug']) ?></code> · File: <code><?= e($tpl['filename']) ?></code></p>
                    <?php if (!empty($tpl['queries'])): ?>
                        <ul style="padding-left:18px;font-size:13px;color:#334155">
                            <?php foreach ($tpl['queries'] as $q): ?>
                                <li><?= e($q) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <form method="POST" action="/reports" style="margin-top:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="template" value="<?= e($tpl['slug']) ?>">
                        <button class="btn primary" type="submit">Tạo báo cáo PDF &amp; MD</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Báo cáo đã tạo của bạn</h2>
    <div class="table-wrap">
        <table class="table" id="reports-table">
            <thead>
                <tr>
                    <th>Mẫu báo cáo</th>
                    <th>Trạng thái</th>
                    <th>Thời gian tạo</th>
                    <th>Hoàn tất</th>
                    <th>Tải xuống / Chi tiết</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jobs)): ?>
                    <tr><td colspan="5" class="empty">Bạn chưa tạo báo cáo nào.</td></tr>
                <?php else: ?>
                    <?php foreach ($jobs as $j): ?>
                        <?php
                        $badgeClass = match ($j['status']) {
                            'succeeded' => 'success',
                            'failed' => 'muted',
                            default => 'warning',
                        };
                        ?>
                        <tr data-status="<?= e($j['status']) ?>" data-job-id="<?= e($j['id']) ?>">
                            <td>
                                <strong><?= e((string)($j['payload_data']['template'] ?? '')) ?>.md</strong>
                                <?php if (!empty($j['error_message'])): ?>
                                    <small style="color:#dc2626"><?= e($j['error_message']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= $badgeClass ?>"><?= e(ai_job_status_label($j['status'])) ?></span></td>
                            <td><?= e($j['created_at']) ?></td>
                            <td><?= e($j['finished_at'] ?: '—') ?></td>
                            <td class="row-actions">
                                <a class="btn small secondary" href="/ai/jobs?id=<?= e($j['id']) ?>">Chi tiết</a>
                                <?php if ($j['status'] === 'succeeded'): ?>
                                    <a class="btn small primary" href="/reports/download?id=<?= e($j['id']) ?>&format=pdf">Tải PDF</a>
                                    <a class="btn small secondary" href="/reports/download?id=<?= e($j['id']) ?>&format=md">Tải MD</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var notifyBtn = document.getElementById('btn-report-notify');
    if (notifyBtn) {
        if (!('Notification' in window)) {
            notifyBtn.style.display = 'none';
        } else {
            if (Notification.permission === 'granted') {
                notifyBtn.textContent = 'Thông báo trình duyệt: Đang bật';
            }
            notifyBtn.addEventListener('click', function () {
                Notification.requestPermission().then(function (perm) {
                    notifyBtn.textContent = perm === 'granted'
                        ? 'Thông báo trình duyệt: Đang bật'
                        : 'Thông báo trình duyệt: Đã từ chối';
                });
            });
        }
    }

    var pendingRows = document.querySelectorAll('#reports-table tr[data-status="queued"], #reports-table tr[data-status="running"]');
    if (!pendingRows.length) return;

    var pendingIds = Array.from(pendingRows).map(function (tr) { return tr.getAttribute('data-job-id'); });
    var timer = setInterval(function () {
        fetch('/ai/status?type=report', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !Array.isArray(data.jobs)) return;
                var changed = false;
                data.jobs.forEach(function (job) {
                    if (pendingIds.indexOf(job.id) !== -1 && (job.status === 'succeeded' || job.status === 'failed')) {
                        changed = true;
                    }
                });
                if (changed) {
                    clearInterval(timer);
                    if ('Notification' in window && Notification.permission === 'granted') {
                        new Notification('Báo cáo PDF đã xử lý xong', {
                            body: 'Nhấn để tải file PDF/Markdown từ trang Báo cáo.'
                        });
                    }
                    window.location.reload();
                }
            })
            .catch(function () {});
    }, 2500);
})();
</script>

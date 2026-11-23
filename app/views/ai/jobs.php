<div class="page-actions actions" style="margin-top:0">
    <?php if (can('ai.ask')): ?><a class="btn primary" href="/ai">Đặt câu hỏi mới</a><?php endif; ?>
    <?php if (can('reports.view')): ?><a class="btn secondary" href="/reports">Tạo báo cáo Markdown / PDF</a><?php endif; ?>
    <button type="button" id="btn-notify-toggle" class="btn secondary">Bật thông báo trình duyệt</button>
</div>

<?php if (!empty($activeJob)): ?>
<div class="panel" id="active-job-panel" data-job-id="<?= e($activeJob['id']) ?>" data-job-status="<?= e($activeJob['status']) ?>">
    <h2>Chi tiết tác vụ: <code><?= e($activeJob['id']) ?></code></h2>
    <p>
        <strong>Loại:</strong> <?= $activeJob['job_type'] === 'report' ? 'Báo cáo Markdown / PDF' : 'Hỏi dữ liệu AI' ?>
        · <strong>Trạng thái:</strong>
        <span id="job-status-badge" class="badge <?= $activeJob['status'] === 'succeeded' ? 'success' : ($activeJob['status'] === 'failed' ? 'muted' : 'warning') ?>">
            <?= e(ai_job_status_label($activeJob['status'])) ?>
        </span>
        · <strong>Tạo lúc:</strong> <?= e($activeJob['created_at']) ?>
    </p>

    <?php if ($activeJob['job_type'] === 'question'): ?>
        <p><strong>Câu hỏi:</strong> <?= e((string)($activeJob['payload_data']['question'] ?? '')) ?></p>
    <?php else: ?>
        <p><strong>Mẫu báo cáo:</strong> <code><?= e((string)($activeJob['payload_data']['template'] ?? '')) ?>.md</code></p>
    <?php endif; ?>

    <div id="job-result-container">
        <?php if (in_array($activeJob['status'], ['queued', 'running'], true)): ?>
            <div class="alert warning" role="status">
                Tác vụ đang được xử lý bởi worker (tự động kiểm tra lại mỗi 2,5 giây)... Vui lòng giữ trang mở để nhận kết quả.
            </div>
        <?php elseif ($activeJob['status'] === 'failed'): ?>
            <div class="alert error" role="alert">
                <strong>Lỗi xử lý:</strong> <?= e((string)($activeJob['error_message'] ?? 'Không rõ nguyên nhân')) ?>
            </div>
        <?php elseif ($activeJob['status'] === 'succeeded'): ?>
            <?php $res = $activeJob['result_data'] ?? []; ?>
            <?php if ($activeJob['job_type'] === 'question'): ?>
                <?php if (!empty($res['explanation'])): ?>
                    <div class="hint"><strong>Diễn giải:</strong> <?= e((string)$res['explanation']) ?></div>
                <?php endif; ?>
                <?php if (!empty($res['sql'])): ?>
                    <h3 style="margin-top:16px">Câu lệnh SQL đã kiểm duyệt</h3>
                    <pre style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:12px;overflow-x:auto"><?= e((string)$res['sql']) ?></pre>
                <?php endif; ?>
                <h3 style="margin-top:16px">Kết quả (<?= (int)($res['row_count'] ?? 0) ?> dòng)</h3>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <?php foreach (($res['columns'] ?? []) as $col): ?>
                                    <th><?= e((string)$col) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($res['rows'])): ?>
                                <tr><td colspan="<?= max(1, count($res['columns'] ?? [])) ?>" class="empty">Không có dữ liệu phù hợp.</td></tr>
                            <?php else: ?>
                                <?php foreach ($res['rows'] as $row): ?>
                                    <tr>
                                        <?php foreach (($res['columns'] ?? []) as $col): ?>
                                            <td><?= e(isset($row[$col]) ? (string)$row[$col] : '') ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="actions">
                    <a class="btn primary" href="/reports/download?id=<?= e($activeJob['id']) ?>&format=pdf">Tải báo cáo PDF (A4 ngang)</a>
                    <a class="btn secondary" href="/reports/download?id=<?= e($activeJob['id']) ?>&format=md">Tải file Markdown (.md)</a>
                </div>
                <?php if (!empty($activeJob['result_markdown'])): ?>
                    <h3 style="margin-top:16px">Nội dung Markdown đã sinh</h3>
                    <pre style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:12px;overflow-x:auto;white-space:pre-wrap"><?= e((string)$activeJob['result_markdown']) ?></pre>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <h2>Lịch sử tác vụ của bạn</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Loại</th>
                    <th>Nội dung</th>
                    <th>Trạng thái</th>
                    <th>Tạo lúc</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jobs)): ?>
                    <tr><td colspan="5" class="empty">Chưa có tác vụ nào.</td></tr>
                <?php else: ?>
                    <?php foreach ($jobs as $j): ?>
                        <?php
                        $badgeClass = match ($j['status']) {
                            'succeeded' => 'success',
                            'failed' => 'muted',
                            default => 'warning',
                        };
                        $desc = $j['job_type'] === 'report'
                            ? 'Mẫu: ' . ($j['payload_data']['template'] ?? '') . '.md'
                            : (string)($j['payload_data']['question'] ?? '');
                        ?>
                        <tr>
                            <td><?= $j['job_type'] === 'report' ? 'Báo cáo' : 'Hỏi AI' ?></td>
                            <td><?= e($desc) ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= e(ai_job_status_label($j['status'])) ?></span></td>
                            <td><?= e($j['created_at']) ?></td>
                            <td class="row-actions">
                                <a class="btn small primary" href="/ai/jobs?id=<?= e($j['id']) ?>">Chi tiết</a>
                                <?php if ($j['job_type'] === 'report' && $j['status'] === 'succeeded'): ?>
                                    <a class="btn small secondary" href="/reports/download?id=<?= e($j['id']) ?>&format=pdf">PDF</a>
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
    var notifyBtn = document.getElementById('btn-notify-toggle');
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

    var panel = document.getElementById('active-job-panel');
    if (!panel) return;
    var jobId = panel.getAttribute('data-job-id');
    var status = panel.getAttribute('data-job-status');
    if (status !== 'queued' && status !== 'running') return;

    var timer = setInterval(function () {
        fetch('/ai/status?id=' + encodeURIComponent(jobId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.job) return;
                var st = data.job.status;
                var badge = document.getElementById('job-status-badge');
                if (badge && data.job.status_label) {
                    badge.textContent = data.job.status_label;
                }
                if (st === 'succeeded' || st === 'failed') {
                    clearInterval(timer);
                    if ('Notification' in window && Notification.permission === 'granted') {
                        new Notification('Tác vụ AI đã ' + (st === 'succeeded' ? 'hoàn tất' : 'thất bại'), {
                            body: 'Job ' + jobId
                        });
                    }
                    window.location.reload();
                }
            })
            .catch(function () {});
    }, 2500);
})();
</script>

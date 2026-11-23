<?php
$initialJob = $latestJob ?? null;
?>

<div class="ai-workspace">
    <!-- Main Column: Composer + Inline Live Result -->
    <div>
        <div class="panel">
            <?php if (!empty($error)): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <div id="ai-inline-error" class="alert error" role="alert" style="display:none"></div>

            <form id="ai-ask-form" method="POST" action="/ai">
                <?= csrf_field() ?>
                <div class="ai-composer">
                    <textarea id="ai-question" name="question" rows="3" required placeholder="Nhập câu hỏi về dữ liệu kho... (VD: Tồn hiện tại theo kho và đơn vị, hoặc sản phẩm nào sắp hết hàng?)"><?= e($oldQuestion ?? '') ?></textarea>
                    <div class="ai-composer-bar">
                        <span class="ai-kbd">Nhấn Ctrl + Enter để gửi nhanh</span>
                        <button id="ai-submit-btn" class="btn primary" type="submit">Hỏi AI ngay</button>
                    </div>
                </div>
            </form>

            <div class="ai-chips">
                <button type="button" class="ai-chip" data-q="Tồn hiện tại theo kho và đơn vị">Tồn hiện tại theo kho &amp; đơn vị</button>
                <button type="button" class="ai-chip" data-q="Top sản phẩm còn tồn thấp hơn mức tối thiểu">Sản phẩm tồn dưới tối thiểu</button>
                <button type="button" class="ai-chip" data-q="Tổng nhập/xuất đã ghi sổ theo tháng">Tổng nhập/xuất theo tháng</button>
                <button type="button" class="ai-chip" data-q="Liệt kê tồn kho theo lô và ngày hết hạn">Tồn theo lô &amp; hạn dùng</button>
                <button type="button" class="ai-chip" data-q="Top 10 sản phẩm có số lượng tồn kho cao nhất">Top 10 sản phẩm tồn cao nhất</button>
            </div>
        </div>

        <div class="panel" id="ai-result-panel">
            <?php if ($initialJob): ?>
                <?php
                $res = $initialJob['result_data'] ?? [];
                $jobModel = (string)($res['model'] ?? ($initialJob['payload_data']['model'] ?? ''));
                $badgeClass = match ($initialJob['status']) {
                    'succeeded' => 'success',
                    'failed' => 'muted',
                    default => 'warning',
                };
                ?>
                <div class="ai-result-header">
                    <div>
                        <h3 class="ai-result-title"><?= e((string)($initialJob['payload_data']['question'] ?? '')) ?></h3>
                        <div class="ai-result-meta">
                            <span class="badge <?= $badgeClass ?>"><?= e(ai_job_status_label($initialJob['status'])) ?></span>
                            <span><?= e($initialJob['created_at']) ?></span>
                            <?php if ($initialJob['status'] === 'succeeded'): ?>
                                <span>· <strong><?= (int)($res['row_count'] ?? 0) ?></strong> dòng</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="actions" style="margin-top:0">
                        <?php if ($initialJob['status'] === 'succeeded' && !empty($res['rows'])): ?>
                            <button type="button" class="btn small secondary" id="btn-export-csv">Xuất CSV</button>
                        <?php endif; ?>
                        <a class="btn small secondary" href="/ai/jobs?id=<?= e($initialJob['id']) ?>">Mở trang chi tiết</a>
                    </div>
                </div>

                <?php if (in_array($initialJob['status'], ['queued', 'running'], true)): ?>
                    <div class="ai-thinking">
                        <span class="ai-spinner"></span>
                        <span>Đang xử lý câu hỏi qua RabbitMQ &amp; Ollama...</span>
                    </div>
                <?php elseif ($initialJob['status'] === 'failed'): ?>
                    <div class="alert error"><?= e((string)($initialJob['error_message'] ?? 'Không rõ nguyên nhân')) ?></div>
                <?php elseif ($initialJob['status'] === 'succeeded'): ?>
                    <?php if (!empty($res['explanation'])): ?>
                        <div class="hint" style="margin-top:0"><?= e((string)$res['explanation']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($res['sql'])): ?>
                        <details class="ai-sql-details">
                            <summary>
                                <span>Xem câu lệnh SQL đã kiểm duyệt</span>
                                <button type="button" class="btn small secondary" id="btn-copy-sql" onclick="event.stopPropagation()">Sao chép SQL</button>
                            </summary>
                            <pre class="ai-sql-pre" id="ai-sql-code"><?= e((string)$res['sql']) ?></pre>
                        </details>
                    <?php endif; ?>
                    <div class="table-wrap" style="margin-top:12px">
                        <table class="table" id="ai-result-table">
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
                                                <?php $val = isset($row[$col]) ? (string)$row[$col] : ''; ?>
                                                <td class="<?= is_numeric($val) ? 'ai-num' : '' ?>"><?= e($val) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty" style="padding:40px 16px">
                    <strong style="display:block;font-size:15px;color:#334155;margin-bottom:6px">Chưa có kết quả truy vấn nào</strong>
                    Nhập câu hỏi ở trên hoặc nhấn vào một gợi ý để AI tự sinh SQL và trả bảng dữ liệu ngay tại đây.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Sidebar: History -->
    <div>
        <div class="panel">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <h2 style="font-size:16px;margin:0">Lịch sử gần đây</h2>
                <a class="help" style="margin:0;color:#2563eb" href="/ai/jobs">Tất cả</a>
            </div>
            <div class="ai-history-list" id="ai-history-list">
                <?php if (empty($jobs)): ?>
                    <p class="empty" style="padding:18px 8px;margin:0">Chưa có câu hỏi nào.</p>
                <?php else: ?>
                    <?php foreach ($jobs as $idx => $j): ?>
                        <?php
                        $jModel = (string)(($j['result_data']['model'] ?? null) ?: ($j['payload_data']['model'] ?? ''));
                        $badgeClass = match ($j['status']) {
                            'succeeded' => 'success',
                            'failed' => 'muted',
                            default => 'warning',
                        };
                        ?>
                        <button type="button" class="ai-history-item <?= $idx === 0 ? 'active' : '' ?>" data-job-id="<?= e($j['id']) ?>">
                            <div class="ai-history-q"><?= e((string)($j['payload_data']['question'] ?? '')) ?></div>
                            <div class="ai-history-meta">
                                <span class="badge <?= $badgeClass ?>" style="padding:3px 7px;font-size:11px"><?= e(ai_job_status_label($j['status'])) ?></span>
                                <span><?= e($j['created_at']) ?></span>
                            </div>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('ai-ask-form');
    var qBox = document.getElementById('ai-question');
    var modelSel = document.getElementById('ai-model');
    var submitBtn = document.getElementById('ai-submit-btn');
    var errBox = document.getElementById('ai-inline-error');
    var resultPanel = document.getElementById('ai-result-panel');
    var historyList = document.getElementById('ai-history-list');
    var activeTimer = null;
    var lastResultData = <?= json_encode($initialJob && $initialJob['status'] === 'succeeded' ? ($initialJob['result_data'] ?? null) : null, JSON_UNESCAPED_UNICODE) ?>;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function badgeCls(status) {
        if (status === 'succeeded') return 'success';
        if (status === 'failed') return 'muted';
        return 'warning';
    }

    function showError(msg) {
        if (!msg) { errBox.style.display = 'none'; errBox.textContent = ''; return; }
        errBox.textContent = msg;
        errBox.style.display = 'block';
    }

    function bindResultButtons(sqlText, resultObj) {
        var copyBtn = document.getElementById('btn-copy-sql');
        if (copyBtn && sqlText) {
            copyBtn.addEventListener('click', function (ev) {
                ev.stopPropagation();
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(sqlText).then(function () {
                        copyBtn.textContent = 'Đã sao chép!';
                        setTimeout(function () { copyBtn.textContent = 'Sao chép SQL'; }, 1500);
                    });
                }
            });
        }
        var csvBtn = document.getElementById('btn-export-csv');
        if (csvBtn && resultObj && Array.isArray(resultObj.columns) && Array.isArray(resultObj.rows)) {
            csvBtn.addEventListener('click', function () {
                var cols = resultObj.columns;
                var lines = [cols.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',')];
                resultObj.rows.forEach(function (r) {
                    lines.push(cols.map(function (c) {
                        var v = r[c] == null ? '' : String(r[c]);
                        return '"' + v.replace(/"/g, '""') + '"';
                    }).join(','));
                });
                var blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'ai_query_result.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });
        }
    }

    function renderJob(job) {
        var payload = job.payload || {};
        var res = job.result || null;
        var html = '<div class="ai-result-header"><div>'
            + '<h3 class="ai-result-title">' + esc(payload.question || '') + '</h3>'
            + '<div class="ai-result-meta">'
            + '<span class="badge ' + badgeCls(job.status) + '">' + esc(job.status_label || job.status) + '</span>'
            + (job.created_at ? '<span>' + esc(job.created_at) + '</span>' : '')
            + (job.status === 'succeeded' && res ? '<span>· <strong>' + Number(res.row_count || 0) + '</strong> dòng</span>' : '')
            + '</div></div>'
            + '<div class="actions" style="margin-top:0">'
            + (job.status === 'succeeded' && res && res.rows && res.rows.length ? '<button type="button" class="btn small secondary" id="btn-export-csv">Xuất CSV</button>' : '')
            + '<a class="btn small secondary" href="/ai/jobs?id=' + encodeURIComponent(job.id) + '">Mở trang chi tiết</a>'
            + '</div></div>';

        if (job.status === 'queued' || job.status === 'running') {
            html += '<div class="ai-thinking"><span class="ai-spinner"></span><span>'
                + (job.status === 'running'
                    ? 'Đang phân tích câu hỏi và truy vấn dữ liệu...'
                    : 'Đang chờ xử lý câu hỏi...')
                + '</span></div>';
        } else if (job.status === 'failed') {
            html += '<div class="alert error">' + esc(job.error_message || 'Không rõ nguyên nhân') + '</div>';
        } else if (job.status === 'succeeded' && res) {
            if (res.explanation) {
                html += '<div class="hint" style="margin-top:0">' + esc(res.explanation) + '</div>';
            }
            if (res.sql) {
                html += '<details class="ai-sql-details"><summary><span>Xem câu lệnh SQL đã kiểm duyệt</span>'
                    + '<button type="button" class="btn small secondary" id="btn-copy-sql">Sao chép SQL</button></summary>'
                    + '<pre class="ai-sql-pre">' + esc(res.sql) + '</pre></details>';
            }
            var cols = Array.isArray(res.columns) ? res.columns : [];
            var rows = Array.isArray(res.rows) ? res.rows : [];
            html += '<div class="table-wrap" style="margin-top:12px"><table class="table"><thead><tr>';
            cols.forEach(function (c) { html += '<th>' + esc(c) + '</th>'; });
            html += '</tr></thead><tbody>';
            if (!rows.length) {
                html += '<tr><td colspan="' + Math.max(1, cols.length) + '" class="empty">Không có dữ liệu phù hợp.</td></tr>';
            } else {
                rows.forEach(function (r) {
                    html += '<tr>';
                    cols.forEach(function (c) {
                        var v = r[c] == null ? '' : String(r[c]);
                        var isNum = v !== '' && !isNaN(Number(v));
                        html += '<td class="' + (isNum ? 'ai-num' : '') + '">' + esc(v) + '</td>';
                    });
                    html += '</tr>';
                });
            }
            html += '</tbody></table></div>';
        }

        resultPanel.innerHTML = html;
        lastResultData = res;
        bindResultButtons(res && res.sql ? res.sql : '', res);
    }

    function prependHistoryItem(job) {
        if (!historyList) return;
        var emptyEl = historyList.querySelector('.empty');
        if (emptyEl) emptyEl.remove();
        var existing = historyList.querySelector('[data-job-id="' + job.id + '"]');
        if (existing) {
            var b = existing.querySelector('.badge');
            if (b) {
                b.className = 'badge ' + badgeCls(job.status);
                b.textContent = job.status_label || job.status;
            }
            return;
        }
        historyList.querySelectorAll('.ai-history-item.active').forEach(function (el) { el.classList.remove('active'); });
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ai-history-item active';
        btn.setAttribute('data-job-id', job.id);
        btn.innerHTML = '<div class="ai-history-q">' + esc((job.payload && job.payload.question) || '') + '</div>'
            + '<div class="ai-history-meta">'
            + '<span class="badge ' + badgeCls(job.status) + '" style="padding:3px 7px;font-size:11px">' + esc(job.status_label || job.status) + '</span>'
            + '<span>Vừa xong</span></div>';
        btn.addEventListener('click', function () { loadJobById(job.id, btn); });
        historyList.insertBefore(btn, historyList.firstChild);
    }

    function pollJob(jobId) {
        if (activeTimer) clearInterval(activeTimer);
        var tick = function () {
            fetch('/ai/status?id=' + encodeURIComponent(jobId), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.job) return;
                    renderJob(data.job);
                    prependHistoryItem(data.job);
                    if (data.job.status === 'succeeded' || data.job.status === 'failed') {
                        clearInterval(activeTimer);
                        activeTimer = null;
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Hỏi AI ngay';
                    }
                })
                .catch(function () {});
        };
        activeTimer = setInterval(tick, 1500);
        tick();
    }

    function loadJobById(jobId, clickedBtn) {
        if (activeTimer) { clearInterval(activeTimer); activeTimer = null; }
        historyList.querySelectorAll('.ai-history-item.active').forEach(function (el) { el.classList.remove('active'); });
        if (clickedBtn) clickedBtn.classList.add('active');
        fetch('/ai/status?id=' + encodeURIComponent(jobId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.job) return;
                renderJob(data.job);
                if (data.job.status === 'queued' || data.job.status === 'running') {
                    pollJob(jobId);
                }
            });
    }

    // Bind initial copy/csv buttons
    var initSqlEl = document.getElementById('ai-sql-code');
    bindResultButtons(initSqlEl ? initSqlEl.textContent : '', lastResultData);

    // Suggestion chips
    document.querySelectorAll('.ai-chip').forEach(function (btn) {
        btn.addEventListener('click', function () {
            qBox.value = btn.getAttribute('data-q') || '';
            qBox.focus();
        });
    });

    // History clicks
    document.querySelectorAll('.ai-history-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
            loadJobById(btn.getAttribute('data-job-id'), btn);
        });
    });

    // Ctrl+Enter shortcut
    qBox.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }
    });

    // AJAX form submit
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var q = qBox.value.trim();
        if (!q) { showError('Vui lòng nhập câu hỏi dữ liệu.'); return; }
        showError('');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Đang gửi...';

        var fd = new FormData(form);
        fetch('/ai', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
            if (!res.ok || res.data.error) {
                showError(res.data.error || 'Không thể gửi câu hỏi.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Hỏi AI ngay';
                return;
            }
            var job = {
                id: res.data.job_id,
                status: 'queued',
                status_label: res.data.status_label || 'Đang chờ xử lý',
                payload: { question: res.data.question, model: res.data.model }
            };
            renderJob(job);
            prependHistoryItem(job);
            pollJob(res.data.job_id);
        })
        .catch(function () {
            showError('Lỗi mạng khi gửi câu hỏi.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Hỏi AI ngay';
        });
    });
})();
</script>

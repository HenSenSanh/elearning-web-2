<?php
$tab = $tab ?? 'permissions';
$tabs = [
    'permissions' => 'Phân quyền chi tiết',
    'users' => 'Quản lý tài khoản',
    'roles' => 'Nhóm quyền',
    'audit' => 'Nhật ký phân quyền',
];
$currentAdminId = (int)($_SESSION['admin_id'] ?? 0);
?>
<div class="panel">
    <div class="tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="btn <?= $tab === $key ? 'primary' : 'secondary' ?>" href="/access?tab=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <p class="help">Mỗi tài khoản có thể thuộc nhiều nhóm. Quyền riêng <strong>Từ chối</strong> có ưu tiên cao nhất, sau đó là quyền riêng <strong>Cho phép</strong>, rồi đến hợp các quyền nhóm. Hệ thống luôn bảo vệ ít nhất 1 tài khoản đang hoạt động có quyền <code>access.manage</code> và tự động vô hiệu phiên của tài khoản bị khóa ở lần kiểm tra kế tiếp.</p>
    <?php if (!empty($error)): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (!empty($message)): ?><div class="alert success" role="status"><?= e($message) ?></div><?php endif; ?>
</div>

<?php if ($tab === 'permissions'): ?>
    <div class="panel">
        <h2>Chọn tài khoản và nhóm cần cấu hình</h2>
        <form method="get" action="/access" class="grid-2">
            <input type="hidden" name="tab" value="permissions">
            <div class="form-group">
                <label for="choose-user">Tài khoản cần phân quyền</label>
                <select id="choose-user" name="user_id">
                    <?php foreach ($access['users'] as $user): ?>
                        <option value="<?= (int)$user['id'] ?>" <?= $access['selected_user'] === (int)$user['id'] ? 'selected' : '' ?>>
                            <?= e($user['username'] . ' — ' . ($user['full_name'] ?: $user['email']) . ((int)$user['is_active'] === 0 ? ' (Đã khóa)' : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="choose-role">Nhóm cần cấu hình</label>
                <select id="choose-role" name="role_id">
                    <?php foreach ($access['roles'] as $role): ?>
                        <option value="<?= (int)$role['id'] ?>" <?= $access['selected_role'] === (int)$role['id'] ? 'selected' : '' ?>>
                            <?= e($role['code'] . ' · ' . $role['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button class="btn secondary" type="submit">Xem cấu hình</button></div>
        </form>
    </div>

    <div class="panel">
        <h2>Nhóm và quyền riêng của tài khoản</h2>
        <form method="post" action="/access?tab=permissions">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="user">
            <input type="hidden" name="user_id" value="<?= (int)$access['selected_user'] ?>">
            <input type="hidden" name="role_id" value="<?= (int)$access['selected_role'] ?>">
            <div class="grid-2">
                <?php foreach ($access['roles'] as $role): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="role_ids[]" value="<?= (int)$role['id'] ?>" <?= in_array((int)$role['id'], $access['user_role_ids'], true) ? 'checked' : '' ?>>
                        <?= e($role['code'] . ' · ' . $role['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Chức năng</th>
                            <th>Quyền từ nhóm</th>
                            <th>Quyền riêng (UserPermission)</th>
                            <th>Hiệu lực hiện tại</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($access['permissions'] as $permission): ?>
                            <?php $override = $access['user_overrides'][$permission['id']] ?? 'inherit'; ?>
                            <tr>
                                <td><?= e($permission['name']) ?><small><?= e($permission['code']) ?></small></td>
                                <td><?= in_array($permission['code'], $access['group_codes'], true) ? 'Cho phép' : 'Chưa cấp' ?></td>
                                <td>
                                    <select name="overrides[<?= (int)$permission['id'] ?>]" aria-label="Quyền riêng: <?= e($permission['name']) ?>">
                                        <option value="inherit" <?= $override === 'inherit' ? 'selected' : '' ?>>Kế thừa nhóm</option>
                                        <option value="allow" <?= $override === 'allow' ? 'selected' : '' ?>>Cho phép</option>
                                        <option value="deny" <?= $override === 'deny' ? 'selected' : '' ?>>Từ chối</option>
                                    </select>
                                </td>
                                <td>
                                    <span class="badge <?= $access['effective'][$permission['code']] ? 'success' : 'muted' ?>">
                                        <?= $access['effective'][$permission['code']] ? 'Được phép' : 'Từ chối' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="help">Cột hiệu lực phản ánh cấu hình đã lưu (tài khoản bị khóa tự động Từ chối mọi quyền). Bấm lưu để tính lại và ghi nhật ký phân quyền.</p>
            <button class="btn primary" type="submit">Lưu quyền tài khoản</button>
        </form>
    </div>

    <div class="panel">
        <h2>Danh sách quyền của nhóm đã chọn</h2>
        <p>Thay đổi tại đây ảnh hưởng đến tất cả thành viên nhóm. Tài khoản có quyền riêng Từ chối vẫn bị từ chối.</p>
        <form method="post" action="/access?tab=permissions">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="role">
            <input type="hidden" name="user_id" value="<?= (int)$access['selected_user'] ?>">
            <input type="hidden" name="role_id" value="<?= (int)$access['selected_role'] ?>">
            <div class="grid-2">
                <?php foreach ($access['permissions'] as $permission): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="permission_ids[]" value="<?= (int)$permission['id'] ?>" <?= in_array((int)$permission['id'], $access['role_permission_ids'], true) ? 'checked' : '' ?>>
                        <?= e($permission['name']) ?> (<?= e($permission['code']) ?>)
                    </label>
                <?php endforeach; ?>
            </div>
            <button class="btn primary" type="submit">Lưu quyền nhóm</button>
        </form>
    </div>

<?php elseif ($tab === 'users'): ?>
    <div class="panel">
        <h2>Tạo tài khoản mới</h2>
        <form method="post" action="/access?tab=users">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="create_user">
            <div class="grid-2">
                <div class="form-group">
                    <label for="new_username">Tên đăng nhập</label>
                    <input id="new_username" name="username" required pattern="[A-Za-z0-9_.\-]{3,60}" maxlength="60" placeholder="vd: clerk02">
                </div>
                <div class="form-group">
                    <label for="new_email">Email</label>
                    <input id="new_email" name="email" type="email" required maxlength="150" placeholder="clerk02@example.com">
                </div>
                <div class="form-group">
                    <label for="new_full_name">Họ và tên</label>
                    <input id="new_full_name" name="full_name" maxlength="150" placeholder="Nhân viên kho 02">
                </div>
                <div class="form-group">
                    <label for="new_password">Mật khẩu ban đầu (&ge; 6 ký tự)</label>
                    <input id="new_password" name="password" type="password" required minlength="6">
                </div>
            </div>
            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1" checked>
                Kích hoạt tài khoản ngay
            </label>
            <div class="form-group">
                <label>Gán vào nhóm quyền ban đầu</label>
                <div class="grid-2">
                    <?php foreach ($access['roles'] as $role): ?>
                        <label class="checkbox">
                            <input type="checkbox" name="role_ids[]" value="<?= (int)$role['id'] ?>">
                            <?= e($role['code'] . ' · ' . $role['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button class="btn primary" type="submit">Tạo tài khoản</button>
        </form>
    </div>

    <div class="panel table-scroll">
        <h2>Danh sách tài khoản (<?= count($access['users']) ?>)</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Tài khoản</th>
                    <th>Thông tin &amp; Cập nhật</th>
                    <th>Trạng thái &amp; Khóa/Mở</th>
                    <th>Đặt lại mật khẩu</th>
                    <th>Phân quyền</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($access['users'] as $user): ?>
                    <?php $isActive = (int)$user['is_active'] === 1; $uid = (int)$user['id']; ?>
                    <tr>
                        <td>
                            <strong><?= e($user['username']) ?></strong>
                            <small>Nhóm: <?= e($user['role_names'] ?: 'Chưa gán nhóm') ?></small>
                            <small>Tạo: <?= e($user['created_at']) ?></small>
                        </td>
                        <td>
                            <form method="post" action="/access?tab=users">
                                <?= csrf_field() ?>
                                <input type="hidden" name="mode" value="update_user">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <div class="form-group">
                                    <input name="full_name" value="<?= e($user['full_name'] ?? '') ?>" placeholder="Họ và tên" maxlength="150" aria-label="Họ và tên của <?= e($user['username']) ?>">
                                </div>
                                <div class="form-group">
                                    <input name="email" type="email" required value="<?= e($user['email']) ?>" maxlength="150" aria-label="Email của <?= e($user['username']) ?>">
                                </div>
                                <button class="btn secondary small" type="submit">Lưu thông tin</button>
                            </form>
                        </td>
                        <td>
                            <div>
                                <span class="badge <?= $isActive ? 'success' : 'muted' ?>">
                                    <?= $isActive ? 'Hoạt động' : 'Đã khóa' ?>
                                </span>
                            </div>
                            <?php if ($uid === $currentAdminId): ?>
                                <small>Đang đăng nhập</small>
                            <?php else: ?>
                                <form method="post" action="/access?tab=users" style="margin-top:8px">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="mode" value="toggle_user">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <input type="hidden" name="is_active" value="<?= $isActive ? 0 : 1 ?>">
                                    <button class="btn <?= $isActive ? 'danger' : 'primary' ?> small" type="submit">
                                        <?= $isActive ? 'Khóa tài khoản' : 'Mở khóa' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="/access?tab=users">
                                <?= csrf_field() ?>
                                <input type="hidden" name="mode" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <div class="form-group">
                                    <input name="new_password" type="password" required minlength="6" placeholder="Mật khẩu mới (&ge; 6)" aria-label="Mật khẩu mới cho <?= e($user['username']) ?>">
                                </div>
                                <button class="btn secondary small" type="submit">Đổi mật khẩu</button>
                            </form>
                        </td>
                        <td>
                            <a class="btn secondary small" href="/access?tab=permissions&amp;user_id=<?= $uid ?>">Cấu hình quyền</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'roles'): ?>
    <div class="panel">
        <h2>Tạo nhóm quyền mới</h2>
        <form method="post" action="/access?tab=roles">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="create_role">
            <div class="grid-2">
                <div class="form-group">
                    <label for="role_code">Mã nhóm (chữ hoa, số, _)</label>
                    <input id="role_code" name="code" required pattern="[A-Za-z][A-Za-z0-9_]{1,49}" maxlength="50" placeholder="vd: AUDITOR">
                </div>
                <div class="form-group">
                    <label for="role_name">Tên nhóm hiển thị</label>
                    <input id="role_name" name="name" required maxlength="150" placeholder="Kiểm toán viên nội bộ">
                </div>
            </div>
            <button class="btn primary" type="submit">Tạo nhóm quyền</button>
        </form>
    </div>

    <div class="panel table-scroll">
        <h2>Danh sách nhóm quyền (<?= count($access['roles']) ?>)</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Mã nhóm</th>
                    <th>Tên nhóm</th>
                    <th>Thành viên</th>
                    <th>Số quyền đang gán</th>
                    <th>Phân quyền</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($access['roles'] as $role): ?>
                    <tr>
                        <td><strong><?= e($role['code']) ?></strong></td>
                        <td>
                            <form method="post" action="/access?tab=roles" class="filter-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="mode" value="update_role">
                                <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                                <input name="name" required maxlength="150" value="<?= e($role['name']) ?>" aria-label="Tên nhóm <?= e($role['code']) ?>">
                                <button class="btn secondary small" type="submit">Đổi tên</button>
                            </form>
                        </td>
                        <td><?= (int)$role['user_count'] ?></td>
                        <td><?= (int)$role['perm_count'] ?></td>
                        <td>
                            <a class="btn secondary small" href="/access?tab=permissions&amp;role_id=<?= (int)$role['id'] ?>">Cấu hình quyền nhóm</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <div class="panel table-scroll">
        <h2>Nhật ký thay đổi tài khoản &amp; phân quyền</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Thời gian</th>
                    <th>Người thực hiện</th>
                    <th>Thao tác</th>
                    <th>Đối tượng</th>
                    <th>Chi tiết</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($access['audit_logs']['items'] as $log): ?>
                    <tr>
                        <td>#<?= (int)$log['id'] ?></td>
                        <td><?= e($log['created_at']) ?></td>
                        <td><?= e($log['actor_username'] ?? 'Hệ thống') ?></td>
                        <td><span class="badge muted"><?= e($log['action']) ?></span></td>
                        <td><?= e($log['target_type'] . ' #' . $log['target_id']) ?><small><?= e($log['target_label']) ?></small></td>
                        <td><small><?= e($log['details'] ?? '') ?></small></td>
                        <td><?= e($log['ip_address'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$access['audit_logs']['items']): ?>
                    <tr><td colspan="7" class="empty">Chưa có nhật ký thay đổi phân quyền.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?= pagination_links($access['audit_logs'], '/access', ['tab' => 'audit']) ?>
    </div>
<?php endif; ?>

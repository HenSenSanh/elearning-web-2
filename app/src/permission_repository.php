<?php
require_once __DIR__ . '/auth.php';

/** Deny riêng > allow riêng > hợp quyền nhóm > mặc định từ chối. */
function permission_decide(array $groupCodes, array $overrides, string $code): bool {
    if (isset($overrides[$code])) return $overrides[$code] === 'allow';
    return in_array($code, $groupCodes, true);
}

function permission_for_user(int $userId, string $code): bool {
    $stmt = db()->prepare(
        "SELECT p.code, up.effect,
                EXISTS(SELECT 1 FROM user_roles ur
                       JOIN role_permissions rp ON rp.role_id=ur.role_id
                       WHERE ur.user_id=:group_user AND rp.permission_id=p.id) AS group_allowed
         FROM admins a
         JOIN permissions p ON p.code=:code
         LEFT JOIN user_permissions up ON up.permission_id=p.id AND up.user_id=a.id
         WHERE a.id=:active_user AND a.is_active=1"
    );
    $stmt->execute(['group_user'=>$userId, 'active_user'=>$userId, 'code'=>$code]);
    $row = $stmt->fetch();
    if (!$row) return false;
    return permission_decide($row['group_allowed'] ? [$code] : [],
        $row['effect'] !== null ? [$code=>$row['effect']] : [], $code);
}

function can(string $code): bool {
    // Query live user status & current grants on every request.
    $user = current_user();
    if (!$user) return false;
    return permission_for_user((int)$user['id'], $code);
}

function require_permission(string $code): void {
    require_admin();
    if (!can($code)) {
        http_response_code(403);
        exit('403 - Bạn không có quyền thực hiện chức năng này.');
    }
}

function permission_audit_record(PDO $pdo, ?int $actorId, string $targetType, int $targetId, string $targetLabel, string $action, array|string $details): void {
    $detailText = is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = $pdo->prepare('INSERT INTO permission_audit_logs(actor_user_id,target_type,target_id,target_label,action,details,ip_address) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$actorId, $targetType, $targetId, $targetLabel, $action, $detailText, $ip]);
}

function access_audit_paginate(int $page = 1, int $perPage = 15): array {
    $total = (int)db()->query('SELECT COUNT(*) FROM permission_audit_logs')->fetchColumn();
    $result = pagination_meta($total, $page, $perPage);
    $stmt = db()->prepare('SELECT l.*, a.username AS actor_username FROM permission_audit_logs l LEFT JOIN admins a ON a.id=l.actor_user_id ORDER BY l.id DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $result['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(2, $result['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $result['items'] = $stmt->fetchAll();
    return $result;
}

function access_assert_active_manager_exists(PDO $pdo): void {
    $activeIds = array_map('intval', $pdo->query('SELECT id FROM admins WHERE is_active=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    foreach ($activeIds as $uid) {
        if (permission_for_user($uid, 'access.manage')) {
            return;
        }
    }
    throw new InvalidArgumentException('Hệ thống phải còn ít nhất một tài khoản đang hoạt động có quyền quản lý phân quyền (access.manage).');
}

function access_data(int $userId = 0, int $roleId = 0, int $auditPage = 1): array {
    $users = db()->query("
        SELECT a.id, a.username, a.email, a.full_name, a.is_active, a.created_at,
               GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ', ') AS role_names
        FROM admins a
        LEFT JOIN user_roles ur ON ur.user_id=a.id
        LEFT JOIN roles r ON r.id=ur.role_id
        GROUP BY a.id, a.username, a.email, a.full_name, a.is_active, a.created_at
        ORDER BY a.id
    ")->fetchAll();
    $roles = db()->query('
        SELECT r.*,
               (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id=r.id) AS user_count,
               (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id=r.id) AS perm_count
        FROM roles r ORDER BY r.id
    ')->fetchAll();
    $permissions = db()->query('SELECT * FROM permissions ORDER BY id')->fetchAll();
    $userId = $userId ?: (int)($users[0]['id'] ?? 0);
    $roleId = $roleId ?: (int)($roles[0]['id'] ?? 0);
    $stmt = db()->prepare('SELECT role_id FROM user_roles WHERE user_id=?');
    $stmt->execute([$userId]);
    $userRoleIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = db()->prepare('SELECT permission_id,effect FROM user_permissions WHERE user_id=?');
    $stmt->execute([$userId]);
    $overrides = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = db()->prepare('SELECT permission_id FROM role_permissions WHERE role_id=?');
    $stmt->execute([$roleId]);
    $rolePermissionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = db()->prepare('SELECT DISTINCT p.code FROM permissions p
        JOIN role_permissions rp ON rp.permission_id=p.id
        JOIN user_roles ur ON ur.role_id=rp.role_id WHERE ur.user_id=?');
    $stmt->execute([$userId]);
    $groupCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $codeOverrides = [];
    foreach ($permissions as $permission) {
        if (isset($overrides[$permission['id']])) $codeOverrides[$permission['code']] = $overrides[$permission['id']];
    }
    $selectedActive = true;
    foreach ($users as $u) {
        if ((int)$u['id'] === $userId) { $selectedActive = (int)$u['is_active'] === 1; break; }
    }
    $effective = [];
    foreach ($permissions as $permission) {
        $effective[$permission['code']] = $selectedActive && permission_decide($groupCodes, $codeOverrides, $permission['code']);
    }
    return [
        'users'=>$users, 'roles'=>$roles, 'permissions'=>$permissions,
        'selected_user'=>$userId, 'selected_role'=>$roleId, 'user_role_ids'=>$userRoleIds,
        'user_overrides'=>$overrides, 'role_permission_ids'=>$rolePermissionIds,
        'group_codes'=>$groupCodes, 'effective'=>$effective,
        'audit_logs'=>access_audit_paginate($auditPage, 15),
    ];
}

function access_valid_ids($values, array $allowed): array {
    if (!is_array($values)) throw new InvalidArgumentException('Danh sách quyền hoặc nhóm không hợp lệ.');
    $ids = [];
    foreach ($values as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false || !in_array($id, $allowed, true)) {
            throw new InvalidArgumentException('Quyền hoặc nhóm không tồn tại.');
        }
        $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

/** Called by the CSRF-checked POST /access route. Every save is atomic and audited. */
function access_save(array $input, int $actorId): void {
    require_permission('access.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    run_with_deadlock_retry(db(), function (PDO $pdo) use ($input, $actorId): void {
        $allUserIds = array_map('intval', $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN));
        if (!permission_for_user($actorId, 'access.manage')) {
            throw new InvalidArgumentException('Quyền quản lý phân quyền đã bị thu hồi.');
        }
        $roleIds = array_map('intval', $pdo->query('SELECT id FROM roles')->fetchAll(PDO::FETCH_COLUMN));
        $permissionIds = array_map('intval', $pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN));

        if (($input['mode'] ?? '') === 'user') {
            $userId = filter_var($input['user_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($userId === false || !in_array($userId, $allUserIds, true)) throw new InvalidArgumentException('Tài khoản không tồn tại.');
            $uRow = $pdo->prepare('SELECT username FROM admins WHERE id=?'); $uRow->execute([$userId]);
            $targetUsername = (string)$uRow->fetchColumn();

            $selectedRoles = access_valid_ids($input['role_ids'] ?? [], $roleIds);
            $overrides = $input['overrides'] ?? [];
            if (!is_array($overrides)) throw new InvalidArgumentException('Quyền riêng không hợp lệ.');
            foreach ($overrides as $id=>$effect) {
                $permissionId = filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($permissionId === false || !in_array($permissionId, $permissionIds, true) || !in_array($effect, ['inherit','allow','deny'], true)) {
                    throw new InvalidArgumentException('Quyền riêng không hợp lệ.');
                }
            }

            $oldRolesStmt = $pdo->prepare('SELECT role_id FROM user_roles WHERE user_id=? ORDER BY role_id');
            $oldRolesStmt->execute([$userId]);
            $oldRoles = array_map('intval', $oldRolesStmt->fetchAll(PDO::FETCH_COLUMN));

            $oldOvrStmt = $pdo->prepare('SELECT permission_id, effect FROM user_permissions WHERE user_id=?');
            $oldOvrStmt->execute([$userId]);
            $oldOverrides = $oldOvrStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $pdo->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$userId]);
            $insert = $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)');
            foreach ($selectedRoles as $roleId) $insert->execute([$userId, $roleId]);

            $pdo->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$userId]);
            $insert = $pdo->prepare('INSERT INTO user_permissions(user_id,permission_id,effect) VALUES(?,?,?)');
            $newOverrides = [];
            foreach ($overrides as $id=>$effect) {
                if ($effect !== 'inherit') {
                    $insert->execute([$userId, (int)$id, $effect]);
                    $newOverrides[(int)$id] = $effect;
                }
            }

            if (!permission_for_user($actorId, 'access.manage')) {
                throw new InvalidArgumentException('Không được tự thu hồi quyền quản lý phân quyền; thay đổi đã được hoàn tác.');
            }
            access_assert_active_manager_exists($pdo);
            permission_audit_record($pdo, $actorId, 'user_permissions', $userId, $targetUsername, 'UPDATE_USER_ACCESS', [
                'old_roles' => $oldRoles,
                'new_roles' => $selectedRoles,
                'old_overrides' => $oldOverrides,
                'new_overrides' => $newOverrides,
            ]);
        } elseif (($input['mode'] ?? '') === 'role') {
            $roleId = filter_var($input['role_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($roleId === false || !in_array($roleId, $roleIds, true)) throw new InvalidArgumentException('Nhóm không tồn tại.');
            $rStmt = $pdo->prepare('SELECT code FROM roles WHERE id=?'); $rStmt->execute([$roleId]);
            $roleCode = (string)$rStmt->fetchColumn();

            $selectedPermissions = access_valid_ids($input['permission_ids'] ?? [], $permissionIds);
            $oldPermStmt = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id=? ORDER BY permission_id');
            $oldPermStmt->execute([$roleId]);
            $oldPerms = array_map('intval', $oldPermStmt->fetchAll(PDO::FETCH_COLUMN));

            $pdo->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]);
            $insert = $pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)');
            foreach ($selectedPermissions as $permissionId) $insert->execute([$roleId, $permissionId]);

            if (!permission_for_user($actorId, 'access.manage')) {
                throw new InvalidArgumentException('Không được tự thu hồi quyền quản lý phân quyền; thay đổi đã được hoàn tác.');
            }
            access_assert_active_manager_exists($pdo);
            permission_audit_record($pdo, $actorId, 'role_permissions', $roleId, $roleCode, 'UPDATE_ROLE_PERMISSIONS', [
                'old_permission_ids' => $oldPerms,
                'new_permission_ids' => $selectedPermissions,
            ]);
        } else {
            throw new InvalidArgumentException('Thao tác phân quyền không hợp lệ.');
        }
    });
}

function access_create_user(array $input, int $actorId): int {
    require_permission('access.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    $username = trim((string)($input['username'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $fullName = trim((string)($input['full_name'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $isActive = isset($input['is_active']) ? 1 : 0;
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
        throw new InvalidArgumentException('Tên đăng nhập phải từ 3–60 ký tự (chữ, số, _, ., -).');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email không hợp lệ.');
    }
    if (strlen($password) < 6) {
        throw new InvalidArgumentException('Mật khẩu phải có ít nhất 6 ký tự.');
    }

    return run_with_deadlock_retry(db(), function (PDO $pdo) use ($input, $actorId, $username, $email, $fullName, $password, $isActive): int {
        $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll();
        if (!permission_for_user($actorId, 'access.manage')) throw new InvalidArgumentException('Quyền quản lý phân quyền đã bị thu hồi.');

        $dup = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE LOWER(username)=LOWER(?) OR LOWER(email)=LOWER(?)');
        $dup->execute([$username, $email]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new InvalidArgumentException('Tên đăng nhập hoặc email đã tồn tại.');
        }

        $roleIds = array_map('intval', $pdo->query('SELECT id FROM roles')->fetchAll(PDO::FETCH_COLUMN));
        $selectedRoles = access_valid_ids($input['role_ids'] ?? [], $roleIds);

        $ins = $pdo->prepare('INSERT INTO admins(username,email,password_hash,full_name,is_active) VALUES(?,?,?,?,?)');
        $ins->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $fullName !== '' ? $fullName : null, $isActive]);
        $newId = (int)$pdo->lastInsertId();

        $ur = $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)');
        foreach ($selectedRoles as $rid) $ur->execute([$newId, $rid]);

        permission_audit_record($pdo, $actorId, 'user', $newId, $username, 'CREATE_USER', [
            'email' => $email,
            'full_name' => $fullName,
            'is_active' => $isActive,
            'role_ids' => $selectedRoles,
        ]);
        return $newId;
    });
}

function access_update_user(array $input, int $actorId): void {
    require_permission('access.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    $userId = filter_var($input['user_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $email = trim((string)($input['email'] ?? ''));
    $fullName = trim((string)($input['full_name'] ?? ''));
    if ($userId === false) throw new InvalidArgumentException('Tài khoản không hợp lệ.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email không hợp lệ.');

    run_with_deadlock_retry(db(), function (PDO $pdo) use ($actorId, $userId, $email, $fullName): void {
        $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll();
        if (!permission_for_user($actorId, 'access.manage')) throw new InvalidArgumentException('Quyền quản lý phân quyền đã bị thu hồi.');
        $cur = $pdo->prepare('SELECT username,email,full_name FROM admins WHERE id=?');
        $cur->execute([$userId]);
        $old = $cur->fetch();
        if (!$old) throw new InvalidArgumentException('Tài khoản không tồn tại.');

        $dup = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE LOWER(email)=LOWER(?) AND id<>?');
        $dup->execute([$email, $userId]);
        if ((int)$dup->fetchColumn() > 0) throw new InvalidArgumentException('Email đã thuộc tài khoản khác.');

        $pdo->prepare('UPDATE admins SET email=?, full_name=? WHERE id=?')->execute([$email, $fullName !== '' ? $fullName : null, $userId]);
        permission_audit_record($pdo, $actorId, 'user', $userId, $old['username'], 'UPDATE_USER', [
            'old_email' => $old['email'],
            'new_email' => $email,
            'old_full_name' => $old['full_name'],
            'new_full_name' => $fullName,
        ]);
    });
}

function access_toggle_user(array $input, int $actorId): void {
    require_permission('access.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    $userId = filter_var($input['user_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($userId === false) throw new InvalidArgumentException('Tài khoản không hợp lệ.');
    $newState = ((int)($input['is_active'] ?? 0)) === 1 ? 1 : 0;

    run_with_deadlock_retry(db(), function (PDO $pdo) use ($actorId, $userId, $newState): void {
        $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll();
        if (!permission_for_user($actorId, 'access.manage')) throw new InvalidArgumentException('Quyền quản lý phân quyền đã bị thu hồi.');
        $cur = $pdo->prepare('SELECT username,is_active FROM admins WHERE id=?');
        $cur->execute([$userId]);
        $old = $cur->fetch();
        if (!$old) throw new InvalidArgumentException('Tài khoản không tồn tại.');

        $pdo->prepare('UPDATE admins SET is_active=? WHERE id=?')->execute([$newState, $userId]);
        if ($userId === $actorId && $newState === 0) {
            throw new InvalidArgumentException('Không được tự khóa tài khoản đang thao tác.');
        }
        access_assert_active_manager_exists($pdo);
        permission_audit_record($pdo, $actorId, 'user', $userId, $old['username'], $newState ? 'UNLOCK_USER' : 'LOCK_USER', [
            'old_is_active' => (int)$old['is_active'],
            'new_is_active' => $newState,
        ]);
    });
}

function access_reset_password(array $input, int $actorId): void {
    require_permission('access.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    $userId = filter_var($input['user_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $newPass = (string)($input['new_password'] ?? '');
    if ($userId === false) throw new InvalidArgumentException('Tài khoản không hợp lệ.');
    if (strlen($newPass) < 6) throw new InvalidArgumentException('Mật khẩu mới phải có ít nhất 6 ký tự.');

    run_with_deadlock_retry(db(), function (PDO $pdo) use ($actorId, $userId, $newPass): void {
        $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll();
        if (!permission_for_user($actorId, 'access.manage')) throw new InvalidArgumentException('Quyền quản lý phân quyền đã bị thu hồi.');
        $cur = $pdo->prepare('SELECT username FROM admins WHERE id=?');
        $cur->execute([$userId]);
        $username = $cur->fetchColumn();
        if (!$username) throw new InvalidArgumentException('Tài khoản không tồn tại.');

        $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($newPass, PASSWORD_DEFAULT), $userId]);
        permission_audit_record($pdo, $actorId, 'user', $userId, (string)$username, 'CHANGE_PASSWORD', 'Đổi mật khẩu bởi quản trị viên');
    });
}

function access_create_role(array $input, int $actorId): int {
    require_permission('access.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    $code = strtoupper(trim((string)($input['code'] ?? '')));
    $name = trim((string)($input['name'] ?? ''));
    if (!preg_match('/^[A-Z][A-Z0-9_]{1,49}$/', $code)) {
        throw new InvalidArgumentException('Mã nhóm phải bắt đầu bằng chữ hoa và chỉ gồm A-Z, 0-9, _ (2–50 ký tự).');
    }
    $nameLen = preg_match_all('/./us', $name);
    if ($nameLen === false || $nameLen < 1 || $nameLen > 150) {
        throw new InvalidArgumentException('Tên nhóm phải từ 1–150 ký tự.');
    }

    return run_with_deadlock_retry(db(), function (PDO $pdo) use ($actorId, $code, $name): int {
        $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll();
        if (!permission_for_user($actorId, 'access.manage')) throw new InvalidArgumentException('Quyền quản lý phân quyền đã bị thu hồi.');
        $dup = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE LOWER(code)=LOWER(?)');
        $dup->execute([$code]);
        if ((int)$dup->fetchColumn() > 0) throw new InvalidArgumentException("Mã nhóm '{$code}' đã tồn tại.");

        $stmt = $pdo->prepare('INSERT INTO roles(code,name) VALUES(?,?)');
        $stmt->execute([$code, $name]);
        $rid = (int)$pdo->lastInsertId();
        permission_audit_record($pdo, $actorId, 'role', $rid, $code, 'CREATE_ROLE', ['name' => $name]);
        return $rid;
    });
}

function access_update_role(array $input, int $actorId): void {
    require_permission('access.manage');
    if ($actorId !== (int)($_SESSION['admin_id'] ?? 0)) throw new InvalidArgumentException('Người thao tác không hợp lệ.');
    $roleId = filter_var($input['role_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $name = trim((string)($input['name'] ?? ''));
    if ($roleId === false) throw new InvalidArgumentException('Nhóm không hợp lệ.');
    $nameLen = preg_match_all('/./us', $name);
    if ($nameLen === false || $nameLen < 1 || $nameLen > 150) throw new InvalidArgumentException('Tên nhóm phải từ 1–150 ký tự.');

    run_with_deadlock_retry(db(), function (PDO $pdo) use ($actorId, $roleId, $name): void {
        $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll();
        if (!permission_for_user($actorId, 'access.manage')) throw new InvalidArgumentException('Quyền quản lý phân quyền đã bị thu hồi.');
        $cur = $pdo->prepare('SELECT code,name FROM roles WHERE id=?');
        $cur->execute([$roleId]);
        $old = $cur->fetch();
        if (!$old) throw new InvalidArgumentException('Nhóm không tồn tại.');

        $pdo->prepare('UPDATE roles SET name=? WHERE id=?')->execute([$name, $roleId]);
        permission_audit_record($pdo, $actorId, 'role', $roleId, $old['code'], 'UPDATE_ROLE', [
            'old_name' => $old['name'],
            'new_name' => $name,
        ]);
    });
}

<?php
/**
 * User and role administration.
 *
 * Users are never deleted, only disabled. New accounts and admin resets get a
 * one-time password that must be changed at the next login. The Super Admin
 * role always holds every permission; system roles cannot be deleted.
 */
defined('QMS') || exit;

// ================================================================ users

/** User list with filters q, role_id, status. */
function user_list(array $filters, array &$paging): array
{
    $where  = [];
    $params = [];
    if (($filters['q'] ?? '') !== '') {
        $where[] = "(u.username LIKE ? ESCAPE '!' OR e.full_name LIKE ? ESCAPE '!' OR e.employee_code LIKE ? ESCAPE '!')";
        array_push($params, db_like($filters['q']), db_like($filters['q']), db_like($filters['q']));
    }
    if (ctype_digit((string) ($filters['role_id'] ?? ''))) {
        $where[]  = 'u.role_id = ?';
        $params[] = (int) $filters['role_id'];
    }
    if (in_array($filters['status'] ?? '', ['ACTIVE', 'DISABLED'], true)) {
        $where[]  = 'u.status = ?';
        $params[] = $filters['status'];
    }
    $from = ' FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN employees e ON e.id = u.employee_id'
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
    $paging['total'] = (int) db_value('SELECT COUNT(*)' . $from, $params);

    return db_all('SELECT u.id, u.username, u.email, u.status, u.locked_until, u.last_login_at, u.must_change_password,
                          r.name AS role_name, r.code AS role_code, e.employee_code, e.full_name' . $from
        . ' ORDER BY u.username LIMIT ? OFFSET ?', [...$params, $paging['per_page'], $paging['offset']]);
}

/** One user without password hash or security stamp. */
function user_find(int $id): array
{
    $row = db_row('SELECT u.*, r.code AS role_code, r.is_super_admin, e.employee_code, e.full_name
                     FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN employees e ON e.id = u.employee_id
                    WHERE u.id = ?', [$id]) ?? not_found('User not found.');
    unset($row['password_hash'], $row['security_stamp']);

    return $row;
}

/**
 * Creates a user with a one-time password.
 *
 * @return array{id: int, password: string}
 */
function user_create(array $input, array $actor): array
{
    $clean    = user_validate($input, null, $actor);
    $password = password_generate(14);
    $now      = now_str();

    return db_transaction(static function () use ($clean, $password, $now, $actor): array {
        $id = db_insert('users', $clean + [
            'password_hash'        => password_hash($password, password_algorithm()),
            'status'               => 'ACTIVE',
            'must_change_password' => 1,
            'security_stamp'       => bin2hex(random_bytes(16)),
            'created_at'           => $now,
            'created_by'           => $actor['id'],
            'updated_at'           => $now,
            'updated_by'           => $actor['id'],
        ]);
        audit_log('CREATE', 'user', $id, null, $clean, $clean['username']);

        return ['id' => $id, 'password' => $password];
    });
}

function user_update(int $id, array $input, array $actor): void
{
    $before = user_find($id);
    $clean  = user_validate($input, $before, $actor);
    unset($clean['username']); // usernames never change
    if ((int) $clean['role_id'] !== (int) $before['role_id'] && $id === (int) $actor['id']) {
        fail_field('role_id', 'You cannot change your own role.');
    }

    db_transaction(static function () use ($id, $clean, $before, $actor): void {
        $update = $clean + ['updated_at' => now_str(), 'updated_by' => $actor['id']];
        if ((int) $clean['role_id'] !== (int) $before['role_id']) {
            $update['security_stamp'] = bin2hex(random_bytes(16)); // log in again with the new role
        }
        db_update('users', $update, 'id = ?', [$id]);
        audit_log('UPDATE', 'user', $id, array_intersect_key($before, $clean), $clean, (string) $before['username']);
    });
}

/** New one-time password (returned once to show to the administrator). */
function user_reset_password(int $id, array $actor): string
{
    $user     = db_row('SELECT * FROM users WHERE id = ?', [$id]) ?? not_found('User not found.');
    $password = password_generate(14);
    auth_set_password($user, $password, true);
    login_log($id, (string) $user['username'], 'PASSWORD_RESET', 'by ' . $actor['username']);

    return $password;
}

function user_unlock(int $id, array $actor): void
{
    $user = user_find($id);
    db_transaction(static function () use ($id, $user, $actor): void {
        db_update('users', ['failed_login_count' => 0, 'locked_until' => null, 'updated_at' => now_str(), 'updated_by' => $actor['id']], 'id = ?', [$id]);
        audit_log('UNLOCK', 'user', $id, ['locked_until' => $user['locked_until']], ['locked_until' => null], (string) $user['username']);
        login_log($id, (string) $user['username'], 'ACCOUNT_UNLOCKED', 'by ' . $actor['username']);
    });
}

/** Enables or disables an account (a disabled account's sessions end at once). Returns the new status. */
function user_toggle_status(int $id, array $actor): string
{
    if ($id === (int) $actor['id']) {
        fail('You cannot disable your own account.', 403);
    }
    $user   = user_find($id);
    $status = $user['status'] === 'ACTIVE' ? 'DISABLED' : 'ACTIVE';
    db_transaction(static function () use ($id, $user, $status, $actor): void {
        db_update('users', ['status' => $status, 'security_stamp' => bin2hex(random_bytes(16)), 'updated_at' => now_str(), 'updated_by' => $actor['id']], 'id = ?', [$id]);
        audit_log($status === 'ACTIVE' ? 'ENABLE' : 'DISABLE', 'user', $id, ['status' => $user['status']], ['status' => $status], (string) $user['username']);
    });

    return $status;
}

function user_roles(): array
{
    return db_all('SELECT id, code, name, is_super_admin FROM roles ORDER BY id');
}

/** Active employees without a login (plus the currently linked one). */
function user_linkable_employees(?int $current = null): array
{
    return db_all('SELECT e.id, e.employee_code, e.full_name FROM employees e LEFT JOIN users u ON u.employee_id = e.id
                    WHERE e.is_active = 1 AND (u.id IS NULL OR e.id = ?) ORDER BY e.full_name', [$current ?? 0]);
}

function user_validate(array $input, ?array $existing, array $actor): array
{
    $text     = static fn (string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';
    $errors   = [];
    $username = $existing['username'] ?? $text('username');
    $email    = $text('email');
    $roleId   = ctype_digit($text('role_id')) ? (int) $text('role_id') : 0;
    $employee = ctype_digit($text('employee_id')) ? (int) $text('employee_id') : null;

    if ($existing === null) {
        if (! preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $errors['username'] = 'Use 3-50 characters: letters, digits, dot, dash, underscore.';
        } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE username = ?', [$username]) > 0) {
            $errors['username'] = 'This username is already taken.';
        }
    }
    if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) {
        $errors['email'] = 'Enter a valid e-mail address.';
    } elseif ($email !== '' && (int) db_value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $existing['id'] ?? 0]) > 0) {
        $errors['email'] = 'This e-mail address is already used by another account.';
    }
    $role = db_row('SELECT * FROM roles WHERE id = ?', [$roleId]);
    if ($role === null) {
        $errors['role_id'] = 'Choose a role.';
    } elseif ((int) $role['is_super_admin'] === 1 && (int) ($actor['is_super_admin'] ?? 0) !== 1) {
        $errors['role_id'] = 'Only a Super Admin can assign the Super Admin role.';
    }
    if ($employee !== null) {
        if ((int) db_value('SELECT COUNT(*) FROM employees WHERE id = ? AND is_active = 1', [$employee]) === 0) {
            $errors['employee_id'] = 'Choose an active employee.';
        } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE employee_id = ? AND id <> ?', [$employee, $existing['id'] ?? 0]) > 0) {
            $errors['employee_id'] = 'This employee already has a login.';
        }
    }
    if ($errors !== []) {
        throw new ValidationError($errors);
    }

    return ['username' => $username, 'email' => $email === '' ? null : $email, 'role_id' => $roleId, 'employee_id' => $employee];
}

// ================================================================ roles

function role_list(): array
{
    return db_all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
                          (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
                     FROM roles r ORDER BY r.id');
}

function role_find(int $id): array
{
    return db_row('SELECT * FROM roles WHERE id = ?', [$id]) ?? not_found('Role not found.');
}

/** Permissions grouped by module, each flagged when the role holds it. */
function role_matrix(int $roleId): array
{
    $role    = role_find($roleId);
    $granted = array_fill_keys(db_column('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]), true);
    $grouped = [];
    foreach (db_all('SELECT * FROM permissions ORDER BY id') as $perm) {
        $perm['granted']            = (int) $role['is_super_admin'] === 1 || isset($granted[$perm['id']]);
        $grouped[$perm['module']][] = $perm;
    }

    return $grouped;
}

function role_create(string $code, string $name, string $description, array $actor): int
{
    $code = strtoupper(trim($code));
    $name = trim($name);
    if (! preg_match('/^[A-Z][A-Z0-9_]{2,29}$/', $code)) {
        fail_field('code', 'Code: 3-30 characters, capital letters, digits and underscore.');
    }
    if ($name === '' || mb_strlen($name) > 60) {
        fail_field('name', 'Enter a name (max 60 characters).');
    }
    if ((int) db_value('SELECT COUNT(*) FROM roles WHERE code = ?', [$code]) > 0) {
        fail_field('code', 'This role code already exists.');
    }

    return db_transaction(static function () use ($code, $name, $description, $actor): int {
        $now = now_str();
        $id  = db_insert('roles', ['code' => $code, 'name' => $name, 'description' => mb_substr(trim($description), 0, 255) ?: null,
            'is_system' => 0, 'is_super_admin' => 0, 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now]);
        audit_log('CREATE', 'role', $id, null, ['code' => $code, 'name' => $name], $code);

        return $id;
    });
}

/** Replaces the role's permissions and updates name / description. */
function role_update(int $roleId, string $name, string $description, array $permissionCodes, array $actor): void
{
    $role = role_find($roleId);
    $name = trim($name);
    if ((int) $role['is_super_admin'] === 1) {
        fail('The Super Admin role always has every permission and cannot be edited.', 403);
    }
    if ((int) $actor['role_id'] === $roleId) {
        fail('You cannot change the permissions of your own role.', 403);
    }
    if ($name === '' || mb_strlen($name) > 60) {
        fail_field('name', 'Enter a name (max 60 characters).');
    }
    $valid = array_column(db_all('SELECT id, code FROM permissions'), 'id', 'code');
    $ids   = [];
    foreach (array_unique($permissionCodes) as $code) {
        if (! isset($valid[$code])) {
            fail_field('permissions', 'Unknown permission ' . $code);
        }
        $ids[] = (int) $valid[$code];
    }
    $before = db_column('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.code', [$roleId]);

    db_transaction(static function () use ($roleId, $role, $name, $description, $ids, $before, $permissionCodes, $actor): void {
        $now = now_str();
        db_update('roles', ['name' => $name, 'description' => mb_substr(trim($description), 0, 255) ?: null, 'updated_at' => $now, 'updated_by' => $actor['id']],
            'id = ?', [$roleId]);
        db_exec('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        foreach ($ids as $pid) {
            db_insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $pid, 'created_at' => $now, 'created_by' => $actor['id']]);
        }
        $after = array_values(array_unique($permissionCodes));
        sort($after);
        audit_log('UPDATE', 'role', $roleId, ['name' => $role['name'], 'permissions' => $before], ['name' => $name, 'permissions' => $after], (string) $role['code']);
    });
}

function role_delete(int $roleId, array $actor): void
{
    $role = role_find($roleId);
    if ((int) $role['is_system'] === 1) {
        fail('System roles cannot be deleted.', 403);
    }
    if ((int) db_value('SELECT COUNT(*) FROM users WHERE role_id = ?', [$roleId]) > 0) {
        fail_field('role', 'Move the users of this role to another role first.');
    }
    db_transaction(static function () use ($roleId, $role): void {
        db_exec('DELETE FROM roles WHERE id = ?', [$roleId]);
        audit_log('DELETE', 'role', $roleId, ['code' => $role['code'], 'name' => $role['name']], null, (string) $role['code']);
    });
}

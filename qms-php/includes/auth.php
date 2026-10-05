<?php
/**
 * Login, logout, sessions, permissions and the password policy.
 *
 * - One generic failure message; unknown users get a dummy hash check (same timing).
 * - Lockout after N failures (Settings → Security), rate limit per IP.
 * - Session id regenerated on login; idle and absolute timeouts.
 * - The account's security stamp ends all other sessions after a password,
 *   role or status change.
 */
defined('QMS') || exit;

const AUTH_FAILURE    = 'Invalid username or password, or the account is locked.';
/** Hashes of random strings: unknown usernames cost the same time as real ones. */
const AUTH_DUMMY_ARGON2 = '$argon2id$v=19$m=65536,t=4,p=1$Q1djaTVhcXZhWG5oTXl4eQ$ghw+iHAltbD0OumxcxFs6dVbXSdjDMq55WSx/4Z6RWo';
const AUTH_DUMMY_BCRYPT = '$2y$10$Dh6KOrtL0yM5xnFnOIPrT.n7xnUUvTjPuv6IzXsWSXadFv3QcHDUK';

/**
 * The logged-in user (with role and employee) or null.
 */
function current_user(bool $reload = false): ?array
{
    static $user = false;
    if ($user !== false && ! $reload) {
        return $user;
    }
    $user = null;
    $uid  = $_SESSION['qms_uid'] ?? null;
    if (! is_int($uid)) {
        return null;
    }

    $row = db_row('SELECT u.id, u.username, u.email, u.status, u.must_change_password, u.security_stamp, u.employee_id, u.role_id,
                          u.password_changed_at, r.code AS role_code, r.name AS role_name, r.is_super_admin,
                          e.employee_code, e.full_name, e.designation
                     FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN employees e ON e.id = u.employee_id
                    WHERE u.id = ?', [$uid]);
    if ($row === null || $row['status'] !== 'ACTIVE' || ! hash_equals((string) $row['security_stamp'], (string) ($_SESSION['qms_stamp'] ?? ''))) {
        login_log($uid, (string) ($row['username'] ?? 'user #' . $uid), 'SESSION_REVOKED', null);
        auth_end_session();

        return null;
    }
    unset($row['security_stamp']);
    $row['id']           = (int) $row['id'];
    $row['employee_id']  = $row['employee_id'] === null ? null : (int) $row['employee_id'];
    $row['display_name'] = $row['full_name'] ?: $row['username'];
    $user                = $row;
    audit_actor(['id' => $row['id'], 'username' => $row['username'], 'employee_id' => $row['employee_id']], true);

    return $user;
}

/** True when the current user holds at least one of the permissions. */
function can(string ...$permissions): bool
{
    $user = current_user();

    return $user !== null && user_can($user, ...$permissions);
}

function user_can(array $user, string ...$permissions): bool
{
    $set = role_permission_set((int) $user['role_id'], (bool) $user['is_super_admin']);
    foreach ($permissions as $permission) {
        if (isset($set[$permission])) {
            return true;
        }
    }

    return false;
}

/** @return array<string, true> permission codes of a role (Super Admin: all) */
function role_permission_set(int $roleId, bool $superAdmin): array
{
    static $cache = [];
    if (! isset($cache[$roleId])) {
        $codes = $superAdmin
            ? db_column('SELECT code FROM permissions')
            : db_column('SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ?', [$roleId]);
        $cache[$roleId] = array_fill_keys($codes, true);
    }

    return $cache[$roleId];
}

/**
 * Stops the page unless a user is logged in (redirect to login, or 401 for fetch).
 * Also applies the idle / absolute timeout and the forced password change.
 */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        auth_to_login('Please log in.');
    }
    if (! auth_touch()) {
        auth_to_login('Your session expired. Please log in again.');
    }
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (auth_must_change_password($user) && ! in_array($page, ['account.php', 'logout.php'], true)) {
        if (wants_json()) {
            show_error(403, 'You must change your password first.');
        }
        redirect('account.php');
    }
    // Pages with personal data are never cached.
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');

    return $user;
}

/**
 * Stops the page unless the user holds one of the permissions (audited).
 */
function require_permission(string ...$permissions): array
{
    $user = require_login();
    if (! user_can($user, ...$permissions)) {
        audit_log('ACCESS_DENIED', 'security', null, null, [
            'required' => implode(',', $permissions),
            'path'     => '/' . current_page(),
            'method'   => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ]);
        show_error(403, 'You do not have permission to open this page or perform this action.');
    }

    return $user;
}

function auth_to_login(string $message): never
{
    if (wants_json()) {
        json_out(['ok' => false, 'error' => $message, 'login' => url('login.php')], 401);
    }
    if (! is_post()) {
        $_SESSION['qms_intended'] = current_page();
    }
    flash('info', $message);
    redirect('login.php');
}

/** Idle and absolute session limits (Settings → Security). False when expired. */
function auth_touch(): bool
{
    $now      = time();
    $idle     = max(1, setting_int('security.session_idle_minutes', 30)) * 60;
    $absolute = max(1, setting_int('security.session_absolute_hours', 12)) * 3600;
    if ($now - (int) ($_SESSION['qms_last_seen'] ?? 0) > $idle || $now - (int) ($_SESSION['qms_login_at'] ?? 0) > $absolute) {
        auth_logout('SESSION_TIMEOUT');

        return false;
    }
    $_SESSION['qms_last_seen'] = $now;

    return true;
}

function auth_must_change_password(array $user): bool
{
    if ((int) $user['must_change_password'] === 1) {
        return true;
    }
    $days = setting_int('security.password_expiry_days', 0);
    if ($days <= 0) {
        return false;
    }
    $changed = (string) ($user['password_changed_at'] ?? '');

    return $changed === '' || $changed < now_utc()->modify("-{$days} days")->format('Y-m-d H:i:s');
}

/**
 * Checks username and password and starts the session.
 *
 * @return array{ok: bool, message: string}
 */
function auth_attempt(string $username, string $password): array
{
    $username = trim($username);
    $user     = $username === '' ? null : db_row('SELECT * FROM users WHERE username = ?', [$username]);
    $now      = now_str();

    if ($user === null || $user['status'] !== 'ACTIVE' || ($user['locked_until'] !== null && $user['locked_until'] > $now)) {
        password_verify($password, password_algorithm() === PASSWORD_BCRYPT ? AUTH_DUMMY_BCRYPT : AUTH_DUMMY_ARGON2);
        $reason = $user === null ? 'UNKNOWN_USER' : ($user['status'] !== 'ACTIVE' ? 'DISABLED' : 'LOCKED');
        login_log($user === null ? null : (int) $user['id'], $username, 'LOGIN_FAILED', $reason);

        return ['ok' => false, 'message' => AUTH_FAILURE];
    }

    if (! password_verify($password, $user['password_hash'])) {
        $max      = max(1, setting_int('security.max_failed_logins', 5));
        $minutes  = max(1, setting_int('security.lockout_minutes', 15));
        $failures = (int) $user['failed_login_count'] + 1;
        $update   = ['failed_login_count' => $failures, 'updated_at' => $now];
        if ($failures >= $max) {
            $update['locked_until']       = now_utc()->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
            $update['failed_login_count'] = 0;
        }
        db_update('users', $update, 'id = ?', [(int) $user['id']]);
        login_log((int) $user['id'], $username, 'LOGIN_FAILED', 'BAD_PASSWORD');
        if ($failures >= $max) {
            login_log((int) $user['id'], $username, 'ACCOUNT_LOCKED', "{$max} consecutive failures");
        }

        return ['ok' => false, 'message' => AUTH_FAILURE];
    }

    $update = ['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => $now, 'last_login_ip' => mb_substr(client_ip(), 0, 45), 'updated_at' => $now];
    if (password_needs_rehash($user['password_hash'], password_algorithm())) {
        $update['password_hash'] = password_hash($password, password_algorithm());
    }
    db_update('users', $update, 'id = ?', [(int) $user['id']]);

    // New session id and CSRF token, bound to the account's security stamp.
    session_regenerate_id(true);
    unset($_SESSION['_csrf']);
    $_SESSION['qms_uid']             = (int) $user['id'];
    $_SESSION['qms_stamp']           = $user['security_stamp'];
    $_SESSION['qms_login_at']        = time();
    $_SESSION['qms_last_seen']       = time();
    $_SESSION['qms_reauth_failures'] = 0;
    current_user(true);
    login_log((int) $user['id'], (string) $user['username'], 'LOGIN_SUCCESS', null);

    return ['ok' => true, 'message' => ''];
}

/** Too many failed logins from this IP (10 per minute, 60 per hour)? */
function login_throttled(): bool
{
    $ip     = mb_substr(client_ip(), 0, 45);
    $minute = (int) db_value("SELECT COUNT(*) FROM login_logs WHERE ip_address = ? AND event = 'LOGIN_FAILED' AND created_at > ?",
        [$ip, gmdate('Y-m-d H:i:s', time() - 60)]);
    if ($minute >= 10) {
        return true;
    }
    $hour = (int) db_value("SELECT COUNT(*) FROM login_logs WHERE ip_address = ? AND event = 'LOGIN_FAILED' AND created_at > ?",
        [$ip, gmdate('Y-m-d H:i:s', time() - 3600)]);

    return $hour >= 60;
}

function auth_logout(string $event = 'LOGOUT'): void
{
    $uid = $_SESSION['qms_uid'] ?? null;
    if (is_int($uid)) {
        $name = (string) (db_value('SELECT username FROM users WHERE id = ?', [$uid]) ?? '');
        login_log($uid, $name, $event, null);
    }
    auth_end_session();
}

function auth_end_session(): void
{
    unset($_SESSION['qms_uid'], $_SESSION['qms_stamp'], $_SESSION['qms_login_at'], $_SESSION['qms_last_seen'], $_SESSION['qms_reauth_failures']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    audit_actor(null, true);
}

/**
 * Password re-entry for e-signatures. Too many failures end the session.
 */
function auth_confirm_password(string $password): void
{
    $user = current_user();
    if ($user === null) {
        fail('Please log in again.', 403);
    }
    $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if ($password !== '' && password_verify($password, $hash)) {
        $_SESSION['qms_reauth_failures'] = 0;

        return;
    }
    $failures                         = (int) ($_SESSION['qms_reauth_failures'] ?? 0) + 1;
    $_SESSION['qms_reauth_failures'] = $failures;
    audit_log('REAUTH_FAILED', 'auth', $user['id'], null, ['failures' => $failures]);
    if ($failures >= 5) {
        auth_logout('SESSION_REVOKED');
        fail('Too many wrong passwords. You have been logged out.', 403);
    }
    fail('The password is not correct. The signature was not applied.', 403);
}

function auth_change_password(int $userId, string $current, string $new, string $confirm): void
{
    $user = db_row('SELECT u.*, e.employee_code, e.full_name FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ?', [$userId]);
    if ($user === null || ! password_verify($current, $user['password_hash'])) {
        fail_field('current_password', 'The current password is not correct.');
    }
    if ($new !== $confirm) {
        fail_field('confirm_password', 'The new passwords do not match.');
    }
    if (hash_equals($current, $new)) {
        fail_field('new_password', 'The new password must be different.');
    }
    $history  = db_column('SELECT password_hash FROM user_password_history WHERE user_id = ? ORDER BY id DESC LIMIT ' . password_history_depth(), [$userId]);
    $problems = password_check($new, $user, [$user['password_hash'], ...$history]);
    if ($problems !== []) {
        throw new ValidationError(['new_password' => implode(' ', $problems)], implode(' ', $problems));
    }
    auth_set_password($user, $new, false);
    login_log($userId, (string) $user['username'], 'PASSWORD_CHANGED', null);

    // This session stays valid with the new stamp; other sessions end.
    if (($_SESSION['qms_uid'] ?? null) === $userId) {
        $_SESSION['qms_stamp'] = db_value('SELECT security_stamp FROM users WHERE id = ?', [$userId]);
        session_regenerate_id(true);
        current_user(true);
    }
}

/** New password hash, history entry and security stamp. */
function auth_set_password(array $user, string $password, bool $mustChange): void
{
    $now  = now_str();
    $hash = password_hash($password, password_algorithm());
    db_transaction(static function () use ($user, $hash, $mustChange, $now): void {
        db_insert('user_password_history', ['user_id' => $user['id'], 'password_hash' => $user['password_hash'], 'created_at' => $now]);
        db_update('users', [
            'password_hash' => $hash, 'password_changed_at' => $now, 'must_change_password' => $mustChange ? 1 : 0,
            'failed_login_count' => 0, 'locked_until' => null, 'security_stamp' => bin2hex(random_bytes(16)), 'updated_at' => $now,
        ], 'id = ?', [(int) $user['id']]);
        audit_log($mustChange ? 'PASSWORD_RESET' : 'PASSWORD_CHANGE', 'user', (int) $user['id'], null, null, (string) $user['username']);
    });
}

function login_log(?int $userId, string $username, string $event, ?string $reason): void
{
    db_insert('login_logs', [
        'user_id'            => $userId,
        'username_attempted' => mb_substr($username, 0, 100),
        'event'              => $event,
        'failure_reason'     => $reason === null ? null : mb_substr($reason, 0, 100),
        'ip_address'         => mb_substr(client_ip(), 0, 45),
        'user_agent'         => user_agent(),
        'created_at'         => now_str(),
    ]);
}

// ---------------------------------------------------------------- password policy

function password_algorithm(): string|int
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

function password_min_length(): int
{
    return max(8, setting_int('security.password_min_length', 10));
}

function password_history_depth(): int
{
    return max(0, setting_int('security.password_history', 5));
}

/**
 * @param array $context username, employee_code, full_name
 * @param list<string> $previousHashes newest first
 *
 * @return list<string> problems (empty = acceptable)
 */
function password_check(string $password, array $context = [], array $previousHashes = []): array
{
    $problems = [];
    $length   = mb_strlen($password);
    $min      = password_min_length();
    if ($length < $min) {
        $problems[] = "Use at least {$min} characters.";
    }
    if ($length > 128) {
        $problems[] = 'Use at most 128 characters.';
    }
    if (password_algorithm() === PASSWORD_BCRYPT && strlen($password) > 72) {
        $problems[] = 'Use at most 72 bytes.';
    }
    $classes = (int) preg_match('/[a-z]/u', $password) + (int) preg_match('/[A-Z]/u', $password)
        + (int) preg_match('/\d/u', $password) + (int) preg_match('/[^a-zA-Z\d]/u', $password);
    if ($classes < 3) {
        $problems[] = 'Mix at least three of: lower case, upper case, digits, symbols.';
    }
    $lower = mb_strtolower($password);
    if (password_is_common($lower)) {
        $problems[] = 'This password is too common.';
    }
    foreach (['username', 'employee_code'] as $field) {
        $value = mb_strtolower(trim((string) ($context[$field] ?? '')));
        if (mb_strlen($value) >= 3 && str_contains($lower, $value)) {
            $problems[] = 'Do not use your username or employee code in the password.';
            break;
        }
    }
    foreach (preg_split('/\s+/', mb_strtolower((string) ($context['full_name'] ?? ''))) ?: [] as $part) {
        if (mb_strlen($part) >= 4 && str_contains($lower, $part)) {
            $problems[] = 'Do not use your name in the password.';
            break;
        }
    }
    $depth = password_history_depth();
    foreach (array_slice($previousHashes, 0, max(1, $depth)) as $hash) {
        if ($hash !== '' && password_verify($password, $hash)) {
            $problems[] = "Do not reuse one of your last {$depth} passwords.";
            break;
        }
    }

    return array_values(array_unique($problems));
}

function password_rules(): array
{
    return [
        'At least ' . password_min_length() . ' characters',
        'At least three of: lower case, upper case, digits, symbols',
        'Not a common password, and not your username, employee code or name',
        'Not one of your last ' . password_history_depth() . ' passwords',
    ];
}

/** Random password that satisfies the policy (admin resets). */
function password_generate(int $length = 14): string
{
    $sets  = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKMNPQRSTUVWXYZ', '23456789', '@#%+=?!'];
    $all   = implode('', $sets);
    $chars = [];
    foreach ($sets as $set) {
        $chars[] = $set[random_int(0, strlen($set) - 1)];
    }
    while (count($chars) < max($length, password_min_length())) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j                     = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return implode('', $chars);
}

function password_is_common(string $lowerPassword): bool
{
    static $list = null;
    if ($list === null) {
        $list = [];
        $file = QMS_ROOT . '/includes/data/common-passwords.txt';
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $list[$line] = true;
        }
    }

    return isset($list[$lowerPassword]);
}

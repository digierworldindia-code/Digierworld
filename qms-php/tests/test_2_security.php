<?php
/**
 * Security: login, lockout, password policy, CSRF tokens, uploads, permissions.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/uploads.php';
require_once QMS_ROOT . '/includes/masters.php';

function t_session(): void
{
    t_ok(session_status() === PHP_SESSION_ACTIVE, 'the test bootstrap starts a session');
}

function test_login_lockout_and_generic_message(): void
{
    t_session();
    $unknown = auth_attempt('nobody', 'whatever');
    $wrong   = auth_attempt('op2', 'wrong-password');
    t_same(false, $wrong['ok']);
    t_same($unknown['message'], $wrong['message'], 'unknown user and wrong password look the same');
    for ($i = 0; $i < 4; $i++) {
        auth_attempt('op2', 'wrong-password');
    }
    t_ok(db_value("SELECT locked_until FROM users WHERE username = 'op2'") !== null, 'locked after 5 failures');
    t_same(false, auth_attempt('op2', t_password())['ok'], 'correct password refused while locked');
    t_ok((int) db_value("SELECT COUNT(*) FROM login_logs WHERE event = 'ACCOUNT_LOCKED'") >= 1, 'lockout logged');
    db_update('users', ['locked_until' => null, 'failed_login_count' => 0], 'username = ?', ['op2']);
    t_same(true, auth_attempt('op2', t_password())['ok']);
    t_same('op2', current_user()['username']);
    t_ok(db_value("SELECT password_hash FROM users WHERE username = 'op2'") !== t_password(), 'passwords are stored as hashes only');
    t_ok(str_starts_with((string) db_value("SELECT password_hash FROM users WHERE username = 'op2'"), '$'), 'hash format');
}

function test_disabled_or_changed_accounts_lose_their_session(): void
{
    $user = t_login('viewer');
    t_ok($user !== null, 'logged in');
    db_update('users', ['security_stamp' => bin2hex(random_bytes(16))], 'username = ?', ['viewer']);
    t_same(null, current_user(true), 'a new security stamp ends the session');
}

function test_password_policy(): void
{
    t_ok(password_check('short') !== [], 'too short');
    t_ok(password_is_common('password') && password_is_common('qwerty123'), 'common passwords are listed');
    t_ok(in_array('This password is too common.', password_check('qwerty123'), true), 'common password refused');
    t_ok(password_check('Op-Brass-Valve-77', ['username' => 'op-brass']) !== [], 'username inside the password');
    t_same([], password_check('Copper-Spindle-Collet-17', ['username' => 'admin']));
    $hash = password_hash('Old-Secret-Value-11', PASSWORD_DEFAULT);
    t_ok(password_check('Old-Secret-Value-11', [], [$hash]) !== [], 'reuse refused');
    for ($i = 0; $i < 20; $i++) {
        t_same([], password_check(password_generate(14)), 'generated passwords meet the policy');
    }
}

function test_csrf_tokens_are_masked_and_checked(): void
{
    t_session();
    $a = csrf_token();
    $b = csrf_token();
    t_ok($a !== $b && strlen($a) === 128, 'a new mask on every call (BREACH)');
    $_POST = ['csrf_qms' => $a];
    t_same(true, csrf_valid());
    $_POST = ['csrf_qms' => str_repeat('0', 128)];
    t_same(false, csrf_valid());
    $_POST = ['csrf_qms' => 'x'];
    t_same(false, csrf_valid());
    $_POST = [];
}

function test_uploads_check_the_real_content(): void
{
    $dir  = sys_get_temp_dir();
    $fake = $dir . '/qms-fake-' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents($fake, "<?php echo 'not a pdf'; ?>");
    t_throws(static fn () => upload_accept(['name' => 'cert.pdf', 'type' => 'application/pdf', 'tmp_name' => $fake, 'error' => UPLOAD_ERR_OK, 'size' => 30],
        'certificate', 5120, UPLOAD_CERTIFICATE_MIMES), ValidationError::class, 422, 'not allowed');
    t_throws(static fn () => upload_accept(null, 'certificate', 5120, UPLOAD_CERTIFICATE_MIMES), ValidationError::class, 422, 'Choose a file');
    $pdf = $dir . '/qms-real-' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $stored = upload_store_certificate(upload_accept(['name' => '../../evil.php', 'tmp_name' => $pdf, 'error' => UPLOAD_ERR_OK], 'certificate', 5120, UPLOAD_CERTIFICATE_MIMES));
    t_ok(preg_match('/^[a-f0-9]{32}\.pdf$/', $stored['name']) === 1, 'random stored name, original name ignored');
    t_same(null, upload_path('certificates', '../config/config.php'), 'no path traversal');
    upload_delete('certificates', $stored['name']);
    @unlink($fake);
    @unlink($pdf);
}

function test_role_permissions(): void
{
    $viewer = t_login('viewer');
    t_same(false, user_can($viewer, 'inspection.create'));
    t_same(true, user_can($viewer, 'report.view'));
    $admin = t_login('admin');
    t_same(true, user_can($admin, 'user.manage', 'nonexistent.permission'), 'super admin');
    $op = t_login('op');
    t_same(false, user_can($op, 'template.manage', 'user.manage', 'setting.manage'));
}

function test_sql_values_are_always_bound(): void
{
    // Input that would break a concatenated query is stored and found as plain text.
    $admin = t_login('admin');
    $name  = "x'; DROP TABLE users; -- \"";
    $id    = master_create('departments', ['code' => 'SQLTEST', 'name' => $name], $admin);
    t_same($name, db_value('SELECT name FROM departments WHERE id = ?', [$id]));
    $paging = paging(30);
    t_same(1, count(master_list('departments', "'; DROP", 'all', $paging)));
    t_ok((int) db_value('SELECT COUNT(*) FROM users') > 0, 'users table still there');
    t_throws(static fn () => db_name('users; DROP'), InvalidArgumentException::class);
}

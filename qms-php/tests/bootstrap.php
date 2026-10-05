<?php
/**
 * Test helpers: fresh test database, users, assertions. Used by tests/run.php.
 *
 * The test database is EMPTIED on every run, so its name must contain "test".
 * Connection details come from environment variables (never from source code):
 *   QMS_TEST_DB_HOST (default 127.0.0.1), QMS_TEST_DB_PORT (3306),
 *   QMS_TEST_DB_NAME, QMS_TEST_DB_USER, QMS_TEST_DB_PASS
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('QMS_INSTALLER', true); // the tests do not need an installed copy
require dirname(__DIR__) . '/includes/init.php';

$GLOBALS['t_results'] = ['passed' => 0, 'failed' => 0, 'failures' => []];

// Login and CSRF code needs a session; start it before any output.
session_save_path(sys_get_temp_dir());
@session_start();

/** Connects to the test database and loads schema, reference data and demo data. */
function t_reset_database(): void
{
    $name = (string) getenv('QMS_TEST_DB_NAME');
    $user = (string) getenv('QMS_TEST_DB_USER');
    if ($name === '' || $user === '') {
        fwrite(STDERR, "Set QMS_TEST_DB_NAME, QMS_TEST_DB_USER and QMS_TEST_DB_PASS (and optionally QMS_TEST_DB_HOST / QMS_TEST_DB_PORT).\n");
        exit(2);
    }
    if (! str_contains(strtolower($name), 'test')) {
        fwrite(STDERR, "Refusing to run: the test database name must contain 'test' (it is emptied on every run).\n");
        exit(2);
    }
    db(db_connect((string) (getenv('QMS_TEST_DB_HOST') ?: '127.0.0.1'), (int) (getenv('QMS_TEST_DB_PORT') ?: 3306), $name, $user, (string) getenv('QMS_TEST_DB_PASS')));
    $GLOBALS['qms_config']['environment'] = 'development';
    $GLOBALS['qms_config']['base_url']    = 'http://localhost/';

    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_column('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()') as $table) {
        db()->exec('DROP TABLE IF EXISTS ' . db_name($table));
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    sql_run_file(QMS_ROOT . '/database/schema.sql');
    sql_run_file(QMS_ROOT . '/database/reference_data.sql');
    settings_refresh();

    $now   = now_str();
    $users = [
        ['admin', 'SUPER_ADMIN', null], ['op', 'OPERATOR', 'E1001'], ['op2', 'OPERATOR', 'E1002'], ['pe', 'PRODUCTION_ENGINEER', 'E3001'],
        ['qe', 'QUALITY_ENGINEER', 'E2001'], ['qa', 'QA_ADMIN', 'E4001'], ['viewer', 'VIEWER', null],
    ];
    sql_run_file(QMS_ROOT . '/database/demo_data.sql');
    foreach ($users as [$username, $role, $employee]) {
        db_insert('users', [
            'username' => $username, 'role_id' => (int) db_value('SELECT id FROM roles WHERE code = ?', [$role]),
            'employee_id' => $employee === null ? null : (int) db_value('SELECT id FROM employees WHERE employee_code = ?', [$employee]),
            'password_hash' => password_hash(t_password(), PASSWORD_DEFAULT), 'status' => 'ACTIVE', 'must_change_password' => 0,
            'security_stamp' => bin2hex(random_bytes(16)), 'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $admin = (int) db_value("SELECT id FROM users WHERE username = 'admin'");
    db_update('inspection_templates', ['status' => 'PUBLISHED', 'published_at' => $now, 'published_by' => $admin, 'updated_at' => $now], "status = 'DRAFT'");
}

/** Password of every test user. */
function t_password(): string
{
    return 'Test-Gauge-Bracket-91';
}

/**
 * Acts as a user: session, current_user() and the audit actor.
 *
 * @return array the user as current_user() returns it
 */
function t_login(string $username): array
{
    $row = db_row('SELECT id, security_stamp FROM users WHERE username = ?', [$username]) ?? throw new RuntimeException("No test user {$username}");
    $_SESSION = ['qms_uid' => (int) $row['id'], 'qms_stamp' => $row['security_stamp'], 'qms_login_at' => time(), 'qms_last_seen' => time()];

    return current_user(true);
}

function t_ok(bool $condition, string $message): void
{
    if (! $condition) {
        throw new AssertionError($message);
    }
}

function t_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** Runs $work and checks that it throws (optionally: class, HTTP status, part of the message). */
function t_throws(callable $work, string $class = Throwable::class, ?int $status = null, ?string $contains = null): Throwable
{
    try {
        $work();
    } catch (Throwable $e) {
        if (! $e instanceof $class) {
            throw new AssertionError("expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($status !== null && (! $e instanceof QmsError || $e->status !== $status)) {
            throw new AssertionError("expected status {$status}, got " . ($e instanceof QmsError ? $e->status : get_class($e)) . ': ' . $e->getMessage());
        }
        if ($contains !== null && ! str_contains($e->getMessage() . ' ' . json_encode($e instanceof QmsError ? $e->errors : []), $contains)) {
            throw new AssertionError("expected message containing '{$contains}', got: " . $e->getMessage());
        }

        return $e;
    }
    throw new AssertionError("expected an exception ({$class}), none was thrown");
}

/** Runs every function named test_* that the given file defines. */
function t_run_file(string $file): void
{
    $before = get_defined_functions()['user'];
    require $file;
    $tests = array_filter(array_diff(get_defined_functions()['user'], $before), static fn (string $f): bool => str_starts_with($f, 'test_'));
    t_say("\n" . basename($file));
    foreach ($tests as $test) {
        try {
            $test();
            $GLOBALS['t_results']['passed']++;
            t_say("  ok    {$test}");
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $GLOBALS['t_results']['failed']++;
            $GLOBALS['t_results']['failures'][] = "{$test}: " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            t_say("  FAIL  {$test}: " . $e->getMessage());
        }
    }
}

/** Prints a line without starting PHP output (sessions keep working in the CLI). */
function t_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

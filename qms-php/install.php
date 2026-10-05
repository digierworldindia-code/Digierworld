<?php
/**
 * One-time setup: database tables, first administrator and config/config.php.
 *
 * Protected by a one-time key in storage/setup-key.php (only someone with
 * access to the server files can read it; opened in a browser it shows nothing). After a successful installation
 * storage/installed.lock exists and this page does nothing any more.
 */
define('QMS_INSTALLER', true);
require __DIR__ . '/includes/init.php';

if (is_file(QMS_ROOT . '/config/config.php') && is_file(QMS_ROOT . '/storage/installed.lock')) {
    show_error(404, 'QMS is already installed. To install again, delete config/config.php and storage/installed.lock (and use an empty database).');
}

// ---------------------------------------------------------------- server check

$checks = [
    ['PHP 8.1 or newer', PHP_VERSION_ID >= 80100, PHP_VERSION, true],
];
foreach (['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'fileinfo' => true, 'gd' => true, 'openssl' => true, 'curl' => false] as $ext => $required) {
    $loaded   = extension_loaded($ext);
    $checks[] = ["PHP extension {$ext}", $loaded, $loaded ? 'loaded' : ($required ? 'missing' : 'missing (only needed for Google Sheets)'), $required];
}
foreach (['config', 'storage', 'storage/logs', 'storage/uploads', 'storage/sessions'] as $dir) {
    $path = QMS_ROOT . '/' . $dir;
    if (! is_dir($path)) {
        @mkdir($path, 0750, true);
    }
    if (is_dir($path) && ! is_file($path . '/index.html')) {
        @file_put_contents($path . '/index.html', "<!doctype html><title>403</title>\n"); // no listing if .htaccess is ignored
    }
    $checks[] = ["Writable folder {$dir}/", is_dir($path) && is_writable($path), is_writable($path) ? 'writable' : 'not writable by PHP', true];
}
// Apache and LiteSpeed read the .htaccess files that block the private folders; other servers need the rules from the README.
$software = substr((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 0, 60);
$htaccess = (bool) preg_match('/apache|litespeed/i', $software);
$checks[] = ['Web server reads .htaccess (blocks private folders)', $htaccess,
    $htaccess ? $software : ($software !== '' ? $software : 'unknown') . ' – add the deny rules from README section 4 before going live', false];
$blocked = false;
foreach ($checks as [, $ok, , $required]) {
    $blocked = $blocked || ($required && ! $ok);
}

// One-time key, created on the first visit.
$keyFile = QMS_ROOT . '/storage/setup-key.php';
if (! $blocked && ! is_file($keyFile)) {
    file_put_contents($keyFile, "<?php exit; ?>\nSetup key: " . bin2hex(random_bytes(10)) . "\n", LOCK_EX);
    @chmod($keyFile, 0600);
}

// ---------------------------------------------------------------- install

header('Cache-Control: no-store');
$values = [
    'setup_key' => '', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'base_url' => base_url(), 'company_name' => '', 'timezone' => 'Asia/Kolkata',
    'admin_username' => 'admin', 'admin_name' => '', 'admin_email' => '', 'demo' => '',
];
$errors = [];
$log    = [];

if (is_post() && ! $blocked) {
    foreach (array_keys($values) as $field) {
        $values[$field] = post($field);
    }
    $dbPass   = (string) ($_POST['db_pass'] ?? '');
    $password = (string) ($_POST['admin_password'] ?? '');

    try {
        $errors = install_validate($values, $password, (string) ($_POST['admin_password_confirm'] ?? ''), $keyFile);
        if ($errors === []) {
            install_run($values, $dbPass, $password, $log);
            $page_title = 'Setup complete';
            require QMS_ROOT . '/includes/views/install_done.php';
            exit;
        }
    } catch (Throwable $e) {
        $errors['general'] = $e instanceof PDOException
            ? 'Database error: ' . ($e->errorInfo[2] ?? $e->getMessage())
            : $e->getMessage();
        log_message('error', 'Setup failed: ' . $e->getMessage());
    }
}

$timezones  = DateTimeZone::listIdentifiers();
$page_title = 'QMS setup';
require QMS_ROOT . '/includes/views/install_form.php';
exit;

// ================================================================ functions

/**
 * @return array<string, string> field => error
 */
function install_validate(array $v, string $password, string $confirm, string $keyFile): array
{
    // Rate limit wrong keys: 10 attempts per 10 minutes per session.
    $_SESSION['setup_attempts'] = array_values(array_filter((array) ($_SESSION['setup_attempts'] ?? []), static fn ($t) => $t > time() - 600));
    if (count($_SESSION['setup_attempts']) >= 10) {
        return ['setup_key' => 'Too many attempts. Wait ten minutes and try again.'];
    }
    $expected = preg_match('/Setup key: ([a-f0-9]{20})/', (string) @file_get_contents($keyFile), $m) ? $m[1] : '';
    if ($expected === '' || ! hash_equals($expected, $v['setup_key'])) {
        $_SESSION['setup_attempts'][] = time();

        return ['setup_key' => 'The setup key does not match the one in storage/setup-key.php.'];
    }

    $errors = [];
    if (! preg_match('#^(/[A-Za-z0-9_./\-]+|[A-Za-z0-9.\-]+)$#', $v['db_host'])) {
        $errors['db_host'] = 'Enter a host name such as localhost (or a socket path).';
    }
    if (! ctype_digit($v['db_port']) || (int) $v['db_port'] < 1 || (int) $v['db_port'] > 65535) {
        $errors['db_port'] = 'Enter a port number (usually 3306).';
    }
    if (! preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $v['db_name'])) {
        $errors['db_name'] = 'Database names may use letters, digits, _ - and $.';
    }
    if ($v['db_user'] === '' || mb_strlen($v['db_user']) > 80) {
        $errors['db_user'] = 'Enter the MySQL user name.';
    }
    if (! preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~\-/]*)?$#', $v['base_url'])) {
        $errors['base_url'] = 'Enter the address of the site, e.g. https://qms.example.com/';
    }
    if ($v['company_name'] === '' || mb_strlen($v['company_name']) > 150) {
        $errors['company_name'] = 'Enter the company name (max 150 characters).';
    }
    if (! in_array($v['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errors['timezone'] = 'Choose the plant time zone.';
    }
    if (! preg_match('/^[A-Za-z0-9._-]{3,50}$/', $v['admin_username'])) {
        $errors['admin_username'] = '3-50 characters: letters, digits, dot, dash, underscore.';
    }
    if ($v['admin_email'] !== '' && filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['admin_email'] = 'Enter a valid e-mail address or leave it empty.';
    }
    if ($password !== $confirm) {
        $errors['admin_password_confirm'] = 'The passwords do not match.';
    } else {
        $problems = password_check($password, ['username' => $v['admin_username'], 'full_name' => $v['admin_name']]);
        if ($problems !== []) {
            $errors['admin_password'] = implode(' ', $problems);
        }
    }

    return $errors;
}

/**
 * Creates everything. Throws on failure (the form shows the message).
 */
function install_run(array $v, string $dbPass, string $password, array &$log): void
{
    $socket = str_starts_with($v['db_host'], '/');
    try {
        $pdo = db_connect($socket ? '' : $v['db_host'], (int) $v['db_port'], $v['db_name'], $v['db_user'], $dbPass, $socket ? $v['db_host'] : '');
    } catch (PDOException $e) {
        throw new RuntimeException('Could not connect to MySQL: ' . ($e->errorInfo[2] ?? $e->getMessage()));
    }
    db($pdo);

    $version = (string) db_value('SELECT VERSION()');
    if (stripos($version, 'mariadb') !== false) {
        throw new RuntimeException("This server runs MariaDB ({$version}). QMS needs MySQL 8.0.16 or newer.");
    }
    if (version_compare((string) preg_replace('/[^0-9.].*$/', '', $version), '8.0.16', '<')) {
        throw new RuntimeException("MySQL {$version} is too old. QMS needs MySQL 8.0.16 or newer.");
    }

    // The database must be empty, or hold only the tables of an earlier failed attempt.
    preg_match_all('/^CREATE TABLE (\w+)/m', (string) file_get_contents(QMS_ROOT . '/database/schema.sql'), $m);
    $ours   = $m[1];
    $tables = db_column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()");
    if (array_diff($tables, $ours) !== []) {
        throw new RuntimeException('This database already contains other tables. Create an empty database for QMS.');
    }
    if (in_array('users', $tables, true) && (int) db_value('SELECT COUNT(*) FROM users') > 0) {
        throw new RuntimeException('This database already contains a QMS installation with users. Use an empty database.');
    }
    if ($tables !== []) {
        $log[] = 'Removing the tables of an unfinished earlier attempt …';
        db()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse($ours) as $table) {
            db()->exec('DROP TABLE IF EXISTS ' . db_name($table));
        }
        db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    try {
        $count = sql_run_file(QMS_ROOT . '/database/schema.sql');
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($code, [1419, 1227], true)) {
            throw new RuntimeException('MySQL refused to create the integrity triggers (binary logging is on and the account has no SUPER privilege). '
                . 'Ask your host to set log_bin_trust_function_creators = 1, or use a VPS. MySQL said: ' . ($e->errorInfo[2] ?? ''));
        }
        throw $e;
    }
    $log[] = "Tables and integrity triggers created ({$count} statements).";

    db_transaction(static function () use ($v, $password, &$log): void {
        sql_run_file(QMS_ROOT . '/database/reference_data.sql');
        settings_refresh();
        $log[] = 'Roles, permissions, report types and default settings loaded.';

        $now    = now_str();
        $roleId = (int) db_value("SELECT id FROM roles WHERE code = 'SUPER_ADMIN'");
        $userId = db_insert('users', [
            'employee_id' => null, 'role_id' => $roleId, 'username' => $v['admin_username'],
            'email' => $v['admin_email'] !== '' ? $v['admin_email'] : null,
            'password_hash' => password_hash($password, password_algorithm()), 'status' => 'ACTIVE', 'must_change_password' => 0,
            'security_stamp' => bin2hex(random_bytes(16)), 'created_at' => $now, 'updated_at' => $now, 'password_changed_at' => $now,
        ]);
        audit_log('CREATE', 'user', $userId, null, ['username' => $v['admin_username'], 'role' => 'SUPER_ADMIN', 'via' => 'install.php'], $v['admin_username']);
        $log[] = "Administrator '{$v['admin_username']}' created.";

        if ($v['demo'] === '1') {
            sql_run_file(QMS_ROOT . '/database/demo_data.sql');
            db_update('inspection_templates', ['status' => 'PUBLISHED', 'published_at' => $now, 'published_by' => $userId, 'updated_at' => $now], "status = 'DRAFT'");
            $log[] = 'Demo data loaded and demo templates published.';
        }

        db_update('system_settings', ['setting_value' => $v['company_name'], 'updated_at' => $now], 'setting_key = ?', ['company.name']);
        db_update('system_settings', ['setting_value' => $v['timezone'], 'updated_at' => $now], 'setting_key = ?', ['regional.timezone']);
    });

    $config = [
        'base_url'                => rtrim($v['base_url'], '/') . '/',
        'environment'             => 'production',
        'force_https'             => str_starts_with(strtolower($v['base_url']), 'https://'),
        'db'                      => [
            'host' => $socket ? '' : $v['db_host'], 'port' => (int) $v['db_port'], 'socket' => $socket ? $v['db_host'] : '',
            'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => $dbPass,
        ],
        'trusted_proxies'         => [],
        'google_credentials_file' => '',
    ];
    $php = "<?php\n// QMS configuration - written by install.php on " . gmdate('Y-m-d H:i') . " UTC.\n"
        . "// Keep this file private: it contains the database password. Never upload it to Git.\n"
        . 'return ' . var_export($config, true) . ";\n";
    if (@file_put_contents(QMS_ROOT . '/config/config.php', $php, LOCK_EX) === false) {
        throw new RuntimeException('Could not write config/config.php. Make the config/ folder writable for PHP and run the setup again.');
    }
    @chmod(QMS_ROOT . '/config/config.php', 0600);
    file_put_contents(QMS_ROOT . '/storage/installed.lock', gmdate('c') . ' ' . QMS_VERSION . "\n");
    @unlink(QMS_ROOT . '/storage/setup-key.php');
    $log[] = 'Configuration saved. Setup is complete.';
    log_message('notice', 'QMS installed from ' . client_ip());
}

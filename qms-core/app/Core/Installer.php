<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Services;
use App\Services\Auth\PasswordPolicy;
use App\Services\Settings\SettingsService;
use DateTimeZone;
use Throwable;

/**
 * Installation steps shared by the web setup page (first visit, shared
 * hosting) and `php bin/qms.php install` (servers):
 *
 *   requirements → database checks → schema → reference data → admin account
 *   → optional demo data → company settings → .env → storage/installed.lock
 *
 * The web page only works until storage/installed.lock exists and asks for a
 * one-time key stored in storage/setup-key.txt, which only someone with access
 * to the server files can read.
 */
final class Installer
{
    public const SCHEMA_VERSION = '0000_qms_schema';

    // ================================================================ checks

    /**
     * @return list<array{label: string, ok: bool, detail: string, required: bool}>
     */
    public static function requirements(): array
    {
        $checks = [
            ['label' => 'PHP 8.2 or newer', 'ok' => PHP_VERSION_ID >= 80200, 'detail' => PHP_VERSION, 'required' => true],
        ];
        foreach (['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'openssl' => true, 'fileinfo' => true, 'gd' => true, 'curl' => false] as $ext => $required) {
            $checks[] = [
                'label'    => "PHP extension {$ext}",
                'ok'       => extension_loaded($ext),
                'detail'   => extension_loaded($ext) ? 'loaded' : ($required ? 'missing' : 'missing (needed only for Google Sheets)'),
                'required' => $required,
            ];
        }
        foreach (['storage/logs', 'storage/cache', 'storage/uploads', 'storage'] as $dir) {
            $path = ROOTPATH . $dir;
            if (! is_dir($path)) {
                @mkdir($path, 0750, true);
            }
            $checks[] = ['label' => "Writable folder {$dir}/", 'ok' => is_dir($path) && is_writable($path), 'detail' => is_writable($path) ? 'writable' : 'not writable by PHP', 'required' => true];
        }
        $checks[] = self::documentRootCheck();
        $checks[] = [
            'label'    => 'PDF library (mPDF in vendor/)',
            'ok'       => class_exists(\Mpdf\Mpdf::class),
            'detail'   => class_exists(\Mpdf\Mpdf::class) ? 'available' : 'missing: PDF export disabled until vendor/ is uploaded',
            'required' => false,
        ];
        $checks[] = [
            'label'    => 'Bootstrap files (public/assets/vendor)',
            'ok'       => is_file(FCPATH . 'assets/vendor/bootstrap/css/bootstrap.min.css'),
            'detail'   => is_file(FCPATH . 'assets/vendor/bootstrap/css/bootstrap.min.css') ? 'available' : 'missing: upload the release zip including public/assets/vendor',
            'required' => true,
        ];

        return $checks;
    }

    /**
     * The safe layout is "document root = public/". When the whole project
     * folder is inside the web root, the .htaccess files must be active,
     * otherwise .env and storage/ could be downloaded: setup refuses to run.
     *
     * @return array{label: string, ok: bool, detail: string, required: bool}
     */
    private static function documentRootCheck(): array
    {
        $label   = 'Private files outside the web root';
        $docroot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $public  = realpath(FCPATH);
        $root    = realpath(ROOTPATH);
        if (PHP_SAPI === 'cli' || $docroot === false || $public === false || $root === false) {
            return ['label' => $label, 'ok' => true, 'detail' => 'not checked', 'required' => false];
        }
        if ($docroot === $public || ! str_starts_with($root . DIRECTORY_SEPARATOR, $docroot . DIRECTORY_SEPARATOR)) {
            return ['label' => $label, 'ok' => true, 'detail' => 'document root is the public/ folder', 'required' => true];
        }

        $prefix = '/' . trim(str_replace('\\', '/', substr($public, strlen($docroot))), '/');
        $uri    = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
            return ['label' => $label, 'ok' => false, 'required' => true,
                'detail' => 'the server ignores the QMS .htaccess files: set the document root of the (sub)domain to the public/ folder'];
        }

        return ['label' => $label, 'ok' => false, 'required' => false,
            'detail' => 'protected by .htaccess; after setup check that ' . config('App')->baseURL . '.env shows an error page'];
    }

    /**
     * MySQL 8.0.16+ is required (CHECK constraints and triggers are enforced).
     */
    public static function checkServer(Database $db): void
    {
        $version = $db->getVersion();
        if (stripos($version, 'mariadb') !== false) {
            throw new \RuntimeException("This server runs MariaDB ({$version}). QMS needs MySQL 8.0.16 or newer.");
        }
        if (version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?? '0', '8.0.16', '<')) {
            throw new \RuntimeException("MySQL {$version} is too old. QMS needs MySQL 8.0.16 or newer.");
        }
    }

    /**
     * empty | partial (QMS tables without users: an earlier attempt failed) | installed | foreign
     */
    public static function databaseState(Database $db): string
    {
        $tables = array_column($db->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type IN ('BASE TABLE', 'VIEW')")->getResultArray(), 't');
        if ($tables === []) {
            return 'empty';
        }
        $ours    = array_merge(self::schemaTables(), ['schema_migrations']);
        $foreign = array_diff($tables, $ours);
        if ($foreign !== []) {
            return 'foreign';
        }
        if (in_array('users', $tables, true) && (int) $db->query('SELECT COUNT(*) AS n FROM users')->getRow('n') > 0) {
            return 'installed';
        }

        return 'partial';
    }

    /**
     * Drops the QMS tables of a failed installation (only when no user exists).
     */
    public static function dropPartial(Database $db, callable $log): void
    {
        if (self::databaseState($db) !== 'partial') {
            throw new \RuntimeException('Refusing to drop tables: the database is not an unfinished QMS installation.');
        }
        $log('Removing tables of the earlier, unfinished installation …');
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (array_merge(array_reverse(self::schemaTables()), ['schema_migrations']) as $table) {
                $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
        } finally {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    // ================================================================ steps

    /**
     * Creates the schema and applies database/migrations/*.sql once each.
     *
     * @return int number of migrations applied
     */
    public static function migrate(Database $db, callable $log): int
    {
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version    VARCHAR(190) NOT NULL,
            applied_at DATETIME     NOT NULL,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');

        $applied = array_column($db->query('SELECT version FROM schema_migrations')->getResultArray(), 'version');
        $files   = [self::SCHEMA_VERSION => ROOTPATH . 'database/schema/qms_schema.sql'];
        foreach (glob(ROOTPATH . 'database/migrations/*.sql') ?: [] as $file) {
            $files[basename($file, '.sql')] = $file;
        }
        ksort($files, SORT_STRING);

        $count = 0;
        foreach ($files as $version => $file) {
            if (in_array($version, $applied, true)) {
                continue;
            }
            $log("Applying {$version} …");
            try {
                SqlScript::run($db, $file);
            } catch (DatabaseException $e) {
                throw new \RuntimeException(self::explainDdlError($e), 0, $e);
            }
            $db->query('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())', [$version]);
            $count++;
        }
        if ($count === 0) {
            $log('Database schema is up to date.');
        }

        return $count;
    }

    /** Roles, permissions, report types, shifts, units, default settings. */
    public static function seedReference(Database $db, callable $log): void
    {
        if ((int) $db->query('SELECT COUNT(*) AS n FROM roles')->getRow('n') > 0) {
            $log('Reference data already present.');

            return;
        }
        $count = SqlScript::run($db, ROOTPATH . 'database/schema/qms_reference_data.sql');
        $log("Reference data loaded ({$count} statements).");
    }

    /**
     * DEMO ONLY: sample employees, machines, part, gauges, parameter library and
     * the three paper formats as templates, published by the first Super Admin.
     */
    public static function seedDemo(Database $db, callable $log): void
    {
        if ((int) $db->query('SELECT COUNT(*) AS n FROM parts')->getRow('n') > 0) {
            $log('Master data already present - demo data skipped.');

            return;
        }
        SqlScript::run($db, ROOTPATH . 'database/samples/qms_demo_templates.sql');
        $admin = $db->table('users u')->select('u.id')->join('roles r', 'r.id = u.role_id')
            ->where('r.code', 'SUPER_ADMIN')->orderBy('u.id')->get(1)->getRowArray();
        if ($admin !== null) {
            $now = gmdate('Y-m-d H:i:s');
            $db->table('inspection_templates')->where('status', 'DRAFT')
                ->update(['status' => 'PUBLISHED', 'published_at' => $now, 'published_by' => $admin['id'], 'updated_at' => $now]);
            $log('Demo data loaded and demo templates published.');
        } else {
            $log('Demo data loaded. Templates stay DRAFT until a Super Admin publishes them.');
        }
    }

    /**
     * @param array{username: string, password: string, email?: string|null, name?: string|null} $data
     *
     * @return int user id
     */
    public static function createAdmin(Database $db, array $data, bool $mustChangePassword): int
    {
        $username = $data['username'];
        if (! preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            throw new \InvalidArgumentException('Username must be 3-50 characters: letters, digits, dot, dash, underscore.');
        }
        if ($db->table('users')->where('username', $username)->countAllResults() > 0) {
            throw new \InvalidArgumentException("User '{$username}' already exists.");
        }
        $role = $db->table('roles')->where('code', 'SUPER_ADMIN')->get(1)->getRowArray();
        if ($role === null) {
            throw new \RuntimeException('Reference data missing. Run: php bin/qms.php seed');
        }

        $policy   = service('passwordPolicy');
        $problems = $policy->check($data['password'], ['username' => $username, 'full_name' => $data['name'] ?? null]);
        if ($problems !== []) {
            throw new \InvalidArgumentException('Password rejected: ' . implode(' ', $problems));
        }

        $now = gmdate('Y-m-d H:i:s');
        $db->table('users')->insert([
            'employee_id'          => null,
            'role_id'              => $role['id'],
            'username'             => $username,
            'email'                => ($data['email'] ?? '') !== '' ? $data['email'] : null,
            'password_hash'        => $policy->hash($data['password']),
            'status'               => 'ACTIVE',
            'must_change_password' => $mustChangePassword ? 1 : 0,
            'security_stamp'       => bin2hex(random_bytes(16)),
            'created_at'           => $now,
            'updated_at'           => $now,
            'password_changed_at'  => $now,
        ]);
        $userId = $db->insertID();

        $audit = service('audit');
        $audit->setActor(null);
        $audit->log('CREATE', 'user', $userId, null, ['username' => $username, 'role' => 'SUPER_ADMIN', 'via' => 'installer'], $username);

        return $userId;
    }

    /**
     * @param array<string, string> $values setting_key => value
     */
    public static function applySettings(Database $db, array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value !== '') {
                $db->table('system_settings')->where('setting_key', $key)->update(['setting_value' => $value, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            }
        }
        service('settings')->refresh();
    }

    /**
     * Writes .env (mode 0600) with the given values.
     *
     * @param array<string, string> $values
     */
    public static function writeEnv(array $values, ?string $file = null): void
    {
        $file ??= ROOTPATH . '.env';
        $lines = [
            '# QMS configuration - written by the installer on ' . gmdate('Y-m-d H:i') . ' UTC.',
            '# Keep this file private. Never commit it or put it inside public/.',
            '',
        ];
        foreach ($values as $key => $value) {
            $lines[] = $key . '=' . self::envValue($value);
        }
        $content = implode("\n", $lines) . "\n";

        $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write ' . basename($file) . ' in ' . dirname($file) . '. Make the folder writable for PHP (or create the file by hand).');
        }
        @chmod($tmp, 0600);
        if (! @rename($tmp, $file)) {
            @unlink($tmp);

            throw new \RuntimeException('Could not create ' . $file . '.');
        }
        Env::load($file);
    }

    public static function envValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@\-]+$/', $value)) {
            return $value;
        }

        return '"' . addcslashes($value, "\\\"\n\r\t\$") . '"';
    }

    public static function markInstalled(): void
    {
        $info = json_encode(['installed_at' => gmdate('c'), 'version' => config('Qms')->version], JSON_PRETTY_PRINT);
        if (@file_put_contents(WRITEPATH . 'installed.lock', $info . "\n") === false) {
            throw new \RuntimeException('Could not write storage/installed.lock. Make storage/ writable for PHP.');
        }
        @unlink(WRITEPATH . 'setup-key.txt');
    }

    /**
     * Password check with the default policy (before the settings table exists).
     *
     * @return list<string>
     */
    public static function precheckPassword(string $password, string $username, string $name): array
    {
        $settings = new class (new Database(['database' => '', 'username' => '', 'password' => ''])) extends SettingsService {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        };

        return (new PasswordPolicy($settings))->check($password, ['username' => $username, 'full_name' => $name]);
    }

    // ================================================================ web setup page

    /**
     * Handles every request while the application is not installed.
     */
    public function web(): void
    {
        $request  = Services::request();
        $response = new Response();
        $response->setHeader('Cache-Control', 'no-store');
        foreach (App::SECURITY_HEADERS as $name => $value) {
            $response->setHeader($name, $value);
        }
        $response->setHeader('Content-Security-Policy', App::contentSecurityPolicy());

        try {
            if (is_file(WRITEPATH . 'installed.lock')) {
                $body = view('install/message', ['title' => 'Configuration missing', 'message' => 'storage/installed.lock exists but the .env file is missing. Restore .env from your backup, or delete storage/installed.lock to run the setup again.']);
                $response->setStatusCode(503)->setBody($body)->send();

                return;
            }

            $checks   = self::requirements();
            $blocked  = array_filter($checks, static fn (array $c): bool => $c['required'] && ! $c['ok']) !== [];
            $keyReady = $this->ensureSetupKey();
            $values   = self::defaults();
            $errors   = [];
            $log      = [];

            if ($request->getMethod() === 'POST' && ! $blocked && $keyReady) {
                foreach (array_keys($values) as $field) {
                    $posted = $request->getPost($field);
                    $values[$field] = is_string($posted) ? trim($posted) : ($field === 'demo' ? '' : $values[$field]);
                }
                $password = (string) $request->getPost('admin_password');
                $confirm  = (string) $request->getPost('admin_password_confirm');
                $dbPass   = (string) $request->getPost('db_pass');

                [$ok, $errors, $log] = $this->install($request, $values, $password, $confirm, $dbPass);
                if ($ok) {
                    $response->setBody(view('install/done', ['log' => $log, 'loginUrl' => rtrim($values['app_url'], '/') . '/login', 'username' => $values['admin_username']]))->send();

                    return;
                }
            }

            $response->setBody(view('install/setup', [
                'checks' => $checks, 'blocked' => $blocked, 'keyReady' => $keyReady, 'values' => $values,
                'errors' => $errors, 'log' => $log, 'timezones' => DateTimeZone::listIdentifiers(),
            ]));
        } catch (Throwable $e) {
            log_message('critical', 'Setup page error: {e}', ['e' => $e]);
            $response->setStatusCode(500)->setBody('<!doctype html><meta charset="utf-8"><title>Setup error</title><p>Setup failed: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>');
        }

        $response->send();
    }

    /**
     * @param array<string, string> $v
     *
     * @return array{0: bool, 1: array<string, string>, 2: list<string>}
     */
    private function install(Request $request, array $v, string $password, string $confirm, string $dbPass): array
    {
        $errors = [];
        $log    = [];
        $say    = static function (string $line) use (&$log): void {
            $log[] = $line;
        };

        // 1. one-time key (rate limited)
        if (! service('throttler')->check('setup_' . $request->getIPAddress(), 10, 600)) {
            return [false, ['setup_key' => 'Too many attempts. Wait ten minutes and try again.'], []];
        }
        $expected = trim((string) @file_get_contents(WRITEPATH . 'setup-key.txt'));
        if ($expected === '' || ! hash_equals($expected, $v['setup_key'])) {
            return [false, ['setup_key' => 'The setup key does not match storage/setup-key.txt.'], []];
        }

        // 2. input
        if (! preg_match('#^(/[A-Za-z0-9_./\-]+|[A-Za-z0-9.\-]+|\[[0-9A-Fa-f:]+\])$#', $v['db_host'])) {
            $errors['db_host'] = 'Enter a host name such as localhost (or a socket path).';
        }
        if (! ctype_digit($v['db_port']) || (int) $v['db_port'] < 1 || (int) $v['db_port'] > 65535) {
            $errors['db_port'] = 'Enter a port number (usually 3306).';
        }
        if (! preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $v['db_name'])) {
            $errors['db_name'] = 'Database names may use letters, digits, _ - and $.';
        }
        if ($v['db_user'] === '' || mb_strlen($v['db_user']) > 80 || preg_match('/[\x00-\x1F]/', $v['db_user'])) {
            $errors['db_user'] = 'Enter the MySQL user name.';
        }
        if (! preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~\-/]*)?$#', $v['app_url'])) {
            $errors['app_url'] = 'Enter the address of the site, e.g. https://qms.example.com/';
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
        } elseif (($problems = self::precheckPassword($password, $v['admin_username'], $v['admin_name'])) !== []) {
            $errors['admin_password'] = implode(' ', $problems);
        }
        $envFile = ROOTPATH . '.env';
        if (is_file($envFile) ? ! is_writable($envFile) : ! is_writable(ROOTPATH)) {
            $errors['general'] = 'PHP cannot write the .env file in ' . ROOTPATH . '. Make that folder writable during setup, or create .env by hand from .env.example.';
        }
        if ($errors !== []) {
            return [false, $errors, []];
        }

        // 3. database
        $socket = str_starts_with($v['db_host'], '/');
        $db     = new Database([
            'host'     => $socket ? '' : $v['db_host'],
            'port'     => (int) $v['db_port'],
            'socket'   => $socket ? $v['db_host'] : '',
            'database' => $v['db_name'],
            'username' => $v['db_user'],
            'password' => $dbPass,
        ]);

        try {
            self::checkServer($db);
            $state = self::databaseState($db);
            if ($state === 'foreign') {
                return [false, ['db_name' => 'This database already contains other tables. Create an empty database for QMS.'], []];
            }
            if ($state === 'installed') {
                return [false, ['db_name' => 'This database already contains a QMS installation with users. Restore your .env file instead of running setup again.'], []];
            }

            Services::reset(false);
            Services::setConnection($db);
            if ($state === 'partial') {
                self::dropPartial($db, $say);
            }
            self::migrate($db, $say);

            $db->transBegin();
            try {
                self::seedReference($db, $say);
                self::createAdmin($db, [
                    'username' => $v['admin_username'], 'password' => $password,
                    'email' => $v['admin_email'], 'name' => $v['admin_name'],
                ], false);
                $say("Administrator '{$v['admin_username']}' created.");
                if ($v['demo'] === '1') {
                    self::seedDemo($db, $say);
                }
                self::applySettings($db, ['company.name' => $v['company_name'], 'regional.timezone' => $v['timezone']]);
                $db->transCommit();
            } catch (Throwable $e) {
                $db->transRollback();

                throw $e;
            }
        } catch (DatabaseException $e) {
            $message = in_array($e->getCode(), [1044, 1045, 1049, 2002, 2003, 2005, 2054], true)
                ? 'Could not connect to MySQL: ' . $e->getMessage()
                : 'Database error: ' . $e->getMessage();

            return [false, ['general' => $message], $log];
        } catch (Throwable $e) {
            return [false, ['general' => $e->getMessage()], $log];
        }

        // 4. configuration files
        try {
            self::writeEnv(self::envValues($v, $dbPass));
            self::markInstalled();
        } catch (Throwable $e) {
            return [false, ['general' => $e->getMessage() . ' The database is ready; create .env by hand from .env.example and storage/installed.lock (any content).'], $log];
        }
        $say('Configuration saved. Setup is complete.');
        log_message('notice', 'QMS installed by setup page from {ip}', ['ip' => $request->getIPAddress()]);

        return [true, [], $log];
    }

    /**
     * @param array<string, string> $v
     *
     * @return array<string, string>
     */
    public static function envValues(array $v, string $dbPass): array
    {
        $socket = str_starts_with($v['db_host'], '/');

        return [
            'APP_ENV'                 => 'production',
            'APP_URL'                 => rtrim($v['app_url'], '/') . '/',
            'DB_HOST'                 => $socket ? '' : $v['db_host'],
            'DB_PORT'                 => $v['db_port'],
            'DB_SOCKET'               => $socket ? $v['db_host'] : '',
            'DB_NAME'                 => $v['db_name'],
            'DB_USER'                 => $v['db_user'],
            'DB_PASS'                 => $dbPass,
            'GOOGLE_CREDENTIALS_FILE' => '',
            'TRUSTED_PROXIES'         => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function defaults(): array
    {
        return [
            'setup_key' => '', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
            'app_url' => config('App')->baseURL, 'company_name' => '', 'timezone' => 'Asia/Kolkata',
            'admin_username' => 'admin', 'admin_name' => '', 'admin_email' => '', 'demo' => '',
        ];
    }

    /** Creates storage/setup-key.txt on the first visit. */
    private function ensureSetupKey(): bool
    {
        $file = WRITEPATH . 'setup-key.txt';
        if (is_file($file)) {
            return true;
        }
        if (! is_dir(WRITEPATH) || ! is_writable(WRITEPATH)) {
            return false;
        }
        $ok = @file_put_contents($file, bin2hex(random_bytes(10)) . "\n", LOCK_EX) !== false;
        if ($ok) {
            @chmod($file, 0600);
        }

        return $ok;
    }

    /**
     * @return list<string> table names in schema order
     */
    private static function schemaTables(): array
    {
        preg_match_all('/^CREATE TABLE (\w+)/m', (string) file_get_contents(ROOTPATH . 'database/schema/qms_schema.sql'), $m);

        return $m[1];
    }

    private static function explainDdlError(DatabaseException $e): string
    {
        return match ($e->getCode()) {
            1419, 1227 => 'MySQL refused to create the integrity triggers (binary logging is on and the account has no SUPER privilege). '
                . 'Ask your host to set log_bin_trust_function_creators = 1 (or use a VPS), then run setup again. MySQL said: ' . $e->getMessage(),
            1142, 1044 => 'The MySQL account lacks privileges (CREATE, ALTER, INDEX, REFERENCES, TRIGGER are needed during setup). MySQL said: ' . $e->getMessage(),
            default    => 'Creating the database schema failed: ' . $e->getMessage(),
        };
    }
}

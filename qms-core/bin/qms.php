<?php

declare(strict_types=1);

/*
 * QMS command line tool.
 *
 *   php bin/qms.php install        first installation (asks for database and admin)
 *   php bin/qms.php migrate        create / upgrade the database tables
 *   php bin/qms.php seed           load reference data (roles, permissions, settings)
 *   php bin/qms.php seed:demo      load demo master data and templates (not for production)
 *   php bin/qms.php create-admin   create a Super Admin account
 *   php bin/qms.php sheets:sync    send waiting Google Sheets jobs (cron, every minute)
 *   php bin/qms.php sheets:test    check Google credentials and spreadsheet access
 *   php bin/qms.php maintenance    daily housekeeping (cron, once a day)
 *   php bin/qms.php down | up      maintenance mode on / off
 *   php bin/qms.php status         version, database and queue status
 */

use App\Core\Cli;
use App\Core\Installer;
use App\Services\Sync\SheetSyncWorker;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

Cli::parse(array_slice($argv, 1));
$command = Cli::argument(0) ?? 'help';

$commands = [
    'install'      => 'First installation: writes .env (when missing), creates tables, reference data and the admin account',
    'migrate'      => 'Creates or upgrades the database tables (needs CREATE/ALTER/TRIGGER privileges)',
    'seed'         => 'Loads reference data (roles, permissions, report types, settings) - runs once',
    'seed:demo'    => 'Loads demo master data and the three demo templates (never on production)',
    'create-admin' => 'Creates a Super Admin [--username=admin] [--name=] [--email=] [--generate | --password-from-env]',
    'sheets:sync'  => 'Sends waiting Google Sheets jobs [--limit=40] [--quiet]',
    'sheets:test'  => 'Tests the Google credentials and spreadsheet access (read-only)',
    'maintenance'  => 'Daily housekeeping: idempotency keys, sessions, temp files, old logs',
    'down'         => 'Maintenance mode on (every page answers 503)',
    'up'           => 'Maintenance mode off',
    'status'       => 'Shows version, database connection and Google Sheets queue',
];

$say = static fn (string $line) => Cli::write($line);

try {
    $code = match ($command) {
        'install'      => cmd_install($say),
        'migrate'      => Installer::migrate(db_connect(), $say) >= 0 ? 0 : 1,
        'seed'         => (static function () use ($say): int { Installer::seedReference(db_connect(), $say); return 0; })(),
        'seed:demo'    => (static function () use ($say): int { Installer::seedDemo(db_connect(), $say); return 0; })(),
        'create-admin' => cmd_create_admin(),
        'sheets:sync'  => cmd_sheets_sync(),
        'sheets:test'  => cmd_sheets_test(),
        'maintenance'  => cmd_maintenance(),
        'down'         => cmd_down(),
        'up'           => cmd_up(),
        'status'       => cmd_status(),
        default        => cmd_help($commands),
    };
} catch (Throwable $e) {
    Cli::error($e->getMessage());
    if (ENVIRONMENT !== 'production') {
        Cli::error($e::class . ' at ' . $e->getFile() . ':' . $e->getLine());
    }
    $code = 1;
}

exit($code);

// ============================================================================

function cmd_help(array $commands): int
{
    Cli::write('QMS command line tool', 'green');
    Cli::write('Usage: php bin/qms.php <command> [options]');
    Cli::write();
    foreach ($commands as $name => $text) {
        Cli::write(sprintf('  %-14s %s', $name, $text));
    }

    return 0;
}

function cmd_install(callable $say): int
{
    // 1. .env
    if (! is_file(ROOTPATH . '.env')) {
        Cli::write('No .env file yet - enter the connection settings.', 'yellow');
        $v = [
            'app_url' => Cli::option('app-url') ?? Cli::prompt('Site address (URL)', 'http://localhost:8080/'),
            'db_host' => Cli::option('db-host') ?? Cli::prompt('MySQL host', 'localhost'),
            'db_port' => Cli::option('db-port') ?? Cli::prompt('MySQL port', '3306'),
            'db_name' => Cli::option('db-name') ?? Cli::prompt('Database name', 'qms'),
            'db_user' => Cli::option('db-user') ?? Cli::prompt('MySQL user'),
        ];
        $password = Cli::option('db-pass') ?? (getenv('QMS_DB_PASSWORD') ?: Cli::secret('MySQL password'));
        if (! preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $v['db_name'])) {
            throw new RuntimeException('Invalid database name.');
        }
        Installer::writeEnv(Installer::envValues($v, (string) $password));
        App\Config\Services::reset(false);
        $say('.env written (mode 0600).');
    }

    // 2. database
    $db = db_connect();
    Installer::checkServer($db);
    $state = Installer::databaseState($db);
    if ($state === 'foreign') {
        throw new RuntimeException('The database contains tables of another application. Use an empty database.');
    }
    if ($state === 'installed') {
        Installer::migrate($db, $say);
        Installer::markInstalled();
        $say('QMS is already installed in this database; schema checked, storage/installed.lock written.');

        return 0;
    }
    if ($state === 'partial') {
        if (! Cli::flag('force') && ! Cli::confirm('The database holds tables of an unfinished installation (no users). Drop them and start again?')) {
            return 1;
        }
        Installer::dropPartial($db, $say);
    }
    Installer::migrate($db, $say);

    // 3. data
    $username  = Cli::option('admin', 'admin');
    $generated = Cli::flag('generate') || ! Cli::interactive();
    if (getenv('QMS_ADMIN_PASSWORD')) {
        $password  = (string) getenv('QMS_ADMIN_PASSWORD');
        $generated = true;
    } elseif ($generated) {
        $password = service('passwordPolicy')->generate(16);
    } else {
        $password = Cli::secret("Password for {$username}");
        if ($password !== Cli::secret('Repeat password')) {
            throw new RuntimeException('Passwords do not match.');
        }
    }

    $db->transBegin();
    try {
        Installer::seedReference($db, $say);
        Installer::createAdmin($db, ['username' => $username, 'password' => $password, 'email' => Cli::option('admin-email'), 'name' => Cli::option('admin-name')], $generated);
        if (Cli::flag('demo')) {
            Installer::seedDemo($db, $say);
        }
        Installer::applySettings($db, array_filter([
            'company.name'      => (string) Cli::option('company', ''),
            'regional.timezone' => in_array(Cli::option('timezone'), DateTimeZone::listIdentifiers(), true) ? (string) Cli::option('timezone') : '',
        ]));
        $db->transCommit();
    } catch (Throwable $e) {
        $db->transRollback();

        throw $e;
    }
    Installer::markInstalled();

    Cli::write("QMS installed. Administrator: {$username}", 'green');
    if (getenv('QMS_ADMIN_PASSWORD') === false && $generated) {
        Cli::write('One-time password (shown only now, must be changed at first login): ' . $password, 'yellow');
    }

    return 0;
}

function cmd_create_admin(): int
{
    $username = Cli::option('username', 'admin');
    $policy   = service('passwordPolicy');
    $mustChange = true;

    if (Cli::flag('generate')) {
        $password = $policy->generate(16);
    } elseif (Cli::flag('password-from-env')) {
        $password = (string) getenv('QMS_ADMIN_PASSWORD');
    } else {
        $password = Cli::secret("Password for {$username}");
        if ($password !== Cli::secret('Repeat password')) {
            Cli::error('Passwords do not match.');

            return 1;
        }
    }

    Installer::createAdmin(db_connect(), ['username' => $username, 'password' => $password, 'email' => Cli::option('email'), 'name' => Cli::option('name')], $mustChange);
    Cli::write("Super Admin '{$username}' created. The password must be changed at first login.", 'green');
    if (Cli::flag('generate')) {
        Cli::write('One-time password (shown only now): ' . $password, 'yellow');
    }

    return 0;
}

function cmd_sheets_sync(): int
{
    $limit = (int) (Cli::option('limit') ?? config('Qms')->syncBatchSize);
    $quiet = Cli::flag('quiet');
    service('audit')->setActor(null);

    $summary = service('sheetWorker')->run(max(1, min(500, $limit)), $quiet ? null : static function (string $line): void {
        Cli::write(gmdate('Y-m-d H:i:s') . ' ' . $line);
    });
    if ($summary['skipped'] !== null) {
        Cli::write('Skipped: ' . $summary['skipped'], 'yellow');

        return 0;
    }
    Cli::write(sprintf('%s processed=%d synced=%d failed=%d', gmdate('Y-m-d H:i:s'), $summary['processed'], $summary['synced'], $summary['failed']),
        $summary['failed'] > 0 ? 'yellow' : 'green');

    return 0;
}

function cmd_sheets_test(): int
{
    $client      = service('sheetsClient');
    $credentials = $client->describeCredentials();
    if (! $credentials['configured']) {
        Cli::error($credentials['hint']);

        return 1;
    }
    Cli::write('Service account: ' . $credentials['email'], 'green');

    $settings = service('settings');
    $ids      = array_filter(array_unique([trim($settings->string('google.spreadsheet_id')), trim($settings->string('google.detail_spreadsheet_id'))]));
    if ($ids === []) {
        Cli::error('No spreadsheet ID configured (Admin → Settings → Google Sheets).');

        return 1;
    }

    $ok = true;
    foreach ($ids as $id) {
        try {
            $tabs  = $client->sheets($id);
            $cells = array_sum(array_map(static fn (array $t): int => $t['rows'] * $t['cols'], $tabs));
            Cli::write(sprintf('%s: "%s", %d tab(s), %s of 10,000,000 cells allocated', $id, $client->spreadsheetTitle($id), count($tabs), number_format($cells)), 'green');
        } catch (Throwable $e) {
            $ok = false;
            Cli::error($id . ': ' . SheetSyncWorker::sanitize($e));
        }
    }
    Cli::write('Sync is ' . ($settings->bool('google.sync_enabled') ? 'ON' : 'OFF (switch it on in Settings)') . '.');

    return $ok ? 0 : 1;
}

function cmd_maintenance(): int
{
    $db     = db_connect();
    $config = config('Qms');

    Cli::write('Idempotency keys removed: ' . service('idempotency')->purge(max(24, $config->idempotencyRetentionHours)));

    $db->query('DELETE FROM ci_sessions WHERE timestamp < ?', [gmdate('Y-m-d H:i:s', time() - max(3600, config('Session')->expiration))]);
    Cli::write('Expired sessions removed: ' . $db->affectedRows());

    $removed = 0;
    $dir     = WRITEPATH . 'cache/mpdf';
    if (is_dir($dir)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isFile() && $item->getMTime() < time() - 86400 && @unlink($item->getPathname())) {
                $removed++;
            }
        }
    }
    Cli::write("PDF temp files removed: {$removed}");
    Cli::write('Login rate-limit buckets removed: ' . service('throttler')->prune());

    $days = max(7, (int) (Cli::option('log-days') ?? 30));
    $logs = 0;
    foreach (glob(WRITEPATH . 'logs/log-*.log') ?: [] as $file) {
        if (filemtime($file) < time() - $days * 86400 && @unlink($file)) {
            $logs++;
        }
    }
    Cli::write("Log files older than {$days} days removed: {$logs}");

    return 0;
}

function cmd_down(): int
{
    file_put_contents(WRITEPATH . 'maintenance.flag', gmdate('c') . "\n");
    Cli::write('Maintenance mode ON. Users see public/maintenance.html. Switch off with: php bin/qms.php up', 'yellow');

    return 0;
}

function cmd_up(): int
{
    @unlink(WRITEPATH . 'maintenance.flag');
    Cli::write('Maintenance mode OFF.', 'green');

    return 0;
}

function cmd_status(): int
{
    Cli::write('QMS ' . config('Qms')->version . ' (' . ENVIRONMENT . ')');
    Cli::write('URL:          ' . config('App')->baseURL);
    Cli::write('Installed:    ' . (is_file(WRITEPATH . 'installed.lock') ? 'yes' : 'no (open the site or run: php bin/qms.php install)'));
    Cli::write('Maintenance:  ' . (is_file(WRITEPATH . 'maintenance.flag') ? 'ON' : 'off'));

    try {
        $db = db_connect();
        Cli::write('MySQL:        ' . $db->getVersion() . ' / database ' . $db->getDatabase(), 'green');
        $migrations = $db->query('SELECT version FROM schema_migrations ORDER BY version')->getResultArray();
        Cli::write('Schema:       ' . implode(', ', array_column($migrations, 'version')));
        $summary = service('sheetQueue')->summary();
        Cli::write(sprintf('Sheets queue: pending=%d failed=%d synced=%d', $summary['PENDING_SYNC'] ?? 0, $summary['FAILED'] ?? 0, $summary['SYNCED'] ?? 0));
    } catch (Throwable $e) {
        Cli::error('Database:     ' . $e->getMessage());

        return 1;
    }

    return 0;
}

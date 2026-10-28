<?php

namespace App\Services\Admin;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Environment health checks for the Diagnostics screen and `dgr:diagnostics`.
 * Never returns secret values — only whether something is configured.
 *
 * Each check: [section, label, status ok|warn|fail, detail]
 */
class Diagnostics
{
    /** PHP extensions the application or its locked dependencies (composer.lock) need. */
    public const REQUIRED_EXTENSIONS = ['intl', 'mbstring', 'json', 'mysqli', 'curl', 'openssl', 'gd', 'zip', 'xml', 'dom', 'xmlwriter', 'fileinfo', 'iconv', 'ctype'];

    /** Folders under writable/ the web server must be able to write. */
    public const WRITABLE_DIRS = ['cache', 'logs', 'session', 'uploads', 'exports', 'backups', 'credentials', 'tmp'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> */
    public function run(): array
    {
        $out = [];
        $add = static function (string $section, string $label, string $status, string $detail) use (&$out): void {
            $out[] = [$section, $label, $status, $detail];
        };

        // PHP
        $add('PHP', 'PHP version', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fail', PHP_VERSION . ' (8.2 or newer required)');
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $add('PHP', "ext-{$ext}", extension_loaded($ext) ? 'ok' : 'fail', extension_loaded($ext) ? 'loaded' : 'missing — enable it in the hosting panel (PHP extensions)');
        }
        // sodium is only suggested by CodeIgniter and firebase/php-jwt; encryption here uses the OpenSSL driver
        $add('PHP', 'ext-sodium (optional)', 'ok', extension_loaded('sodium') ? 'loaded' : 'not loaded — not needed: DGR Office encrypts with OpenSSL');
        $add('PHP', 'memory_limit', $this->bytes((string) ini_get('memory_limit')) >= 256 * 1048576 || ini_get('memory_limit') === '-1' ? 'ok' : 'warn', (string) ini_get('memory_limit') . ' (256M+ recommended for PDF/XLSX)');
        $add('PHP', 'upload_max_filesize', (string) ini_get('upload_max_filesize'), (string) ini_get('upload_max_filesize'));
        $out[array_key_last($out)][2] = $this->bytes((string) ini_get('upload_max_filesize')) >= 8 * 1048576 ? 'ok' : 'warn';
        // gateway timeouts (504): how long PHP may run, and whether compiled code is cached
        $met = (int) ini_get('max_execution_time');
        $add('PHP', 'max_execution_time', $met === 0 || $met >= 60 ? 'ok' : 'warn', ($met === 0 ? 'no limit' : $met . ' s') . ' — the web server\'s own gateway timeout (often 60 s) applies as well; long jobs run from cron (php spark dgr:cron)');
        $opcache = extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
        $add('PHP', 'OPcache', $opcache ? 'ok' : 'warn', $opcache ? 'enabled' : 'not enabled for web requests — enable it in the hosting panel for much faster pages');
        $zlib = filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN) || (int) ini_get('zlib.output_compression') > 0;
        $add('PHP', 'zlib.output_compression', $zlib ? 'fail' : 'ok', $zlib ? 'on — CodeIgniter refuses to run with it; switch it off (the web server compresses pages itself)' : 'off');
        $slow = $this->slowRequestsToday();
        $add('PHP', 'Slow requests today', $slow === 0 ? 'ok' : 'warn', $slow === 0 ? 'none over ' . (int) env('app.slowRequestMs', 3000) . ' ms' : $slow . ' over ' . (int) env('app.slowRequestMs', 3000) . ' ms — see "SLOW REQUEST" lines in writable/logs/log-' . date('Y-m-d') . '.log');

        // Composer / vendor
        $vendorOk = is_file(ROOTPATH . 'vendor/autoload.php');
        $add('Dependencies', 'vendor/autoload.php', $vendorOk ? 'ok' : 'fail', $vendorOk ? 'present' : 'missing — run composer install --no-dev or upload vendor/');
        foreach ([
            'CodeIgniter Shield'  => \CodeIgniter\Shield\Auth::class,
            'PhpSpreadsheet'      => \PhpOffice\PhpSpreadsheet\Spreadsheet::class,
            'Dompdf'              => \Dompdf\Dompdf::class,
            'Google Sheets API'   => \Google\Service\Sheets::class,
            'PDF parser'          => \Smalot\PdfParser\Parser::class,
            'brick/math'          => \Brick\Math\BigDecimal::class,
            'Anthropic SDK'       => \Anthropic\Client::class,
        ] as $label => $class) {
            $add('Dependencies', $label, class_exists($class) ? 'ok' : 'fail', class_exists($class) ? 'autoloads' : 'class not found');
        }

        // Database
        try {
            $ver  = (string) ($this->db->query('SELECT VERSION() v')->getRow()->v ?? '');
            $mode = (string) ($this->db->query('SELECT @@sql_mode m')->getRow()->m ?? '');
            $isMaria = stripos($ver, 'mariadb') !== false;
            $add('Database', 'Connection', 'ok', $this->db->getDatabase() . ' @ ' . $this->db->hostname);
            $num  = preg_match('/^\d+(\.\d+)+/', $ver, $m) ? $m[0] : '0';
            $okDb = $isMaria ? version_compare($num, '10.6', '>=') : version_compare($num, '8.0.16', '>=');
            $add('Database', 'Server version', $okDb ? 'ok' : 'warn', $ver . ($isMaria
                ? ($okDb ? ' — supported (full test suite passes on MariaDB 11.8)' : ' — MariaDB 10.6 or newer required')
                : ($okDb ? '' : ' — MySQL 8.0.16 or newer required')));
            $add('Database', 'Strict SQL mode', str_contains($mode, 'STRICT_TRANS_TABLES') ? 'ok' : 'warn', $mode ?: '(empty)');
            $charset = (string) ($this->db->query('SELECT DEFAULT_CHARACTER_SET_NAME c FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->getRow()->c ?? '');
            $add('Database', 'Charset', $charset === 'utf8mb4' ? 'ok' : 'warn', $charset);
        } catch (Throwable $e) {
            $add('Database', 'Connection', 'fail', 'Cannot connect: ' . $e->getMessage());
        }

        // Migrations
        try {
            $files = [];
            foreach ([APPPATH . 'Database/Migrations/*.php', ROOTPATH . 'vendor/codeigniter4/shield/src/Database/Migrations/*.php', ROOTPATH . 'vendor/codeigniter4/settings/src/Database/Migrations/*.php'] as $g) {
                foreach (glob($g) ?: [] as $f) {
                    $files[] = substr(basename($f), 0, 17);
                }
            }
            $ran     = array_column($this->db->table('migrations')->select('version')->get()->getResultArray(), 'version');
            $pending = array_diff($files, $ran);
            $add('Database', 'Migrations', $pending === [] ? 'ok' : 'fail', $pending === [] ? count($ran) . ' applied, none pending' : count($pending) . ' pending — run php spark migrate --all');
        } catch (Throwable $e) {
            $add('Database', 'Migrations', 'fail', 'migrations table unavailable');
        }

        // Filesystem
        foreach (self::WRITABLE_DIRS as $dir) {
            $path = WRITEPATH . $dir;
            $ok   = is_dir($path) && is_writable($path);
            $add('Filesystem', "writable/{$dir}", $ok ? 'ok' : 'fail', $ok ? 'writable' : 'not writable — chmod/chown for the web server user');
        }
        $uploadsPublic = str_starts_with(realpath(WRITEPATH) ?: '', realpath(FCPATH) ?: '#');
        // whole folder inside the web root (e.g. public_html/dgr), served through the root .htaccess: only the .htaccess files protect it
        $request     = service('request');
        $throughRoot = ! is_cli() && str_ends_with((string) $request->getServer('SCRIPT_NAME'), '/public/index.php')
            && ! str_contains((string) parse_url('http://x' . $request->getServer('REQUEST_URI'), PHP_URL_PATH), '/public/');
        $guarded = is_file(ROOTPATH . '.htaccess') && is_file(WRITEPATH . '.htaccess');
        $add('Filesystem', 'Private files outside web root', $uploadsPublic || ($throughRoot && ! $guarded) ? 'fail' : 'ok', match (true) {
            $uploadsPublic => 'writable/ is inside public/ — move it out of the web root',
            $throughRoot   => $guarded ? 'the whole app folder is inside the web root and served through its root .htaccess, which blocks .env, app/, writable/ and vendor/ — keep both .htaccess files'
                : 'the app folder is inside the web root but .htaccess or writable/.htaccess is missing — .env and uploads may be downloadable; restore them from the release zip',
            default        => 'writable/ is outside public/',
        });
        // files the database lists but the disk no longer has (folder replaced on upgrade, uploads not restored, demo SQL from another install)
        try {
            $files   = service('files');
            $missing = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $f): bool => ! $files->isOnDisk($f)));
            $brand   = $missing($this->db->table('branding_assets b')->select('b.asset_type, f.id, f.disk_path')->join('stored_files f', 'f.id = b.stored_file_id')
                ->where('b.is_active', 1)->get()->getResultArray());
            $add('Filesystem', 'Active logo/signature/stamp files', $brand === [] ? 'ok' : 'fail', $brand === [] ? 'present (or none uploaded)'
                : 'missing on disk: ' . implode(', ', array_unique(array_column($brand, 'asset_type'))) . ' — documents print without them. Upload again in Settings › Branding Assets, or restore writable/uploads from the files backup');
            $all  = $this->db->table('stored_files')->select('id, disk_path')->where('deleted_at', null)->get()->getResultArray();
            $lost = count($missing($all));
            $add('Filesystem', 'Uploaded and generated files', $lost === 0 ? 'ok' : 'warn', $lost === 0 ? count($all) . ' file(s), all present'
                : "{$lost} of " . count($all) . ' missing on disk (they open as "not found") — restore writable/uploads from the files backup (dgr-…-files.zip)');
        } catch (Throwable) {
            // database checks above already report a connection problem
        }

        // Cron
        $last = $this->db->table('cron_runs')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        if ($last === null) {
            $add('Scheduler', 'Cron (php spark dgr:cron)', 'warn', 'Never ran — add the cron line from docs/04-DEPLOYMENT.md (reminders, Sheets sync, recurring drafts depend on it)');
        } else {
            $age = time() - strtotime($last['started_at']);
            $add('Scheduler', 'Cron last run', $age < 1200 && $last['status'] !== 'error' ? 'ok' : 'warn', dmy($last['started_at'], true) . ' (' . $last['status'] . ($last['message'] ? ': ' . mb_strimwidth($last['message'], 0, 80, '…') : '') . ')');
        }

        // Integrations
        $gs = $this->db->table('google_sync_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $credPath = (string) ($gs['credentials_path'] ?? '');
        if (empty($gs['enabled'])) {
            $add('Integrations', 'Google Sheets sync', 'warn', 'Not configured / disabled — export-only mode');
        } else {
            $credOk = $credPath !== '' && is_readable($credPath);
            $perm   = $credOk ? substr(sprintf('%o', fileperms($credPath)), -3) : '';
            $add('Integrations', 'Google Sheets credentials', $credOk ? ($perm[2] !== '0' ? 'warn' : 'ok') : 'fail', $credOk ? "readable (mode {$perm}" . ($perm[2] !== '0' ? ' — remove world access: chmod 600' : '') . ')' : 'credentials file missing/unreadable');
            $add('Integrations', 'Google Sheets last sync', ! empty($gs['last_error']) && (empty($gs['last_success_at']) || $gs['last_error_at'] > $gs['last_success_at']) ? 'warn' : 'ok', 'Last success: ' . dmy($gs['last_success_at'] ?? null, true) . (! empty($gs['last_error']) ? ' · last error: ' . mb_strimwidth((string) $gs['last_error'], 0, 80, '…') : ''));
        }
        $ai = service('aiConfig');
        $add('Integrations', 'AI provider', $ai->isConfigured() ? 'ok' : 'warn', $ai->isConfigured() ? $ai->provider() . ' / ' . $ai->model() . ' (key stored, not shown)' : 'Not configured — recruitment works with manual HR assessment and the keyword check');
        $enabledCaps = $this->db->table('ai_capabilities')->where('enabled', 1)->countAllResults();
        $add('Integrations', 'AI capabilities enabled', 'ok', $enabledCaps . ' of ' . $this->db->table('ai_capabilities')->countAllResults());
        $mail = \App\Services\Notify\Notifier::mailConfigured();
        $add('Integrations', 'SMTP email', $mail ? 'ok' : 'warn', $mail ? 'configured' : 'Not configured — reminders stay in-app only');

        // Backups
        $backups = glob(WRITEPATH . 'backups/*.{sql.gz,zip}', GLOB_BRACE) ?: [];
        usort($backups, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $latest = $backups[0] ?? null;
        $add('Backups', 'Latest backup', $latest && filemtime($latest) > time() - 2 * 86400 ? 'ok' : 'warn', $latest ? basename($latest) . ' · ' . date('d-m-Y H:i', (int) filemtime($latest)) : 'No backup found — run php spark dgr:backup (schedule daily)');

        // Security
        $env = ENVIRONMENT;
        $add('Security', 'Environment', $env === 'production' ? 'ok' : 'warn', $env . ($env !== 'production' ? ' — set CI_ENVIRONMENT = production on live servers' : ''));
        $https = str_starts_with(config('App')->baseURL, 'https://');
        $force = config('App')->forceGlobalSecureRequests;
        $add('Security', 'Force HTTPS', $force && $https ? 'ok' : 'warn', match (true) {
            $force && $https => 'on',
            $force           => 'on, but app.baseURL starts with http:// — change it to https://',
            $https           => 'off — set app.forceGlobalSecureRequests = true and cookie.secure = true in .env (SSL is in use)',
            default          => 'off — install SSL, then use an https:// app.baseURL and set app.forceGlobalSecureRequests = true',
        });
        $key = (string) config('Encryption')->key;
        $add('Security', 'Encryption key', $key === '' ? 'fail' : (strlen($key) >= 32 ? 'ok' : 'warn'), match (true) {
            $key === ''        => 'missing or unreadable — bank numbers and passwords cannot be saved. Run php spark key:generate once in the application folder and back up .env',
            strlen($key) < 32 => 'set but shorter than 32 bytes — keep it (existing data depends on it); use key:generate only on a new install',
            default            => 'set (not shown) — keep a safe copy of .env; changing it makes saved bank numbers unreadable',
        });
        $base = config('App')->baseURL;
        $placeholder = str_contains($base, 'example.com') || str_starts_with($base, 'http://localhost:8080');
        $add('Security', 'Site address (app.baseURL)', $placeholder ? ($env === 'production' ? 'fail' : 'warn') : 'ok', $placeholder
            ? "{$base} is a placeholder — set app.baseURL in .env to the address users open, with a trailing slash (e.g. https://your-domain/dgr/)"
            : $base);
        if (($miss = base_url_mismatch()) !== null) {
            $add('Security', 'Site address matches this page', 'fail', "this page was opened at {$miss['detected']} but app.baseURL is {$miss['configured']} — set app.baseURL = '{$miss['detected']}' in .env");
        }
        $supers = service('users')->activeSuperAdminCount();
        $add('Security', 'Active Owner/Super Admins', $supers >= 1 ? ($supers > 3 ? 'warn' : 'ok') : 'fail', (string) $supers . ($supers > 3 ? ' — keep full-access accounts to a minimum' : ''));
        $demo = $this->db->table('auth_identities')->like('secret', '@demo.dgr.local', 'before')->countAllResults();
        $add('Security', 'Demo accounts', $demo === 0 ? 'ok' : ($env === 'production' ? 'fail' : 'warn'), $demo === 0 ? 'none' : "{$demo} demo account(s) present — remove before go-live (php spark dgr:demo --purge)");
        $add('Security', 'Email 2FA', config('Auth')->actions['login'] !== null ? 'ok' : 'warn', config('Auth')->actions['login'] !== null ? 'enabled' : 'optional — enable auth.email2fa once SMTP works');
        $company = $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $add('Billing readiness', 'Legal identity entered', ! empty($company['legal_name']) && ! empty($company['state_code']) ? 'ok' : 'warn', ! empty($company['legal_name']) ? (string) $company['legal_name'] : 'Complete the Billing Setup Wizard before issuing real tax documents');
        $add('Billing readiness', 'Tax rates reviewed by accountant', $this->db->table('tax_rates')->where('review_status', 'accountant_reviewed')->countAllResults() > 0 ? 'ok' : 'warn', 'Seeded rates are suggestions until marked reviewed in Settings › Tax');

        return $out;
    }

    /** "SLOW REQUEST" lines in today's log (written by app/Filters/SlowRequestFilter.php). */
    private function slowRequestsToday(): int
    {
        $log = rtrim(WRITEPATH, '/\\') . '/logs/log-' . date('Y-m-d') . '.log';
        if (! is_file($log) || filesize($log) > 50 * 1048576) {
            return 0;
        }
        $n  = 0;
        $fh = fopen($log, 'rb');
        while ($fh !== false && ($line = fgets($fh)) !== false) {
            $n += str_contains($line, 'SLOW REQUEST') ? 1 : 0;
        }
        if ($fh !== false) {
            fclose($fh);
        }

        return $n;
    }

    private function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}

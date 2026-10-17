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
        $add('PHP', 'PHP version', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : (version_compare(PHP_VERSION, '8.2.0', '>=') ? 'warn' : 'fail'), PHP_VERSION . ' (8.3+ recommended)');
        foreach (['intl', 'mbstring', 'json', 'mysqli', 'curl', 'openssl', 'gd', 'zip', 'xml', 'dom', 'xmlwriter', 'fileinfo', 'iconv', 'ctype', 'sodium'] as $ext) {
            $add('PHP', "ext-{$ext}", extension_loaded($ext) ? 'ok' : 'fail', extension_loaded($ext) ? 'loaded' : 'missing — install/enable in PHP');
        }
        $add('PHP', 'memory_limit', $this->bytes((string) ini_get('memory_limit')) >= 256 * 1048576 || ini_get('memory_limit') === '-1' ? 'ok' : 'warn', (string) ini_get('memory_limit') . ' (256M+ recommended for PDF/XLSX)');
        $add('PHP', 'upload_max_filesize', (string) ini_get('upload_max_filesize'), (string) ini_get('upload_max_filesize'));
        $out[array_key_last($out)][2] = $this->bytes((string) ini_get('upload_max_filesize')) >= 8 * 1048576 ? 'ok' : 'warn';

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
            $add('Database', 'Server version', ! $isMaria && version_compare($ver, '8.0.16', '>=') ? 'ok' : 'warn', $ver . ($isMaria ? ' (MariaDB: CHECK constraints/JSON behave differently — MySQL 8 is the supported target)' : ''));
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
        foreach (['cache', 'logs', 'session', 'uploads', 'exports', 'backups', 'credentials', 'tmp'] as $dir) {
            $path = WRITEPATH . $dir;
            $ok   = is_dir($path) && is_writable($path);
            $add('Filesystem', "writable/{$dir}", $ok ? 'ok' : 'fail', $ok ? 'writable' : 'not writable — chmod/chown for the web server user');
        }
        $uploadsPublic = str_starts_with(realpath(WRITEPATH) ?: '', realpath(FCPATH) ?: '#');
        $add('Filesystem', 'Private files outside web root', $uploadsPublic ? 'fail' : 'ok', $uploadsPublic ? 'writable/ is inside public web root!' : 'writable/ is outside public/');

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
        $add('Security', 'Force HTTPS', config('App')->forceGlobalSecureRequests ? 'ok' : 'warn', config('App')->forceGlobalSecureRequests ? 'on' : 'off — enable app.forceGlobalSecureRequests after installing SSL');
        $key = (string) config('Encryption')->key;
        $add('Security', 'Encryption key', strlen($key) >= 32 ? 'ok' : 'fail', strlen($key) >= 32 ? 'set (not shown)' : 'missing — run php spark key:generate and back it up');
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

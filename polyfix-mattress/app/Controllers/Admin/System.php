<?php

namespace App\Controllers\Admin;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Settings;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * System health and the settings an administrator can change without a deploy.
 *
 * Health shows only what an operator needs to act on. It never prints
 * credentials, connection strings or file system paths.
 */
class System extends AdminController
{
    public function index(): string
    {
        $db     = db_connect();
        $checks = [];

        try {
            $started = microtime(true);
            $version = $db->query('SELECT VERSION() v')->getRow()->v;
            $checks[] = ['Database', 'ok', 'MySQL ' . preg_replace('/-.*/', '', (string) $version) . ', answering in ' . round((microtime(true) - $started) * 1000) . ' ms'];
        } catch (\Throwable) {
            $checks[] = ['Database', 'fail', 'Not reachable.'];
        }

        try {
            $applied = $db->table('migrations')->countAllResults();
            $latest  = $db->table('migrations')->selectMax('version', 'v')->get()->getRow()->v;
            $checks[] = ['Migrations', 'ok', $applied . ' applied, latest ' . $latest];
        } catch (\Throwable) {
            // The application's database user is not granted the migration
            // table on purpose — schema changes are the owner account's job.
            $checks[] = ['Migrations', 'ok', 'Not readable by the application account, as intended. Check with the owner account: php spark migrate:status.'];
        }

        // The application user must not be able to change history.
        $grants = array_map(static fn (array $row): string => (string) current($row), $db->query('SHOW GRANTS FOR CURRENT_USER()')->getResultArray());
        $writable = [];
        foreach (['audit_logs', 'record_versions', 'mattress_events', 'claim_events'] as $table) {
            foreach ($grants as $grant) {
                [$privileges] = explode(' ON ', (string) $grant, 2) + [''];
                if (str_contains((string) $grant, '`' . $table . '`') && preg_match('/\b(UPDATE|DELETE|ALL PRIVILEGES)\b/', $privileges)) {
                    $writable[] = $table;
                }
            }
        }
        $checks[] = $writable === []
            ? ['Append-only history', 'ok', 'The application cannot update or delete audit rows.']
            : ['Append-only history', 'fail', 'The application can modify: ' . implode(', ', array_unique($writable)) . '. Re-apply the privilege script.'];

        $uploads = config('Polyfix')->uploadDirectory();
        $checks[] = is_writable($uploads)
            ? ['Upload storage', 'ok', 'Writable, outside the web root.']
            : ['Upload storage', 'fail', 'Not writable.'];
        $checks[] = is_writable(WRITEPATH . 'logs')
            ? ['Log storage', 'ok', 'Writable.']
            : ['Log storage', 'fail', 'Not writable.'];

        $problems = config('Polyfix')->problems();
        $checks[] = $problems === []
            ? ['Configuration', 'ok', 'Encryption key, signing secret and base URL are set.']
            : ['Configuration', 'fail', implode(' ', $problems)];

        $checks[] = ENVIRONMENT === 'production'
            ? ['Environment', 'ok', 'production — errors are hidden from visitors.']
            : ['Environment', 'warn', ENVIRONMENT . ' — detailed errors are shown. Never run a live site this way.'];

        $counts = [];
        foreach (['mattresses', 'dealers', 'sales', 'warranties', 'warranty_claims', 'users', 'audit_logs'] as $table) {
            $counts[$table] = (int) $db->table($table)->countAllResults();
        }

        return $this->render('admin/system/index', 'System', 'admin/system', [
            'checks'  => $checks,
            'counts'  => $counts,
            'php'     => PHP_VERSION,
            'ci'      => \CodeIgniter\CodeIgniter::CI_VERSION,
            'chain'   => $db->table('audit_chain_head')->get()->getRowArray(),
            'storage' => $this->storageUsage($uploads),
        ]);
    }

    public function settings(): string
    {
        $rows = db_connect()->table('system_settings')->select('key, value, description, category, updated_at')
            ->where('is_secret', 0)->orderBy('category')->orderBy('key')->get()->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['category'] ?: 'general'][] = $row;
        }

        return $this->render('admin/system/settings', 'Settings', 'admin/settings', ['groups' => $grouped]);
    }

    public function saveSetting(string $key): RedirectResponse
    {
        $raw = (string) $this->request->getPost('value');

        return $this->act(function () use ($key, $raw): void {
            $db      = db_connect();
            $setting = $db->table('system_settings')->where('key', $key)->get()->getRowArray()
                ?? throw AppException::notFound('setting');
            if ($setting['is_secret']) {
                // A secret is never shown or set here; it belongs in the environment.
                throw AppException::forbidden('That setting is held outside the application.');
            }

            // Values are JSON. A boolean or number stays typed; anything else is a string.
            $value = match (true) {
                $raw === 'true', $raw === 'false' => $raw,
                is_numeric($raw)                  => $raw,
                default                           => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };

            $db->table('system_settings')->where('key', $key)->update([
                'value' => $value, 'updated_by' => $this->ctx->userId(), 'updated_at' => utc_now(),
            ]);
            Settings::forget();
            Audit::instance()->record('SETTING_CHANGED', 'system_setting', $key,
                ['value' => $setting['value']], ['value' => $value]);
        }, 'Setting saved.', site_url('admin/settings'));
    }

    /** @return array{files:int, bytes:int} */
    private function storageUsage(string $directory): array
    {
        $files = 0;
        $bytes = 0;
        if (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files++;
                    $bytes += $file->getSize();
                }
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }
}

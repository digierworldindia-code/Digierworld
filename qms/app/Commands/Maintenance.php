<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Qms;

/**
 * Daily housekeeping (cron 02:15):
 * - purge idempotency keys older than Config\Qms::$idempotencyRetentionHours
 * - remove expired database sessions
 * - remove PDF temp files older than a day
 * Audit, login and approval history are never purged here.
 */
class Maintenance extends BaseCommand
{
    protected $group       = 'QMS';
    protected $name        = 'qms:maintenance';
    protected $description = 'Daily housekeeping: idempotency keys, expired sessions, PDF temp files.';

    public function run(array $params)
    {
        $db     = db_connect();
        $config = config(Qms::class);

        $keys = service('idempotency')->purge(max(24, $config->idempotencyRetentionHours));
        CLI::write("Idempotency keys removed: {$keys}");

        $lifetime = max(3600, (int) config('Session')->expiration ?: 7200);
        $db->table('ci_sessions')->where('timestamp <', gmdate('Y-m-d H:i:s', time() - $lifetime))->delete();
        CLI::write('Expired sessions removed: ' . $db->affectedRows());

        $removed = 0;
        $dir     = WRITEPATH . 'cache/mpdf';
        if (is_dir($dir)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                if ($item->isFile() && $item->getMTime() < time() - 86400) {
                    @unlink($item->getPathname());
                    $removed++;
                }
            }
        }
        CLI::write("PDF temp files removed: {$removed}");

        return EXIT_SUCCESS;
    }
}

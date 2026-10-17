<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * php spark dgr:cron — run from the system crontab every minute:
 *   * * * * * cd /path/to/erp && php spark dgr:cron >> writable/logs/cron.log 2>&1
 *
 * No long-running daemon is needed. Each task records a row in cron_runs
 * (shown in Admin → Diagnostics) and a lock prevents overlapping runs.
 */
class CronCommand extends BaseCommand
{
    protected $group       = 'DGR Office';
    protected $name        = 'dgr:cron';
    protected $description = 'Run scheduled jobs: reminders, Google Sheets sync, recurring retainer drafts, HR retention and incentive proposals.';
    protected $usage       = 'dgr:cron [--only reminders|sheets|recurring|hr]';

    public function run(array $params)
    {
        $lockFile = WRITEPATH . 'cron.lock';
        $lock     = fopen($lockFile, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            CLI::write('Another cron run is still active; skipping.', 'yellow');

            return EXIT_SUCCESS;
        }
        $only = (string) (CLI::getOption('only') ?? '');
        $db   = db_connect();
        $authz = service('authz');
        $authz->actAs(null);

        $tasks = [
            'reminders' => static fn () => service('notifier') !== null ? (new \App\Services\Notify\ReminderService($db, service('notifier')))->run() : [],
            'sheets'    => static function () use ($db) {
                $svc = service('sheetsSync');
                $s   = $svc->settings();
                if (empty($s['enabled'])) {
                    return ['skipped' => 'sync not enabled'];
                }
                $last = $s['last_run_at'] ?? null;
                if ($last !== null && strtotime($last) > time() - ((int) ($s['frequency_minutes'] ?? 5) * 60) + 20) {
                    return ['skipped' => 'not due yet'];
                }

                return $svc->process(300, 'cron');
            },
            'recurring' => static function () use ($db) {
                // once a day is enough; generate drafts for retainers that are due
                $ran = $db->table('cron_runs')->where('task', 'recurring')->where('status', 'ok')->where('started_at >=', date('Y-m-d 00:00:00'))->countAllResults();

                return $ran > 0 ? ['skipped' => 'already ran today'] : (new \App\Services\Billing\RecurringBillingService($db))->runDue();
            },
            'hr' => static function () use ($db) {
                // once a day: purge applicant data past retention, propose this month's incentives
                $ran = $db->table('cron_runs')->where('task', 'hr')->where('status', 'ok')->where('started_at >=', date('Y-m-d 00:00:00'))->countAllResults();
                if ($ran > 0) {
                    return ['skipped' => 'already ran today'];
                }

                return ['purged_applicants' => service('recruitment')->purgeExpired(), 'incentives' => service('incentives')->generate(date('Y-m'))];
            },
        ];

        $exit = EXIT_SUCCESS;
        foreach ($tasks as $name => $fn) {
            if ($only !== '' && $only !== $name) {
                continue;
            }
            $db->table('cron_runs')->insert(['task' => $name, 'started_at' => date('Y-m-d H:i:s'), 'status' => 'running']);
            $runId = (int) $db->insertID();
            try {
                $res = $fn();
                $msg = json_encode($res);
                $db->table('cron_runs')->where('id', $runId)->update(['finished_at' => date('Y-m-d H:i:s'), 'status' => 'ok', 'message' => mb_substr((string) $msg, 0, 1000)]);
                CLI::write(str_pad($name, 10) . ' ' . $msg, 'green');
            } catch (Throwable $e) {
                $exit = EXIT_ERROR;
                $db->table('cron_runs')->where('id', $runId)->update(['finished_at' => date('Y-m-d H:i:s'), 'status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 1000)]);
                log_message('error', 'Cron task ' . $name . ' failed: ' . $e->getMessage());
                CLI::error(str_pad($name, 10) . ' FAILED: ' . $e->getMessage());
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);

        return $exit;
    }
}

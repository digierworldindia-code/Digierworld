<?php
/**
 * Command-line helper for the administrator (never reachable from the web).
 *
 *   php cron/tool.php status                    version, database and Google Sheets queue
 *   php cron/tool.php sheets-test               checks the Google key and spreadsheet access
 *   php cron/tool.php down | up                 maintenance mode on / off (users see "being updated")
 *   php cron/tool.php unlock <username>         removes a login lockout
 *   php cron/tool.php reset-password <username> new one-time password (must be changed at next login)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/init.php';
require_once QMS_ROOT . '/includes/sheets_worker.php';

$command = $argv[1] ?? 'help';
$arg     = $argv[2] ?? '';
$flag    = QMS_ROOT . '/storage/maintenance.flag';

try {
    switch ($command) {
        case 'status':
            echo 'QMS ' . QMS_VERSION . ' (' . config('environment', 'production') . ")\n";
            echo 'URL:          ' . base_url() . "\n";
            echo 'Maintenance:  ' . (is_file($flag) ? 'ON' : 'off') . "\n";
            echo 'MySQL:        ' . db_value('SELECT VERSION()') . ' / database ' . db_value('SELECT DATABASE()') . "\n";
            $s = sheet_summary();
            printf("Sheets queue: pending=%d processing=%d failed=%d synced=%d\n", $s['PENDING_SYNC'], $s['PROCESSING'], $s['FAILED'], $s['SYNCED']);
            break;

        case 'sheets-test':
            $credentials = gs_credentials();
            if (! $credentials['configured']) {
                fwrite(STDERR, $credentials['hint'] . "\n");
                exit(1);
            }
            echo 'Service account: ' . $credentials['email'] . "\n";
            $ids = array_filter(array_unique([trim(setting_str('google.spreadsheet_id')), trim(setting_str('google.detail_spreadsheet_id'))]));
            if ($ids === []) {
                fwrite(STDERR, "No spreadsheet ID configured (Administration → Settings → Google Sheets).\n");
                exit(1);
            }
            $ok = true;
            foreach ($ids as $id) {
                try {
                    $tabs  = gs_tabs($id);
                    $cells = array_sum(array_map(static fn (array $t): int => $t['rows'] * $t['cols'], $tabs));
                    printf("%s: \"%s\", %d tab(s), %s of 10,000,000 cells allocated\n", $id, gs_spreadsheet_title($id), count($tabs), number_format($cells));
                } catch (Throwable $e) {
                    $ok = false;
                    fwrite(STDERR, $id . ': ' . gs_sanitize($e) . "\n");
                }
            }
            echo 'Sync is ' . (setting_bool('google.sync_enabled') ? 'ON' : 'OFF (switch it on in Settings)') . ".\n";
            exit($ok ? 0 : 1);

        case 'down':
            file_put_contents($flag, gmdate('c') . "\n");
            echo "Maintenance mode ON. Switch it off with: php cron/tool.php up\n";
            break;

        case 'up':
            @unlink($flag);
            echo "Maintenance mode OFF.\n";
            break;

        case 'unlock':
            $user = db_row('SELECT * FROM users WHERE username = ?', [$arg]) ?? throw new RuntimeException("User '{$arg}' not found.");
            db_update('users', ['failed_login_count' => 0, 'locked_until' => null, 'updated_at' => now_str()], 'id = ?', [$user['id']]);
            audit_log('UNLOCK', 'user', (int) $user['id'], ['locked_until' => $user['locked_until']], ['locked_until' => null], (string) $user['username']);
            login_log((int) $user['id'], (string) $user['username'], 'ACCOUNT_UNLOCKED', 'by command line');
            echo "Account '{$arg}' unlocked.\n";
            break;

        case 'reset-password':
            $user = db_row('SELECT * FROM users WHERE username = ?', [$arg]) ?? throw new RuntimeException("User '{$arg}' not found.");
            $password = password_generate(16);
            auth_set_password($user, $password, true);
            login_log((int) $user['id'], (string) $user['username'], 'PASSWORD_RESET', 'by command line');
            echo "One-time password for '{$arg}' (shown only now, must be changed at the next login): {$password}\n";
            break;

        default:
            echo "Commands: status | sheets-test | down | up | unlock <username> | reset-password <username>\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

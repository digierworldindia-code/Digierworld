<?php
/**
 * Google Sheets outbox and worker against a fake Google server (no network).
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/sheets_worker.php';

/** In-memory Google: token endpoint and the few Sheets API calls the worker uses. */
function t_fake_google(array &$state): callable
{
    return static function (string $method, string $url, array $headers, ?string $body) use (&$state): array {
        $state['calls'][] = $method . ' ' . $url;
        if (str_contains($url, 'oauth2')) {
            parse_str((string) $body, $form);
            $parts = explode('.', (string) ($form['assertion'] ?? ''));
            $ok    = count($parts) === 3 && openssl_verify($parts[0] . '.' . $parts[1], base64_decode(strtr($parts[2], '-_', '+/')), $state['public'], OPENSSL_ALGO_SHA256) === 1;

            return $ok ? [200, '{"access_token":"ya29.fake-token","expires_in":3600}'] : [400, '{"error":"invalid_grant"}'];
        }
        if (! in_array('Authorization: Bearer ya29.fake-token', $headers, true)) {
            return [401, '{"error":{"code":401,"status":"UNAUTHENTICATED","message":"no token"}}'];
        }
        if ($state['fail'] > 0) {
            $state['fail']--;

            return [503, '{"error":{"code":503,"status":"UNAVAILABLE","message":"Backend error"}}'];
        }
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $data = $body === null ? [] : json_decode($body, true);
        if ($method === 'GET' && str_contains((string) parse_url($url, PHP_URL_QUERY), 'sheets.properties')) {
            $sheets = [];
            foreach ($state['tabs'] as $title => $rows) {
                $sheets[] = ['properties' => ['title' => $title, 'sheetId' => crc32($title), 'gridProperties' => ['rowCount' => $state['rowCount'], 'columnCount' => 30]]];
            }

            return [200, json_encode(['sheets' => $sheets])];
        }
        if (str_ends_with($path, ':batchUpdate')) {
            $request = $data['requests'][0];
            if (isset($request['addSheet'])) {
                $state['tabs'][$request['addSheet']['properties']['title']] = [];

                return [200, json_encode(['replies' => [['addSheet' => ['properties' => ['sheetId' => 7]]]]])];
            }

            return [200, '{}'];
        }
        if (! preg_match("#/values/'(.+)'!([A-Z]+)(\d*)(?::[A-Z]+)?(:append)?$#", $path, $m)) {
            return [404, '{"error":{"code":404,"message":"unknown ' . $path . '"}}'];
        }
        [$tab, $col, $row, $append] = [$m[1], $m[2], $m[3], $m[4] ?? ''];
        if ($method === 'GET') {
            return [200, json_encode(['values' => [array_map(static fn (array $r) => (string) $r[0], $state['tabs'][$tab])]])];
        }
        if ($append !== '') {
            $start = count($state['tabs'][$tab]) + 1;
            foreach ($data['values'] as $values) {
                $state['tabs'][$tab][] = $values;
            }

            return [200, json_encode(['updates' => ['updatedRange' => "{$tab}!A{$start}"]])];
        }
        $state['tabs'][$tab][(int) $row - 1] = $data['values'][0];

        return [200, json_encode(['updatedRange' => "{$tab}!A{$row}"])];
    };
}

function test_sheets_outbox_and_worker(): void
{
    // Key file outside the web folders, like a real installation.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $file = sys_get_temp_dir() . '/qms-test-sa-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($file, json_encode(['type' => 'service_account', 'client_email' => 'qms@test.iam.gserviceaccount.com', 'private_key' => $pem,
        'token_uri' => 'https://oauth2.googleapis.com/token']));
    $GLOBALS['qms_config']['google_credentials_file'] = $file;
    $state = ['tabs' => [], 'calls' => [], 'fail' => 0, 'rowCount' => 1000, 'public' => openssl_pkey_get_details($key)['key']];
    gs_transport(t_fake_google($state));

    try {
        $admin = t_login('admin');
        db_update('system_settings', ['setting_value' => '1'], 'setting_key = ?', ['google.sync_enabled']);
        db_update('system_settings', ['setting_value' => 'TestSpreadsheet0123456789'], 'setting_key = ?', ['google.spreadsheet_id']);
        settings_refresh();
        t_same(true, gs_credentials()['configured']);

        // Queue: every final report numbered so far.
        $count = sheet_backfill('2000-01-01', '2099-12-31');
        t_ok($count >= 1, 'reports queued');
        $summary = sheet_worker_run(100, null, false);
        $paging = paging(10);
        t_same(0, $summary['failed'], 'no failures: ' . json_encode(sheet_jobs(['status' => 'FAILED'], $paging)));
        t_ok(isset($state['tabs']['Reports']) && $state['tabs']['Reports'][0][0] === 'Sync Key', 'Reports tab created with headers');
        $rows = count($state['tabs']['Reports']);
        t_ok($rows >= 2, 'report rows appended');
        t_same(0, (int) db_value("SELECT COUNT(*) FROM google_sheet_sync_logs WHERE sync_status <> 'SYNCED'"), 'all jobs synced');
        $obsTab = array_values(array_filter(array_keys($state['tabs']), static fn (string $t): bool => str_starts_with($t, 'Observations_')));
        t_ok($obsTab !== [] && count($state['tabs'][$obsTab[0]]) > 1, 'observation rows of approved reports appended');

        // A status change updates the same row (de-duplicated by the sync key).
        $report = db_row("SELECT * FROM inspection_reports WHERE report_no IS NOT NULL AND status = 'SUPERSEDED' LIMIT 1")
            ?? db_row('SELECT * FROM inspection_reports WHERE report_no IS NOT NULL LIMIT 1');
        db_transaction(static fn () => sheet_enqueue_report((int) $report['id']));
        sheet_worker_run(10, null, false);
        t_same($rows, count($state['tabs']['Reports']), 'row updated in place, not duplicated');

        // Failures back off, keep a sanitised message and finally stop at max attempts.
        db_transaction(static fn () => sheet_enqueue_report((int) $report['id']));
        $state['fail'] = 100;
        sheet_worker_run(1, null, false);
        $job = db_row("SELECT * FROM google_sheet_sync_logs WHERE sync_status = 'PENDING_SYNC' ORDER BY id DESC LIMIT 1");
        t_ok($job !== null && $job['next_attempt_at'] > now_str() && str_contains((string) $job['error_message'], 'UNAVAILABLE'), 'retry scheduled with reason');
        t_ok(! str_contains((string) $job['error_message'], 'ya29'), 'no token in the stored error');
        db_update('google_sheet_sync_logs', ['attempt_count' => 11, 'next_attempt_at' => now_str()], 'id = ?', [$job['id']]);
        sheet_worker_run(1, null, false);
        t_same('FAILED', db_value('SELECT sync_status FROM google_sheet_sync_logs WHERE id = ?', [$job['id']]), 'FAILED after max attempts');
        $state['fail'] = 0;
        sheet_retry((int) $job['id']);
        sheet_worker_run(5, null, false);
        t_same('SYNCED', db_value('SELECT sync_status FROM google_sheet_sync_logs WHERE id = ?', [$job['id']]), 'manual retry succeeds');

        // A nearly full detail spreadsheet postpones jobs instead of failing them.
        $state['rowCount'] = 400000;
        $approved = db_row("SELECT * FROM inspection_reports WHERE status IN ('QA_APPROVED', 'SUPERSEDED') ORDER BY id LIMIT 1");
        db_exec("DELETE FROM google_sheet_sync_logs WHERE report_id = ? AND job_type = 'OBSERVATIONS_APPEND'", [$approved['id']]);
        db_transaction(static fn () => sheet_enqueue_observations((int) $approved['id'], (string) $approved['inspection_date']));
        sheet_worker_run(5, null, false);
        $postponed = db_row("SELECT * FROM google_sheet_sync_logs WHERE report_id = ? AND job_type = 'OBSERVATIONS_APPEND'", [$approved['id']]);
        t_same('PENDING_SYNC', $postponed['sync_status']);
        t_ok(str_contains((string) $postponed['error_message'], 'full') && (int) $postponed['attempt_count'] === 0, 'postponed, attempt not counted');

        // Pausing the integration keeps the queue.
        db_update('system_settings', ['setting_value' => '0'], 'setting_key = ?', ['google.sync_enabled']);
        settings_refresh();
        t_ok(sheet_worker_run(5, null, false)['skipped'] !== null, 'worker skips while switched off');
    } finally {
        gs_transport(null, true);
        $GLOBALS['qms_config']['google_credentials_file'] = '';
        db_update('system_settings', ['setting_value' => '0'], 'setting_key = ?', ['google.sync_enabled']);
        settings_refresh();
        @unlink($file);
    }
}

<?php
/**
 * Google Sheets: API client, row builder and the outbox worker
 * (run by cron/sheets_sync.php every minute).
 *
 * Client: Sheets API v4 with a service account, using only PHP's cURL and
 * OpenSSL (no SDK). Only the PATH of the key file is configured
 * (config.php → google_credentials_file); the key lives outside public folders.
 * A JWT signed with the private key (RS256) is exchanged for a one-hour access
 * token, kept in memory only. All writes use valueInputOption=RAW, so text
 * such as "=HYPERLINK(...)" is stored as text, never run as a formula.
 * TLS certificates are always verified.
 *
 * Worker: Google is called only here, never inside a business transaction.
 * Jobs are claimed with an atomic UPDATE (parallel runs never send the same
 * job); rows are de-duplicated by the sync key in column A; failures back off
 * exponentially (1, 2, 4 … 360 min ± 20 %) and become FAILED after
 * google.max_attempts; a capacity guard protects the 10 M cell limit.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/sheets.php';
require_once QMS_ROOT . '/includes/workflow.php';

const GS_API              = 'https://sheets.googleapis.com/v4/spreadsheets/';
const GS_SCOPE            = 'https://www.googleapis.com/auth/spreadsheets';
const GS_CELL_LIMIT       = 10000000;
const GS_BATCH_SIZE       = 40;
const GS_STALE_LOCK_MIN   = 10;
const GS_PAUSE_MS         = 1100;
const GS_REPORT_HEADERS   = [
    'Sync Key', 'Report No', 'Revision', 'Report Type', 'Production Date', 'Shift', 'Part Number', 'Part Name', 'Machine',
    'Operator', 'Setter', 'Status', 'Overall Result', 'OOS Readings', 'Accepted', 'Rejected', 'Rework', 'Expired Gauge Used',
    'Submitted By', 'Submitted At', 'Production Verified By', 'Production Verified At', 'Quality Verified By',
    'Quality Verified At', 'QA Approved By', 'QA Approved At', 'Template / Version', 'Format Doc No / Rev', 'QMS ID', 'Synced At',
];
const GS_OBSERVATION_HEADERS = [
    'Sync Key', 'Report No', 'Revision', 'Report Type', 'Production Date', 'Part Number', 'Machine', 'Shift', 'Round',
    'Inspection Time', 'Section', 'Parameter Code', 'Parameter', 'Specification', 'LSL', 'USL', 'Unit', 'Method',
    'Gauge ID', 'Gauge Calibration Valid', 'Reading No', 'Value', 'Result', 'Remarks',
];

/** Thrown when the detail spreadsheet is nearly full: the job waits, it is not the job's fault. */
class SheetCapacityError extends RuntimeException
{
}

// ================================================================ client

/** State of the service-account key: configured, email, hint. */
function gs_credentials(): array
{
    $file = trim((string) config('google_credentials_file', ''));
    if ($file === '') {
        return ['configured' => false, 'email' => null,
            'hint' => "Set 'google_credentials_file' in config/config.php to the path of the service-account key (outside public folders)."];
    }
    if (! is_file($file) || ! is_readable($file)) {
        return ['configured' => false, 'email' => null,
            'hint' => 'The key file is missing or not readable by the web server user. Check the path and permissions (0440).'];
    }
    $key = gs_key();
    if ($key === null) {
        return ['configured' => false, 'email' => null, 'hint' => 'The key file is not a Google service-account JSON key.'];
    }
    if (! function_exists('curl_init') || ! function_exists('openssl_sign')) {
        return ['configured' => false, 'email' => (string) $key['client_email'], 'hint' => 'The PHP extensions curl and openssl are required for Google Sheets.'];
    }

    return ['configured' => true, 'email' => (string) $key['client_email'],
        'hint' => 'Share the spreadsheet (and the detail spreadsheet, if used) with this e-mail as Editor. Do not share it with anyone else as Editor.'];
}

function gs_key(): ?array
{
    $file = trim((string) config('google_credentials_file', ''));
    if ($file === '' || ! is_readable($file)) {
        return null;
    }
    $json = json_decode((string) file_get_contents($file), true);

    return is_array($json) && ($json['type'] ?? '') === 'service_account' && isset($json['client_email'], $json['private_key']) ? $json : null;
}

function gs_spreadsheet_title(string $spreadsheetId): string
{
    $data = gs_call('GET', gs_id($spreadsheetId) . '?fields=' . rawurlencode('properties.title'));

    return (string) ($data['properties']['title'] ?? '');
}

/** @return array<string, array{sheetId: int, rows: int, cols: int}> tab title => size */
function gs_tabs(string $spreadsheetId): array
{
    $data = gs_call('GET', gs_id($spreadsheetId) . '?fields=' . rawurlencode('sheets.properties'));
    $out  = [];
    foreach ($data['sheets'] ?? [] as $sheet) {
        $props = $sheet['properties'] ?? [];
        $grid  = $props['gridProperties'] ?? [];
        $out[(string) ($props['title'] ?? '')] = ['sheetId' => (int) ($props['sheetId'] ?? 0), 'rows' => (int) ($grid['rowCount'] ?? 0), 'cols' => (int) ($grid['columnCount'] ?? 0)];
    }

    return $out;
}

/** Creates a tab with a header row, protected so that only the service account can edit it. */
function gs_add_tab(string $spreadsheetId, string $title, array $headers): void
{
    $response = gs_call('POST', gs_id($spreadsheetId) . ':batchUpdate', ['requests' => [[
        'addSheet' => ['properties' => ['title' => $title, 'gridProperties' => ['frozenRowCount' => 1, 'columnCount' => max(26, count($headers)), 'rowCount' => 1000]]],
    ]]]);
    $sheetId = (int) ($response['replies'][0]['addSheet']['properties']['sheetId'] ?? 0);
    gs_call('PUT', gs_id($spreadsheetId) . '/values/' . rawurlencode(gs_a1($title, 'A1')) . '?valueInputOption=RAW', ['values' => [array_values($headers)]]);

    $email = gs_credentials()['email'];
    if ($email !== null) {
        gs_call('POST', gs_id($spreadsheetId) . ':batchUpdate', ['requests' => [[
            'addProtectedRange' => ['protectedRange' => ['range' => ['sheetId' => $sheetId], 'description' => 'Managed by QMS - edit in a separate tab',
                'warningOnly' => false, 'editors' => ['users' => [$email]]]],
        ]]]);
    }
}

/** @return list<string> values of column A (sync keys) */
function gs_read_key_column(string $spreadsheetId, string $tab): array
{
    $data = gs_call('GET', gs_id($spreadsheetId) . '/values/' . rawurlencode(gs_a1($tab, 'A:A')) . '?majorDimension=COLUMNS');

    return array_map('strval', $data['values'][0] ?? []);
}

function gs_update_row(string $spreadsheetId, string $tab, int $row, array $values): string
{
    $data = gs_call('PUT', gs_id($spreadsheetId) . '/values/' . rawurlencode(gs_a1($tab, 'A' . $row)) . '?valueInputOption=RAW', ['values' => [gs_clean($values)]]);

    return (string) ($data['updatedRange'] ?? '');
}

function gs_append_rows(string $spreadsheetId, string $tab, array $rows): string
{
    $data = gs_call('POST', gs_id($spreadsheetId) . '/values/' . rawurlencode(gs_a1($tab, 'A1')) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',
        ['values' => array_map('gs_clean', $rows)]);

    return (string) ($data['updates']['updatedRange'] ?? '');
}

function gs_call(string $method, string $path, ?array $body = null): array
{
    $headers = ['Authorization: Bearer ' . gs_access_token(), 'Accept: application/json'];
    $payload = null;
    if ($body !== null) {
        $payload   = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json; charset=utf-8';
    }
    [$status, $response] = gs_http($method, GS_API . $path, $headers, $payload);
    if ($status >= 400) {
        $decoded = json_decode($response, true);
        throw new RuntimeException(is_array($decoded) && isset($decoded['error']) ? $response : "HTTP {$status}: " . mb_substr(trim(strip_tags($response)), 0, 300), $status);
    }
    $data = json_decode($response, true);

    return is_array($data) ? $data : [];
}

/** One-hour access token from a signed JWT (kept in memory for this run only). */
function gs_access_token(): string
{
    static $token = null;
    static $expires = 0;
    if ($token !== null && time() < $expires - 60) {
        return $token;
    }
    $state = gs_credentials();
    if (! $state['configured']) {
        throw new RuntimeException($state['hint']);
    }
    $key = gs_key();
    $now = time();
    $aud = (string) ($key['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    if (! str_starts_with($aud, 'https://')) {
        throw new RuntimeException('The token_uri in the key file must be an https:// address.');
    }
    $header = ['alg' => 'RS256', 'typ' => 'JWT'] + (isset($key['private_key_id']) ? ['kid' => (string) $key['private_key_id']] : []);
    $claims = ['iss' => $key['client_email'], 'scope' => GS_SCOPE, 'aud' => $aud, 'iat' => $now, 'exp' => $now + 3600];
    $input  = gs_base64url((string) json_encode($header)) . '.' . gs_base64url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    $private = openssl_pkey_get_private((string) $key['private_key']);
    if ($private === false || ! openssl_sign($input, $signature, $private, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The private key in the service-account file could not be used.');
    }
    [$status, $response] = gs_http('POST', $aud, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $input . '.' . gs_base64url($signature)]));
    $data = json_decode($response, true);
    if ($status !== 200 || ! is_array($data) || ! isset($data['access_token'])) {
        $reason = is_array($data) ? trim(($data['error'] ?? '') . ' ' . ($data['error_description'] ?? '')) : '';
        throw new RuntimeException('Google rejected the service-account login' . ($reason !== '' ? ": {$reason}" : '') . '. Check the key file and the server clock.', $status);
    }
    $token   = (string) $data['access_token'];
    $expires = $now + (int) ($data['expires_in'] ?? 3600);

    return $token;
}

/**
 * HTTPS request with certificate checks. A test can replace the transport:
 * gs_transport(fn ($method, $url, $headers, $body) => [200, '{}']).
 *
 * @return array{0: int, 1: string} status code and body
 */
function gs_http(string $method, string $url, array $headers, ?string $body): array
{
    $fake = gs_transport();
    if ($fake !== null) {
        return $fake($method, $url, $headers, $body);
    }
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('cURL could not be initialised.');
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_merge($headers, ['User-Agent: QMS Inspection Sync']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_ENCODING       => '',
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    $status   = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error    = curl_error($curl);
    unset($curl);
    if ($response === false) {
        throw new RuntimeException('Could not reach Google: ' . $error);
    }

    return [$status, (string) $response];
}

/** Replacement HTTP transport for tests (null = real HTTPS). */
function gs_transport(?callable $set = null, bool $reset = false): ?callable
{
    static $transport = null;
    if ($set !== null || $reset) {
        $transport = $set;
    }

    return $transport;
}

function gs_id(string $spreadsheetId): string
{
    if (! preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $spreadsheetId)) {
        throw new RuntimeException('The spreadsheet ID is not valid. Copy it from the sheet URL (between /d/ and /edit).');
    }

    return $spreadsheetId;
}

function gs_a1(string $tab, string $cells): string
{
    return "'" . str_replace("'", "''", $tab) . "'!" . $cells;
}

function gs_clean(array $values): array
{
    return array_map(static fn ($v) => $v ?? '', array_values($values));
}

function gs_base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Error text safe to store and show: no tokens, keys or headers, at most 2000 characters. */
function gs_sanitize(Throwable $e): string
{
    $message = $e->getMessage();
    $decoded = json_decode($message, true);
    if (is_array($decoded) && isset($decoded['error'])) {
        $error   = $decoded['error'];
        $message = is_array($error) ? trim(($error['code'] ?? '') . ' ' . ($error['status'] ?? '') . ': ' . ($error['message'] ?? '')) : (string) $error;
    }
    $message = preg_replace([
        '/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s',
        '/ya29\.[0-9A-Za-z\-_.]+/',
        '/Bearer\s+[0-9A-Za-z\-_.~+\/]+=*/i',
        '/(access_token|refresh_token|private_key|client_secret|assertion)(["\']?\s*[:=]\s*)["\']?[^"\'&\s,}]+/i',
    ], ['[key removed]', '[token removed]', 'Bearer [token removed]', '$1$2[removed]'], $message) ?? 'Error';
    $code = (int) $e->getCode();

    return mb_substr(($code > 0 && ! str_contains($message, (string) $code) ? "HTTP {$code}: " : '') . trim($message), 0, 2000);
}

// ================================================================ rows

/** Sync key of a report row: <report no>|R<revision>. */
function sheet_report_key(array $report): string
{
    return $report['report_no'] . '|R' . (int) $report['revision_no'];
}

/**
 * Reports-tab row from the CURRENT database state.
 *
 * @return array{key: string, values: list<scalar|null>}
 */
function sheet_report_row(int $reportId): array
{
    $report  = insp_report($reportId);
    $signers = [];
    foreach (wf_steps($report, insp_approvals($reportId)) as $step) {
        $sig = $step['state'] === 'done' ? $step['signature'] : null;
        $signers[$step['key']] = [
            $sig === null ? '' : trim(($sig['full_name'] ?? $sig['username']) . ($sig['employee_code'] ? ' (' . $sig['employee_code'] . ')' : '')),
            $sig === null ? '' : to_plant($sig['signed_at'], 'Y-m-d H:i'),
        ];
    }
    $rounds = db_all('SELECT ro.*, s.code AS shift_code, e.full_name AS operator_name FROM inspection_rounds ro
                        LEFT JOIN shifts s ON s.id = ro.shift_id LEFT JOIN users u ON u.id = ro.operator_user_id LEFT JOIN employees e ON e.id = u.employee_id
                       WHERE ro.report_id = ? ORDER BY ro.round_no', [$reportId]);
    $grid       = $report['layout'] === 'SHIFT_GRID';
    $quantities = ['', '', ''];
    if ($grid) {
        $quantities = [0, 0, 0];
        foreach ($rounds as $round) {
            $quantities[0] += (int) $round['accepted_qty'];
            $quantities[1] += (int) $round['rejected_qty'];
            $quantities[2] += (int) $round['rework_qty'];
        }
    }
    $expired  = (int) db_value('SELECT COUNT(*) FROM inspection_observations WHERE report_id = ? AND gauge_id IS NOT NULL AND gauge_cal_valid = 0', [$reportId]) > 0;
    $shift    = $grid ? implode(', ', array_values(array_unique(array_filter(array_column($rounds, 'shift_code'))))) : (string) ($report['shift_code'] ?? '');
    $operator = $grid
        ? implode(', ', array_values(array_unique(array_filter(array_column($rounds, 'operator_name')))))
        : trim(($report['operator_name'] ?? '') . ($report['operator_code'] !== null ? ' (' . $report['operator_code'] . ')' : ''));
    $stage    = static fn (string $key): array => $signers[$key] ?? ['', ''];

    $values = [
        sheet_report_key($report),
        (string) $report['report_no'],
        (int) $report['revision_no'],
        (string) $report['type_code'],
        (string) $report['inspection_date'],
        $shift,
        (string) $report['part_number'],
        (string) $report['part_name'],
        (string) $report['machine_code'],
        $operator,
        trim(($report['setter_name'] ?? '') . ($report['setter_code'] !== null ? ' (' . $report['setter_code'] . ')' : '')),
        status_label((string) $report['status']),
        (string) ($report['overall_result'] ?? ''),
        (int) $report['oos_count'],
        $quantities[0],
        $quantities[1],
        $quantities[2],
        $expired ? 'YES' : 'NO',
        ...$stage('SUBMISSION'),
        ...$stage('PRODUCTION'),
        ...$stage('QUALITY'),
        ...$stage('QA'),
        $report['template_code'] . ' v' . (int) $report['template_version'],
        $report['format_doc_no'] . ' Rev ' . $report['format_rev_no'],
        (int) $report['id'],
        plant_now()->format('Y-m-d H:i'),
    ];

    return ['key' => $values[0], 'values' => $values];
}

/**
 * One row per reading of a final report; $mode ALL or OOS_ONLY.
 * Key: <report no>|R<revision>|<round>|<template parameter id>|<reading no>.
 *
 * @return list<array{key: string, values: list<scalar|null>}>
 */
function sheet_observation_rows(int $reportId, string $mode): array
{
    $report = insp_report($reportId);
    $rows   = db_all('SELECT rd.*, o.lsl, o.usl, o.remarks, o.gauge_cal_valid, o.template_parameter_id, ro.round_no, ro.inspection_time,
                             s.code AS shift_code, g.gauge_code, tp.name, tp.specification_text, tp.observation_type, tp.decimal_places,
                             ip.code AS parameter_code, ts.title AS section_title, u.symbol AS unit_symbol, m.short_label AS method_label
                        FROM inspection_readings rd
                        JOIN inspection_observations o ON o.id = rd.observation_id
                        JOIN inspection_rounds ro ON ro.id = o.round_id
                        LEFT JOIN shifts s ON s.id = ro.shift_id
                        LEFT JOIN gauges g ON g.id = o.gauge_id
                        JOIN template_parameters tp ON tp.id = o.template_parameter_id
                        JOIN template_sections ts ON ts.id = tp.section_id
                        JOIN inspection_parameters ip ON ip.id = tp.parameter_id
                        LEFT JOIN units u ON u.id = tp.unit_id
                        LEFT JOIN inspection_methods m ON m.id = tp.inspection_method_id
                       WHERE o.report_id = ? AND rd.result IS NOT NULL
                       ORDER BY ro.round_no, ts.sort_order, tp.sort_order, rd.reading_no', [$reportId]);
    $base   = sheet_report_key($report);
    $number = static fn (?string $d): string|float => $d === null ? '' : (float) $d;
    $out    = [];
    foreach ($rows as $row) {
        if ($mode === 'OOS_ONLY' && $row['result'] !== 'FAIL') {
            continue;
        }
        // Numbers go to Sheets as numbers (so they can be charted); everything else as text.
        $value = match (true) {
            $row['value_numeric'] !== null => (float) $row['value_numeric'],
            $row['value_choice'] !== null  => str_replace('_', ' ', (string) $row['value_choice']),
            $row['value_date'] !== null    => (string) $row['value_date'],
            $row['value_time'] !== null    => substr((string) $row['value_time'], 0, 5),
            default                        => (string) ($row['value_text'] ?? ''),
        };
        $key   = $base . '|' . (int) $row['round_no'] . '|' . (int) $row['template_parameter_id'] . '|' . (int) $row['reading_no'];
        $out[] = ['key' => $key, 'values' => [
            $key, (string) $report['report_no'], (int) $report['revision_no'], (string) $report['type_code'], (string) $report['inspection_date'],
            (string) $report['part_number'], (string) $report['machine_code'],
            $report['layout'] === 'SHIFT_GRID' ? (string) ($row['shift_code'] ?? '') : (string) ($report['shift_code'] ?? ''),
            (int) $row['round_no'], $row['inspection_time'] === null ? '' : substr((string) $row['inspection_time'], 0, 5),
            (string) $row['section_title'], (string) $row['parameter_code'], (string) $row['name'], (string) ($row['specification_text'] ?? ''),
            $number($row['lsl']), $number($row['usl']), $row['observation_type'] === 'PERCENTAGE' ? '%' : (string) ($row['unit_symbol'] ?? ''),
            (string) ($row['method_label'] ?? ''), (string) ($row['gauge_code'] ?? ''),
            $row['gauge_cal_valid'] === null ? '' : ((int) $row['gauge_cal_valid'] === 1 ? 'YES' : 'NO'),
            (int) $row['reading_no'], $value, (string) $row['result'], (string) ($row['remarks'] ?? ''),
        ]];
    }

    return $out;
}

// ================================================================ worker

/**
 * Sends waiting jobs.
 *
 * @param callable(string): void|null $log
 *
 * @return array{processed: int, synced: int, failed: int, skipped: ?string}
 */
function sheet_worker_run(int $limit, ?callable $log = null, bool $pause = true): array
{
    $log ??= static function (string $line): void {
    };
    $summary = ['processed' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => null];
    if (! setting_bool('google.sync_enabled')) {
        return ['skipped' => 'Google Sheets sync is switched off (Settings → Google Sheets).'] + $summary;
    }
    $main = trim(setting_str('google.spreadsheet_id'));
    if ($main === '') {
        return ['skipped' => 'No spreadsheet ID configured.'] + $summary;
    }
    $credentials = gs_credentials();
    if (! $credentials['configured']) {
        sheet_heartbeat(false, $credentials['hint']);

        return ['skipped' => $credentials['hint']] + $summary;
    }
    // Only one worker at a time.
    if ((int) db_value("SELECT GET_LOCK('qms_sheets_sync', 0)") !== 1) {
        return ['skipped' => 'Another sync worker is running.'] + $summary;
    }

    $state = ['tabs' => [], 'keys' => []];
    try {
        sheet_reclaim_stale();
        $worker    = substr(gethostname() . ':' . getmypid(), 0, 64);
        $lastError = null;
        for ($i = 0; $i < $limit; $i++) {
            $job = db_row("SELECT * FROM google_sheet_sync_logs WHERE sync_status = 'PENDING_SYNC' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT 1", [now_str()]);
            if ($job === null) {
                break;
            }
            if (! sheet_claim($job, $worker)) {
                continue;
            }
            $summary['processed']++;
            $attempt = (int) $job['attempt_count'] + 1;
            try {
                [$spreadsheet, $range] = $job['job_type'] === SHEET_REPORT_UPSERT
                    ? sheet_upsert_report($job, $main, $state)
                    : sheet_append_observations($job, $main, $attempt, $state);
                sheet_succeed($job, $spreadsheet, $range);
                $summary['synced']++;
                $log("#{$job['id']} {$job['job_type']} report {$job['report_id']} → {$range}");
            } catch (SheetCapacityError $e) {
                sheet_postpone($job, $e->getMessage());
                $lastError = $e->getMessage();
                $log("#{$job['id']} postponed: {$e->getMessage()}");
            } catch (Throwable $e) {
                $message   = gs_sanitize($e);
                $lastError = $message;
                sheet_fail($job, $attempt, $message);
                $summary['failed']++;
                $log("#{$job['id']} attempt {$attempt} failed: {$message}");
            }
            if ($pause) {
                usleep(GS_PAUSE_MS * 1000);
            }
        }
        sheet_heartbeat($summary['failed'] === 0, $lastError, $summary);
    } finally {
        db_value("SELECT RELEASE_LOCK('qms_sheets_sync')");
    }

    return $summary;
}

/** Retry delay for attempt n (1-based): min(2^(n-1) min, 6 h) ± 20 %. */
function sheet_backoff_seconds(int $attempt, ?float $jitter = null): int
{
    $minutes = min(2 ** max(0, $attempt - 1), 360);
    $jitter ??= random_int(-200, 200) / 1000;

    return max(30, (int) round($minutes * 60 * (1 + $jitter)));
}

/** @return array{0: string, 1: string} spreadsheet id and range */
function sheet_upsert_report(array $job, string $spreadsheet, array &$state): array
{
    $row = sheet_report_row((int) $job['report_id']);
    $tab = (string) $job['sheet_name'];
    sheet_ensure_tab($spreadsheet, $tab, GS_REPORT_HEADERS, $state);
    $keys  = sheet_key_column($spreadsheet, $tab, false, $state);
    $index = array_search($row['key'], $keys, true);
    if ($index !== false && $index > 0) {
        $range = gs_update_row($spreadsheet, $tab, $index + 1, $row['values']);
    } else {
        $range = gs_append_rows($spreadsheet, $tab, [$row['values']]);
        $state['keys'][$spreadsheet . '|' . $tab][] = $row['key'];
    }

    return [$spreadsheet, $range];
}

/** @return array{0: string, 1: string} */
function sheet_append_observations(array $job, string $main, int $attempt, array &$state): array
{
    $spreadsheet = trim(setting_str('google.detail_spreadsheet_id'));
    $spreadsheet = $spreadsheet === '' ? $main : $spreadsheet;
    $mode        = sheet_detail_mode();
    if ($mode === 'NONE') {
        return [$spreadsheet, 'not sent (observation detail switched off)'];
    }
    $rows = sheet_observation_rows((int) $job['report_id'], $mode);
    if ($rows === []) {
        return [$spreadsheet, 'nothing to send'];
    }
    $tab = (string) $job['sheet_name'];
    sheet_ensure_tab($spreadsheet, $tab, GS_OBSERVATION_HEADERS, $state);
    sheet_guard_capacity($spreadsheet, count($rows) * count(GS_OBSERVATION_HEADERS), $state);
    if ($attempt > 1) {
        // An earlier attempt may have reached Google before failing: skip rows already there.
        $present = array_flip(sheet_key_column($spreadsheet, $tab, true, $state));
        $rows    = array_values(array_filter($rows, static fn (array $r): bool => ! isset($present[$r['key']])));
        if ($rows === []) {
            return [$spreadsheet, 'already present'];
        }
    }

    return [$spreadsheet, gs_append_rows($spreadsheet, $tab, array_column($rows, 'values'))];
}

function sheet_ensure_tab(string $spreadsheet, string $tab, array $headers, array &$state): void
{
    $state['tabs'][$spreadsheet] ??= gs_tabs($spreadsheet);
    if (! isset($state['tabs'][$spreadsheet][$tab])) {
        gs_add_tab($spreadsheet, $tab, $headers);
        $state['tabs'][$spreadsheet]               = gs_tabs($spreadsheet);
        $state['keys'][$spreadsheet . '|' . $tab] = [$headers[0]];
    }
}

function sheet_key_column(string $spreadsheet, string $tab, bool $refresh, array &$state): array
{
    $cacheKey = $spreadsheet . '|' . $tab;
    if ($refresh || ! isset($state['keys'][$cacheKey])) {
        $state['keys'][$cacheKey] = gs_read_key_column($spreadsheet, $tab);
    }

    return $state['keys'][$cacheKey];
}

function sheet_guard_capacity(string $spreadsheet, int $newCells, array &$state): void
{
    $state['tabs'][$spreadsheet] = gs_tabs($spreadsheet);
    $cells = 0;
    foreach ($state['tabs'][$spreadsheet] as $tab) {
        $cells += $tab['rows'] * $tab['cols'];
    }
    $percent = max(10, min(99, setting_int('google.capacity_alert_percent', 80)));
    if ($cells + $newCells >= GS_CELL_LIMIT * $percent / 100) {
        throw new SheetCapacityError(sprintf(
            'Detail spreadsheet is %d %% full (%s of 10,000,000 cells). Create a new detail spreadsheet, share it with the service account and enter its ID in Settings. Jobs wait until then.',
            (int) floor($cells * 100 / GS_CELL_LIMIT), number_format($cells)));
    }
}

function sheet_claim(array $job, string $worker): bool
{
    $now = now_str();

    return db_exec("UPDATE google_sheet_sync_logs SET attempt_count = attempt_count + 1, sync_status = 'PROCESSING', locked_at = ?, locked_by = ?, last_attempt_at = ?
                     WHERE id = ? AND sync_status = 'PENDING_SYNC'", [$now, $worker, $now, $job['id']]) === 1;
}

function sheet_succeed(array $job, string $spreadsheet, string $range): void
{
    $now = now_str();
    db_update('google_sheet_sync_logs', ['sync_status' => 'SYNCED', 'synced_at' => $now, 'spreadsheet_id' => mb_substr($spreadsheet, 0, 100),
        'sheet_range' => mb_substr($range, 0, 120), 'error_message' => null, 'locked_at' => null, 'locked_by' => null], 'id = ?', [$job['id']]);
    if ($job['job_type'] === SHEET_REPORT_UPSERT) {
        // Older waiting / failed upserts of the same report are covered by this newer state.
        db_exec("UPDATE google_sheet_sync_logs SET sync_status = 'SYNCED', synced_at = ?, spreadsheet_id = ?, sheet_range = ?, error_message = ?
                  WHERE report_id = ? AND job_type = ? AND id < ? AND sync_status IN ('PENDING_SYNC', 'FAILED')",
            [$now, mb_substr($spreadsheet, 0, 100), mb_substr($range, 0, 120), 'Covered by job #' . $job['id'], $job['report_id'], SHEET_REPORT_UPSERT, $job['id']]);
    }
}

function sheet_fail(array $job, int $attempt, string $message): void
{
    $final = $attempt >= max(1, setting_int('google.max_attempts', 12));
    db_update('google_sheet_sync_logs', [
        'sync_status'     => $final ? 'FAILED' : 'PENDING_SYNC',
        'next_attempt_at' => now_utc()->modify('+' . sheet_backoff_seconds($attempt) . ' seconds')->format('Y-m-d H:i:s'),
        'error_message'   => $message,
        'locked_at'       => null,
        'locked_by'       => null,
    ], 'id = ?', [$job['id']]);
    if ($final) {
        log_message('error', "Google Sheets sync job {$job['id']} FAILED after {$attempt} attempts: {$message}");
    }
}

/** Capacity problems are not the job's fault: wait an hour and do not count the attempt. */
function sheet_postpone(array $job, string $message): void
{
    db_exec("UPDATE google_sheet_sync_logs SET attempt_count = GREATEST(attempt_count - 1, 0), sync_status = 'PENDING_SYNC', next_attempt_at = ?,
                    error_message = ?, locked_at = NULL, locked_by = NULL WHERE id = ?",
        [now_utc()->modify('+1 hour')->format('Y-m-d H:i:s'), mb_substr($message, 0, 2000), $job['id']]);
}

/** Jobs left PROCESSING by a crashed worker go back to the queue. */
function sheet_reclaim_stale(): void
{
    db_exec("UPDATE google_sheet_sync_logs SET sync_status = 'PENDING_SYNC', locked_at = NULL, locked_by = NULL, next_attempt_at = ?
              WHERE sync_status = 'PROCESSING' AND locked_at < ?", [now_str(), now_utc()->modify('-' . GS_STALE_LOCK_MIN . ' minutes')->format('Y-m-d H:i:s')]);
}

/** Last run information for the Sync monitor (storage/sync-heartbeat.php). */
function sheet_heartbeat(bool $ok, ?string $error, array $summary = []): void
{
    $current = sheet_read_heartbeat();
    $now     = now_str();
    $data    = [
        'last_run'     => $now,
        'last_success' => $ok ? $now : ($current['last_success'] ?? null),
        'last_error'   => $error ?? ($ok ? null : ($current['last_error'] ?? null)),
        'last_summary' => $summary,
    ];
    @file_put_contents(QMS_ROOT . '/storage/sync-heartbeat.php', "<?php exit; ?>\n" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function sheet_read_heartbeat(): array
{
    $file = QMS_ROOT . '/storage/sync-heartbeat.php';
    if (! is_file($file)) {
        return [];
    }
    $content = (string) file_get_contents($file);
    $data    = json_decode((string) substr($content, (int) strpos($content, "\n") + 1), true);

    return is_array($data) ? $data : [];
}

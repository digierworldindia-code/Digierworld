<?php
/**
 * Small helpers used by every page: escaping, URLs, redirects, flash
 * messages, request input and display formatting.
 */
defined('QMS') || exit;

// ---------------------------------------------------------------- escaping

/** HTML-escapes a value for output (attributes and text). Non-strings are returned unchanged. */
function e(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('e', $value);
    }
    if (! is_string($value)) {
        return $value ?? '';
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------- URLs and responses

/** Site address with a trailing slash (config base_url, or detected before installation). */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = trim((string) config('base_url', ''));
    if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
        return $base = rtrim($configured, '/') . '/';
    }
    if (QMS_CLI) {
        return $base = 'http://localhost/';
    }
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host   = preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) ? $host : 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $folder = rtrim(dirname($script), '/');
    $folder = (string) preg_replace('#/(admin|api|cron)$#', '', $folder);

    return $base = (is_https() ? 'https' : 'http') . '://' . $host . $folder . '/';
}

/** Absolute URL of a page: url('gauge.php?id=5'). */
function url(string $path = ''): string
{
    return base_url() . ltrim($path, '/');
}

/** URL of a file in assets/ with a cache-busting version. */
function asset(string $path): string
{
    $file = QMS_ROOT . '/assets/' . ltrim($path, '/');

    return url('assets/' . ltrim($path, '/')) . '?v=' . (is_file($file) ? filemtime($file) : '1');
}

/** Redirects to a page of this application and stops. */
function redirect(string $to): never
{
    $target = preg_match('#^https?://#i', $to) ? $to : url($to);
    if (! str_starts_with($target, base_url())) {
        $target = base_url(); // no open redirects
    }
    header('Location: ' . $target, true, is_post() ? 303 : 302);
    exit;
}

/** The current page with its query string (for "back to the form"). */
function current_page(): string
{
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) parse_url(base_url(), PHP_URL_PATH);

    return ltrim(str_starts_with($uri, $path) ? substr($uri, strlen($path)) : $uri, '/');
}

/** Sends JSON and stops. */
function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** JSON error response from any exception. */
function json_error(Throwable $e): never
{
    $error = qms_error($e);
    $body  = ['ok' => false, 'error' => $error->getMessage()];
    if ($error->errors !== []) {
        $body['errors'] = $error->errors;
    }
    json_out($body, $error->status);
}

// ---------------------------------------------------------------- request

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function wants_json(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || defined('QMS_API');
}

/** Trimmed POST value ('' when missing or not a string). */
function post(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

/** POST values of several fields (missing → ''). */
function post_fields(array $keys): array
{
    $out = [];
    foreach ($keys as $key) {
        $value      = $_POST[$key] ?? '';
        $out[$key]  = is_string($value) ? trim($value) : '';
    }

    return $out;
}

/** POST array (checkbox lists): only string values. */
function post_list(string $key): array
{
    $value = $_POST[$key] ?? [];

    return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
}

/** Trimmed GET value ('' when missing). */
function get(string $key): string
{
    $value = $_GET[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

function get_int(string $key): int
{
    $value = get($key);

    return ctype_digit($value) ? (int) $value : 0;
}

/** Decoded JSON request body (fetch requests). */
function json_input(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true, 64);

    return is_array($data) ? $data : [];
}

function is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    return from_trusted_proxy() && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Client IP; X-Forwarded-For only from proxies listed in config trusted_proxies. */
function client_ip(): string
{
    if (QMS_CLI) {
        return 'cli';
    }
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (from_trusted_proxy()) {
        foreach (array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')))) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (! in_array($ip, (array) config('trusted_proxies', []), true)) {
                return $ip;
            }
        }
    }

    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
}

function from_trusted_proxy(): bool
{
    return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (array) config('trusted_proxies', []), true);
}

function user_agent(): string
{
    return QMS_CLI ? 'cli' : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

// ---------------------------------------------------------------- flash messages and old input

function flash(string $key, mixed $value): void
{
    $_SESSION['_flash_next'][$key] = $value;
}

function flash_get(string $key): mixed
{
    return $GLOBALS['qms_flash'][$key] ?? null;
}

/** Called once per page view: last request's flash data becomes readable. */
function flash_rotate(): void
{
    $GLOBALS['qms_flash'] = $_SESSION['_flash_next'] ?? [];
    unset($_SESSION['_flash_next']);
}

/** Keeps the posted form values for old() on the next page (never passwords). */
function keep_input(): void
{
    $input = [];
    foreach ($_POST as $key => $value) {
        if (! preg_match('/password|csrf|token/i', (string) $key)) {
            $input[$key] = $value;
        }
    }
    flash('_old', $input);
}

/** Value submitted with the previous (failed) form. */
function old(string $key, mixed $default = null): mixed
{
    return $GLOBALS['qms_flash']['_old'][$key] ?? $default;
}

/** Field errors of the previous (failed) form. */
function form_errors(): array
{
    $errors = flash_get('errors');

    return is_array($errors) ? $errors : [];
}

/** After a failed form post: message, field errors and input go back to the form. */
function back_with_error(Throwable $e, string $to): never
{
    $error = qms_error($e);
    flash('error', $error->getMessage());
    if ($error->errors !== []) {
        flash('errors', $error->errors);
    }
    keep_input();
    redirect($to);
}

/** Successful form post: message and redirect. */
function done(string $message, string $to): never
{
    flash('success', $message);
    redirect($to);
}

// ---------------------------------------------------------------- display

function qms_icon(string $name, string $extra = ''): string
{
    return '<i class="bi ' . e($name) . ($extra !== '' ? ' ' . e($extra) : '') . '" aria-hidden="true"></i>';
}

/** UTC DATETIME → plant time. */
function plant_dt(?string $utc, string $format = 'd-m-Y H:i'): string
{
    return to_plant($utc, $format);
}

/** Y-m-d → configured date format. */
function plant_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

    return $parsed === false ? $date : $parsed->format(setting_str('regional.date_format', 'd-m-Y'));
}

function status_badge(string $status): string
{
    $info = REPORT_STATUSES[$status] ?? null;
    if ($info === null) {
        return '<span class="qms-badge qms-badge--muted">' . e($status) . '</span>';
    }

    return '<span class="qms-badge qms-badge--' . $info['tone'] . '">' . qms_icon($info['icon']) . ' ' . e($info['label']) . '</span>';
}

function result_badge(?string $result): string
{
    return match ($result) {
        'PASS'           => '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-check-circle-fill') . ' PASS</span>',
        'FAIL'           => '<span class="qms-badge qms-badge--fail">' . qms_icon('bi-x-octagon-fill') . ' OUT OF SPEC</span>',
        'NOT_APPLICABLE' => '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-dash-circle') . ' N/A</span>',
        'INCOMPLETE'     => '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-circle') . ' INCOMPLETE</span>',
        default          => '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-circle') . ' NOT FILLED</span>',
    };
}

function sync_badge(?string $status): string
{
    return match ($status) {
        'SYNCED'                     => '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-cloud-check') . ' SYNCED</span>',
        'FAILED'                     => '<span class="qms-badge qms-badge--fail">' . qms_icon('bi-cloud-slash') . ' SYNC FAILED</span>',
        'PENDING_SYNC', 'PROCESSING' => '<span class="qms-badge qms-badge--pending">' . qms_icon('bi-cloud-arrow-up') . ' SYNC PENDING</span>',
        default                      => '',
    };
}

function active_badge(mixed $active): string
{
    return (int) $active === 1
        ? '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-check-circle') . ' Active</span>'
        : '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-archive') . ' Retired</span>';
}

/** DECIMAL string with a fixed number of places, without float conversion. */
function qms_decimal(?string $value, int $places): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $negative     = str_starts_with($value, '-');
    $value        = ltrim($value, '-+');
    [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
    $int          = ltrim($int, '0');
    $int          = $int === '' ? '0' : $int;
    $frac         = substr(str_pad($frac, $places, '0'), 0, $places);
    $out          = $places > 0 ? $int . '.' . $frac : $int;

    return ($negative && trim($out, '0.') !== '' ? '-' : '') . $out;
}

function field_invalid(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}

function field_error(array $errors, string $field): string
{
    return isset($errors[$field])
        ? '<div class="invalid-feedback d-block" id="err-' . e($field) . '">' . e($errors[$field]) . '</div>'
        : '';
}

/** Display text of a stored reading (screens and printouts). */
function reading_text(array $param, ?array $reading): string
{
    if ($reading === null) {
        return '';
    }

    return match (true) {
        $reading['value_numeric'] !== null => qms_decimal((string) $reading['value_numeric'], (int) $param['decimal_places']),
        $reading['value_choice'] !== null  => str_replace('_', ' ', (string) $reading['value_choice']),
        $reading['value_date'] !== null    => plant_date((string) $reading['value_date']),
        $reading['value_time'] !== null    => substr((string) $reading['value_time'], 0, 5),
        $reading['value_text'] !== null    => (string) $reading['value_text'],
        default                            => '',
    };
}

/** Value of a stored reading for an input control (raw codes, ISO dates, HH:MM). */
function reading_input(array $param, ?array $reading): string
{
    if ($reading === null) {
        return '';
    }

    return match (true) {
        $reading['value_numeric'] !== null => qms_decimal((string) $reading['value_numeric'], (int) $param['decimal_places']),
        $reading['value_choice'] !== null  => (string) $reading['value_choice'],
        $reading['value_date'] !== null    => (string) $reading['value_date'],
        $reading['value_time'] !== null    => substr((string) $reading['value_time'], 0, 5),
        $reading['value_text'] !== null    => (string) $reading['value_text'],
        default                            => '',
    };
}

/** "6.450 – 6.550 mm", "≥ 5.0 bar", "≤ 2.000" or "". */
function spec_limits(?string $lsl, ?string $usl, int $places, ?string $unit = null): string
{
    $lo   = $lsl === null || $lsl === '' ? null : qms_decimal($lsl, $places);
    $hi   = $usl === null || $usl === '' ? null : qms_decimal($usl, $places);
    $text = match (true) {
        $lo !== null && $hi !== null => $lo . ' – ' . $hi,
        $lo !== null                 => '≥ ' . $lo,
        $hi !== null                 => '≤ ' . $hi,
        default                      => '',
    };

    return $text !== '' && $unit !== null && $unit !== '' ? $text . ' ' . $unit : $text;
}

/** Random RFC 4122 version 4 UUID. */
function uuid_v4(): string
{
    $bytes    = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex      = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function is_uuid_v4(string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
}

// ---------------------------------------------------------------- paging

/**
 * Paging for list pages: ['page' => n, 'per_page' => n, 'offset' => n, 'total' => 0].
 */
function paging(int $perPage = 25): array
{
    $page = max(1, get_int('page'));

    return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => 0];
}

function paging_pages(array $paging): int
{
    return max(1, (int) ceil($paging['total'] / $paging['per_page']));
}

/** URL of another page of the current list (keeps the filters). */
function paging_url(int $page): string
{
    $query         = $_GET;
    $query['page'] = $page;
    $path          = strtok(current_page(), '?');

    return url((string) $path) . '?' . http_build_query($query);
}

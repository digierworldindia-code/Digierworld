<?php
/**
 * System settings (table system_settings, edited in Administration → Settings).
 */
defined('QMS') || exit;

/** All settings rows keyed by setting_key (cached for this request). */
function settings_rows(bool $reload = false): array
{
    static $rows = null;
    if ($rows === null || $reload) {
        $rows = [];
        try {
            foreach (db_all('SELECT * FROM system_settings ORDER BY id') as $row) {
                $rows[$row['setting_key']] = $row;
            }
        } catch (Throwable) {
            $rows = []; // not installed yet
        }
    }

    return $rows;
}

function settings_refresh(): void
{
    settings_rows(true);
}

function setting(string $key, mixed $default = null): mixed
{
    $row = settings_rows()[$key] ?? null;
    if ($row === null || $row['setting_value'] === null) {
        return $default;
    }
    $value = $row['setting_value'];

    return match ($row['value_type']) {
        'INTEGER' => is_numeric($value) ? (int) $value : $default,
        'BOOLEAN' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
        'JSON'    => json_decode($value, true),
        default   => $value,
    };
}

function setting_str(string $key, string $default = ''): string
{
    $value = setting($key);

    return $value === null ? $default : (string) $value;
}

function setting_int(string $key, int $default = 0): int
{
    $value = setting($key);

    return is_numeric($value) ? (int) $value : $default;
}

function setting_bool(string $key, bool $default = false): bool
{
    $value = setting($key);

    return $value === null ? $default : (bool) $value;
}

/** Settings of one group, in seeded order. */
function settings_group(string $group): array
{
    return array_values(array_filter(settings_rows(), static fn (array $r): bool => $r['setting_group'] === $group));
}

/**
 * Saves several settings of one group as one audited change.
 *
 * @param array<string, string> $values key => validated value
 */
function settings_update(string $group, array $values, ?int $userId): void
{
    $rows    = settings_rows();
    $before  = [];
    $after   = [];
    $now     = now_str();
    foreach ($values as $key => $value) {
        $row = $rows[$key] ?? null;
        if ($row === null || $row['setting_group'] !== $group) {
            fail_field($key, 'Unknown setting.');
        }
        $value = match ($row['value_type']) {
            'BOOLEAN' => in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
            'INTEGER' => (string) (int) $value,
            default   => trim((string) $value),
        };
        if ($value !== $row['setting_value']) {
            $before[$key] = $row['setting_value'];
            $after[$key]  = $value;
        }
    }
    if ($after === []) {
        return;
    }
    db_transaction(static function () use ($after, $before, $group, $now, $userId): void {
        foreach ($after as $key => $value) {
            db_update('system_settings', ['setting_value' => $value, 'updated_at' => $now, 'updated_by' => $userId], 'setting_key = ?', [$key]);
        }
        audit_log('UPDATE', 'settings', null, $before, $after, $group);
    });
    settings_refresh();
}

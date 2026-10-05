<?php
/**
 * Append-only audit trail: who (user, employee), what (action, module, record),
 * previous / new values, IP, user agent, date and time. Database triggers stop
 * anyone from changing or deleting audit rows.
 */
defined('QMS') || exit;

const AUDIT_REDACT = ['password', 'password_hash', 'new_password', 'current_password', 'security_stamp',
    'token', 'secret', 'credentials', 'private_key', 'idem_key'];

/** The acting user for audit rows (set at login check; null for the command line). */
function audit_actor(?array $actor = null, bool $set = false): ?array
{
    static $current = null;
    if ($set) {
        $current = $actor;
    }

    return $current;
}

/**
 * Writes one audit row. For updates pass $before and $after: only changed keys are kept.
 */
function audit_log(string $action, string $module, int|string|null $recordId = null, ?array $before = null, ?array $after = null, ?string $recordRef = null): void
{
    [$before, $after] = audit_diff($before, $after);
    $actor            = audit_actor();
    $json             = static fn (?array $v): ?string => $v === null ? null
        : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

    db_insert('audit_logs', [
        'user_id'        => $actor['id'] ?? null,
        'employee_id'    => $actor['employee_id'] ?? null,
        'username'       => $actor['username'] ?? null,
        'action'         => mb_substr(strtoupper($action), 0, 40),
        'module'         => mb_substr($module, 0, 40),
        'record_id'      => $recordId === null ? null : (int) $recordId,
        'record_ref'     => $recordRef === null ? null : mb_substr($recordRef, 0, 60),
        'previous_value' => $json($before),
        'new_value'      => $json($after),
        'ip_address'     => mb_substr(client_ip(), 0, 45),
        'user_agent'     => user_agent(),
        'request_id'     => request_id(),
        'created_at'     => now_str(),
    ]);
}

/**
 * @return array{0: array|null, 1: array|null}
 */
function audit_diff(?array $before, ?array $after): array
{
    if ($before !== null && $after !== null) {
        $b = [];
        $a = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            $same = (is_scalar($old) || $old === null) && (is_scalar($new) || $new === null) && (string) $old === (string) $new;
            if (! $same && json_encode($old) !== json_encode($new)) {
                $b[$key] = $old;
                $a[$key] = $new;
            }
        }
        [$before, $after] = [$b, $a];
    }

    return [audit_redact($before), audit_redact($after)];
}

function audit_redact(?array $values): ?array
{
    if ($values === null) {
        return null;
    }
    foreach ($values as $key => $value) {
        if (in_array(strtolower((string) $key), AUDIT_REDACT, true)) {
            $values[$key] = '[REDACTED]';
        } elseif (is_array($value)) {
            $values[$key] = audit_redact($value);
        }
    }

    return $values;
}

<?php
/**
 * Exactly-once execution of submit / approval requests.
 *
 * The browser sends an Idempotency-Key (UUID) per action attempt. The key row is
 * inserted in the same transaction as the action: a failed action leaves no key
 * (safe to retry); a successful one returns its stored answer to any repeat of
 * the same request (double tap, retry after a network drop).
 */
defined('QMS') || exit;

/**
 * @param callable(): array $work runs inside the transaction
 */
function idempotent_run(int $userId, ?string $key, string $action, array $payload, callable $work): array
{
    if ($key === null || $key === '') {
        return db_transaction($work);
    }
    if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key)) {
        fail_field('idempotency_key', 'Invalid request key.');
    }
    $key  = strtolower($key);
    $hash = hash('sha256', $action . '|' . json_encode($payload));

    $stored = idempotency_stored($userId, $key, $hash);
    if ($stored !== null) {
        return $stored;
    }

    try {
        return db_transaction(static function () use ($userId, $key, $action, $hash, $work): array {
            db_insert('idempotency_keys', ['user_id' => $userId, 'idem_key' => $key, 'action' => mb_substr($action, 0, 50),
                'request_hash' => $hash, 'created_at' => now_str()]);
            $response = $work();
            db_update('idempotency_keys', ['response_code' => 200,
                'response_body' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'user_id = ? AND idem_key = ?', [$userId, $key]);

            return $response;
        });
    } catch (PDOException $e) {
        if (db_error_code($e) === 1062 && str_contains($e->getMessage(), 'uq_idempotency_keys_user_key')) {
            // A parallel duplicate committed first: return its answer.
            return idempotency_stored($userId, $key, $hash) ?? fail('This request is still being processed. Wait a moment and refresh.', 409);
        }

        throw $e;
    }
}

function idempotency_stored(int $userId, string $key, string $hash): ?array
{
    $row = db_row('SELECT * FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [$userId, $key]);
    if ($row === null) {
        return null;
    }
    if (! hash_equals((string) $row['request_hash'], $hash)) {
        fail('This request key was already used with different data.', 422);
    }
    if ($row['response_code'] === null) {
        fail('This request is still being processed. Wait a moment and refresh.', 409);
    }

    return (array) json_decode((string) $row['response_body'], true) + ['replayed' => true];
}

/** Deletes keys older than $hours (cron/maintenance.php). */
function idempotency_purge(int $hours): int
{
    return db_exec('DELETE FROM idempotency_keys WHERE created_at < ?', [now_utc()->modify("-{$hours} hours")->format('Y-m-d H:i:s')]);
}

/** Idempotency-Key header, or the idempotency_key form field. */
function idempotency_key_from_request(array $data = []): ?string
{
    $key = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($key === '') {
        $key = is_string($data['idempotency_key'] ?? null) ? trim($data['idempotency_key']) : '';
    }

    return $key === '' ? null : $key;
}

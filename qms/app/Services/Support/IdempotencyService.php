<?php

namespace App\Services\Support;

use App\Exceptions\ConcurrencyException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ValidationException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Exactly-once execution of submit / approval requests.
 *
 * The browser sends an Idempotency-Key (UUID) per action attempt. The key row is
 * inserted in the same transaction as the action, so a failed action leaves no
 * key (safe to retry) and a successful one returns its stored response to any
 * repeat of the same request (double tap, retry after a network drop).
 */
class IdempotencyService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly TransactionRunner $tx,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed>                   $payload
     * @param callable(BaseConnection): array<string, mixed> $work
     *
     * @return array<string, mixed>
     */
    public function run(int $userId, ?string $key, string $action, array $payload, callable $work): array
    {
        if ($key === null || $key === '') {
            return $this->tx->run($work);
        }
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key)) {
            throw ValidationException::single('idempotency_key', 'Invalid request key.');
        }
        $hash = hash('sha256', $action . '|' . json_encode($payload));

        $stored = $this->stored($userId, $key, $hash);
        if ($stored !== null) {
            return $stored;
        }

        try {
            return $this->tx->run(function (BaseConnection $db) use ($userId, $key, $action, $hash, $work): array {
                $db->table('idempotency_keys')->insert([
                    'user_id' => $userId, 'idem_key' => strtolower($key), 'action' => mb_substr($action, 0, 50),
                    'request_hash' => $hash, 'created_at' => $this->clock->nowUtcString(),
                ]);
                $response = $work($db);
                $db->table('idempotency_keys')->where('user_id', $userId)->where('idem_key', strtolower($key))->update([
                    'response_code' => 200,
                    'response_body' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

                return $response;
            });
        } catch (DatabaseException $e) {
            if ((int) $e->getCode() === 1062) {
                // A parallel duplicate committed first: return its result.
                return $this->stored($userId, $key, $hash) ?? throw new ConcurrencyException('This request is still being processed. Wait a moment and refresh.');
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stored(int $userId, string $key, string $hash): ?array
    {
        $row = $this->db->table('idempotency_keys')->where('user_id', $userId)->where('idem_key', strtolower($key))->get(1)->getRowArray();
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row['request_hash'], $hash)) {
            throw new IdempotencyConflictException();
        }
        if ($row['response_code'] === null) {
            throw new ConcurrencyException('This request is still being processed. Wait a moment and refresh.');
        }

        return (array) json_decode((string) $row['response_body'], true) + ['replayed' => true];
    }

    public function purge(int $hours): int
    {
        $this->db->table('idempotency_keys')->where('created_at <', $this->clock->nowUtc()->modify("-{$hours} hours")->format('Y-m-d H:i:s'))->delete();

        return $this->db->affectedRows();
    }
}

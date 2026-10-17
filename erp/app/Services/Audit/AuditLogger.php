<?php

namespace App\Services\Audit;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Append-only audit trail with a SHA-256 hash chain (tamper evidence).
 *
 * Each row's hash covers the previous row's hash plus its own content, so
 * editing or deleting any historical row breaks verification
 * (`php spark dgr:audit-verify`). Secrets are redacted before storage.
 */
class AuditLogger
{
    /** Keys whose values are never written to the audit trail. */
    private const REDACT = [
        'password', 'password_confirm', 'secret', 'secret2', 'token', 'token_hash', 'account_number',
        'account_number_enc', 'cif', 'cif_enc', 'api_key', 'private_key', 'csrf_test_name', 'extracted_text',
    ];

    private string $actorType = 'user';

    private ?int $actorOverride = null;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** Switch actor (e.g. 'cron', 'system', 'ai_service') for the remainder of the request. */
    public function asActor(string $type, ?int $userId = null): self
    {
        $this->actorType     = $type;
        $this->actorOverride = $userId;

        return $this;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>      $meta
     */
    public function log(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $summary = null,
        array $meta = [],
    ): void {
        try {
            $userId = $this->actorOverride;
            if ($userId === null && $this->actorType === 'user' && function_exists('auth')) {
                $session = auth('session');
                $userId  = $session->loggedIn() ? (int) $session->id() : null;
            }

            $request   = service('request');
            $isCli     = is_cli();
            $ip        = $isCli ? null : $request->getIPAddress();
            $ua        = $isCli ? 'cli' : mb_substr((string) $request->getUserAgent(), 0, 255);
            $beforeJ   = $before === null ? null : json_encode($this->redact($before), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $afterJ    = $after === null ? null : json_encode($this->redact($after), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $metaJ     = $meta === [] ? null : json_encode($this->redact($meta), JSON_UNESCAPED_UNICODE);
            $occurred  = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
            $actorType = $this->actorType;

            $this->db->transStart();
            $prev     = $this->db->query('SELECT row_hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE')->getRowArray();
            $prevHash = $prev['row_hash'] ?? null;
            $row      = [
                'occurred_at' => $occurred,
                'user_id'     => $userId,
                'actor_type'  => $actorType,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId === null ? null : (int) $entityId,
                'summary'     => $summary === null ? null : mb_substr($summary, 0, 255),
                'before_data' => $beforeJ,
                'after_data'  => $afterJ,
                'meta'        => $metaJ,
                'ip_address'  => $ip,
                'user_agent'  => $ua,
                'prev_hash'   => $prevHash,
            ];
            $row['row_hash'] = self::hashRow($row);
            $this->db->table('audit_logs')->insert($row);
            $this->db->transComplete();
        } catch (Throwable $e) {
            // Auditing must never be silently skipped for financial actions:
            // surface it in logs; callers inside transactions will roll back.
            log_message('critical', 'Audit log write failed: ' . $e->getMessage());

            throw $e;
        }
    }

    /** @param array<string, mixed> $row */
    public static function hashRow(array $row): string
    {
        $parts = [
            $row['prev_hash'] ?? '',
            $row['occurred_at'],
            (string) ($row['user_id'] ?? ''),
            $row['actor_type'],
            $row['action'],
            (string) ($row['entity_type'] ?? ''),
            (string) ($row['entity_id'] ?? ''),
            (string) ($row['summary'] ?? ''),
            (string) ($row['before_data'] ?? ''),
            (string) ($row['after_data'] ?? ''),
            (string) ($row['meta'] ?? ''),
        ];

        return hash('sha256', implode("\x1F", $parts));
    }

    /**
     * Verify the whole chain. Returns [ok, firstBrokenId|null, rowsChecked].
     *
     * @return array{0: bool, 1: int|null, 2: int}
     */
    public function verify(): array
    {
        $prev    = null;
        $checked = 0;
        $lastId  = 0;
        while (true) {
            $rows = $this->db->table('audit_logs')->where('id >', $lastId)->orderBy('id')->limit(1000)->get()->getResultArray();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $occurred = (new \DateTimeImmutable($row['occurred_at']))->format('Y-m-d H:i:s.u');
                $row['occurred_at'] = $occurred;
                if ($row['prev_hash'] !== $prev || self::hashRow($row) !== $row['row_hash']) {
                    return [false, (int) $row['id'], $checked];
                }
                $prev   = $row['row_hash'];
                $lastId = (int) $row['id'];
                $checked++;
            }
        }

        return [true, null, $checked];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT, true)) {
                $data[$k] = $v === null || $v === '' ? $v : '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = $this->redact($v);
            }
        }

        return $data;
    }
}

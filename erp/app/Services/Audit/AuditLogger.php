<?php

namespace App\Services\Audit;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Append-only audit trail with a SHA-256 hash chain (tamper evidence).
 *
 * Each row's hash covers the previous row's hash plus its own content, so
 * editing or deleting any historical row breaks verification
 * (Admin › Audit log › Verify). Secrets are redacted before storage.
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
            if ($userId === null && $this->actorType === 'user') {
                $userId = service('authz')->currentUserId();
            }

            $request   = service('request');
            $isCli     = is_cli();
            $ip        = $isCli ? null : $request->getIPAddress();
            $ua        = $isCli ? 'cli' : mb_substr((string) $request->getUserAgent(), 0, 255);
            $beforeJ   = $before === null ? null : json_encode($this->redact($before), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $afterJ    = $after === null ? null : json_encode($this->redact($after), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $metaJ     = $meta === [] ? null : json_encode($this->redact($meta), JSON_UNESCAPED_UNICODE);
            $occurred  = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
            $row       = [
                'occurred_at' => $occurred,
                'user_id'     => $userId,
                'actor_type'  => $this->actorType,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId === null ? null : (int) $entityId,
                'summary'     => $summary === null ? null : mb_substr($summary, 0, 255),
                'before_data' => $beforeJ,
                'after_data'  => $afterJ,
                'meta'        => $metaJ,
                'ip_address'  => $ip,
                'user_agent'  => $ua,
            ];
            // Inside a business transaction the entry is appended when that transaction commits:
            // locking the newest chain row in the middle of it made concurrent saves deadlock.
            if ($this->db instanceof \App\Database\MySQLi\Connection && $this->db->inTransaction()) {
                $this->db->beforeCommit(fn () => $this->append($row));
            } else {
                $this->db->transBegin();
                try {
                    $this->append($row);
                    $this->db->transCommit();
                } catch (\Throwable $e) {
                    $this->db->transRollback();

                    throw $e;
                }
            }
        } catch (Throwable $e) {
            // Auditing must never be silently skipped for financial actions:
            // surface it in logs; callers inside transactions will roll back.
            log_message('critical', 'Audit log write failed: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Appends one entry to the hash chain. Writers take turns on one
     * settings row (an exact primary-key lock): locking the newest audit row
     * directly also locks the gap after it, which two writers can hold at
     * once and then deadlock on their inserts.
     *
     * @param array<string, mixed> $row
     */
    private function append(array $row): void
    {
        try {
            $lock = "SELECT setting_value FROM app_settings WHERE setting_key = 'audit.chain_lock' FOR UPDATE";
            if ($this->db->query($lock)->getRow() === null) {
                $this->db->query("INSERT IGNORE INTO app_settings (setting_key, setting_value, value_type) VALUES ('audit.chain_lock', '1', 'int')");
                $this->db->query($lock);
            }
            $prev             = $this->db->query('SELECT row_hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE')->getRowArray();
            $row['prev_hash'] = $prev['row_hash'] ?? null;
            $row['row_hash']  = self::hashRow($row);
            $this->db->table('audit_logs')->insert($row);
        } catch (Throwable $e) {
            log_message('critical', 'Audit log write failed: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Hash of one entry. JSON data is hashed in canonical form (keys sorted,
     * fixed encoding) because MySQL 8 rewrites JSON columns when storing
     * them; $canonical = false gives the pre-1.3.0 hash of the text as written.
     *
     * @param array<string, mixed> $row
     */
    public static function hashRow(array $row, bool $canonical = true): string
    {
        $json  = static fn ($v) => $v === null ? '' : ($canonical ? self::canonicalJson((string) $v) : (string) $v);
        $parts = [
            $row['prev_hash'] ?? '',
            $row['occurred_at'],
            (string) ($row['user_id'] ?? ''),
            $row['actor_type'],
            $row['action'],
            (string) ($row['entity_type'] ?? ''),
            (string) ($row['entity_id'] ?? ''),
            (string) ($row['summary'] ?? ''),
            $json($row['before_data'] ?? null),
            $json($row['after_data'] ?? null),
            $json($row['meta'] ?? null),
        ];

        return hash('sha256', implode("\x1F", $parts));
    }

    private static function canonicalJson(string $text): string
    {
        $data = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $text;
        }
        $sort = static function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($sort, $v);
        };

        return (string) json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Verify the whole chain. Returns [ok, firstBrokenId|null, rowsChecked,
     * olderEntriesNotContentChecked]. Every entry must link to the one
     * before it. Entries written before 1.3.0 on MySQL 8 that carry JSON
     * data cannot have their content re-hashed (MySQL rewrote that JSON when
     * storing it); their links are still checked and they are counted in
     * the fourth value. On MariaDB every entry is fully checked.
     *
     * @return array{0: bool, 1: int|null, 2: int, 3: int}
     */
    public function verify(): array
    {
        $prev      = null;
        $checked   = 0;
        $legacy    = 0;
        $lastId    = 0;
        $from      = (int) ($this->db->table('app_settings')->select('setting_value')->where('setting_key', 'audit.canonical_from_id')->get()->getRow()->setting_value ?? 0);
        $rewrites  = stripos((string) ($this->db->query('SELECT VERSION() v')->getRow()->v ?? ''), 'mariadb') === false; // MySQL normalises JSON columns
        while (true) {
            $rows = $this->db->table('audit_logs')->where('id >', $lastId)->orderBy('id')->limit(1000)->get()->getResultArray();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $row['occurred_at'] = (new \DateTimeImmutable($row['occurred_at']))->format('Y-m-d H:i:s.u');
                if ($row['prev_hash'] !== $prev) {
                    return [false, (int) $row['id'], $checked, $legacy];
                }
                $old = $from === 0 || (int) $row['id'] < $from;
                if (self::hashRow($row) !== $row['row_hash'] && ! ($old && self::hashRow($row, false) === $row['row_hash'])) {
                    $hasJson = $row['before_data'] !== null || $row['after_data'] !== null || $row['meta'] !== null;
                    if (! ($old && $rewrites && $hasJson)) {
                        return [false, (int) $row['id'], $checked, $legacy];
                    }
                    $legacy++;
                }
                $prev   = $row['row_hash'];
                $lastId = (int) $row['id'];
                $checked++;
            }
        }

        return [true, null, $checked, $legacy];
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

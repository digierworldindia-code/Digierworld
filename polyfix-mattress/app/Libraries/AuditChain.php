<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The audit log's SHA-256 hash chain.
 *
 * Each row's hash covers its own content plus the previous row's hash, so
 * altering or deleting any row breaks every link after it. The formula is
 * byte-for-byte the one the previous platform's PostgreSQL trigger used, which
 * is what lets the history imported from that database verify here:
 *
 *   sha256( prev_hash or 'genesis' ␟ occurred_at as UTC YYYYMMDDHHMMSSffffff
 *           ␟ user_id ␟ role_key ␟ dealer_id ␟ action ␟ entity ␟ entity_id
 *           ␟ previous_value ␟ new_value ␟ reason )
 *
 * where ␟ is the ASCII unit separator (0x1F) and a NULL contributes ''.
 *
 * This detects tampering. It does not prevent it: someone with direct write
 * access could recompute every hash after an edit. What they cannot do is edit
 * history without either breaking the chain or rewriting all of it after that
 * point, and backups taken before the edit still hold the original hashes.
 */
class AuditChain
{
    private const SEPARATOR = "\x1f";
    private const GENESIS   = 'genesis';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param array{occurred_at:string, user_id?:?string, role_key?:?string, dealer_id?:?string,
     *              action:string, entity:string, entity_id?:?string, previous_value?:?string,
     *              new_value?:?string, reason?:?string} $row  occurred_at in UTC
     */
    public static function hash(?string $previousHash, array $row): string
    {
        $fields = [
            $previousHash ?? self::GENESIS,
            self::timestamp($row['occurred_at']),
            (string) ($row['user_id'] ?? ''),
            (string) ($row['role_key'] ?? ''),
            (string) ($row['dealer_id'] ?? ''),
            (string) $row['action'],
            (string) $row['entity'],
            (string) ($row['entity_id'] ?? ''),
            (string) ($row['previous_value'] ?? ''),
            (string) ($row['new_value'] ?? ''),
            (string) ($row['reason'] ?? ''),
        ];

        return hash('sha256', implode(self::SEPARATOR, $fields));
    }

    /** '2026-09-22 14:12:27.503000' (UTC) -> '20260922141227503000' */
    public static function timestamp(string $utc): string
    {
        $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', str_contains($utc, '.') ? $utc : $utc . '.000000', new DateTimeZone('UTC'));

        return $moment->format('YmdHisu');
    }

    /**
     * Re-walks the chain in id order.
     *
     * @return array{rows:int, problems:list<string>}  every problem names a row id
     */
    public function verify(int $fromId = 0, int $limit = 1_000_000): array
    {
        $query = $this->db->query(
            'SELECT id, occurred_at, user_id, role_key, dealer_id, action, entity, entity_id,
                    previous_value, new_value, reason, prev_hash, row_hash
               FROM audit_logs WHERE id > ? ORDER BY id LIMIT ?',
            [$fromId, $limit],
        );

        $problems = [];
        $rows     = 0;
        $running  = null;
        $first    = true;

        while ($row = $query->getUnbufferedRow('array')) {
            $rows++;
            if ($first) {
                // Verification may start mid-chain; trust the first row's link.
                $running = $row['prev_hash'];
                $first   = false;
            } elseif ($row['prev_hash'] !== $running) {
                $problems[] = "row {$row['id']}: prev_hash does not match the preceding row";
            }

            if (self::hash($row['prev_hash'], $row) !== $row['row_hash']) {
                $problems[] = "row {$row['id']}: row_hash does not match the row's contents";
            }

            $running = $row['row_hash'];
        }

        return ['rows' => $rows, 'problems' => $problems];
    }
}

<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * The only way anything is written to the audit trail.
 *
 * Each entry is sealed into the SHA-256 hash chain (see AuditChain) while the
 * single audit_chain_head row is locked, so concurrent writers are serialised
 * and every entry links to exactly one predecessor. Called inside the
 * transaction of the change it records: if that change rolls back, so does its
 * audit entry, and the log never describes something that did not happen.
 *
 * The application's database account can INSERT into audit_logs but cannot
 * UPDATE, DELETE or TRUNCATE it. No screen, endpoint or crafted request can
 * alter history, because the privilege does not exist on the connection.
 */
final class Audit
{
    /** Never written to the log, at any depth. */
    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'passwordHash', 'new_password', 'newPassword',
        'current_password', 'currentPassword', 'mfa_secret_encrypted', 'mfaSecretEncrypted',
        'mfa_recovery_codes', 'mfaRecoveryCodes', 'token', 'token_hash', 'tokenHash',
        'phone_encrypted', 'phoneEncrypted', 'email_encrypted', 'emailEncrypted',
        'csrf_pfm', 'totp_code', 'totpCode', 'recovery_code',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly RequestContext $context,
    ) {
    }

    public static function instance(?BaseConnection $db = null): self
    {
        return new self($db ?? db_connect(), service('requestContext'));
    }

    /**
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed>|null $new
     * @param array{user_id?:?string, user_email?:?string, role_key?:?string, dealer_id?:?string} $actor
     *        override for events with no signed-in user yet (sign-in itself)
     */
    public function record(
        string $action,
        string $entity,
        ?string $entityId = null,
        ?array $previous = null,
        ?array $new = null,
        ?string $reason = null,
        array $actor = [],
    ): void {
        $work = function (BaseConnection $db) use ($action, $entity, $entityId, $previous, $new, $reason, $actor): void {
            $row = [
                'occurred_at'    => utc_now(),
                'user_id'        => $actor['user_id'] ?? $this->context->userId(),
                'user_email'     => $actor['user_email'] ?? $this->context->email(),
                'role_key'       => $actor['role_key'] ?? $this->context->primaryRole(),
                'dealer_id'      => array_key_exists('dealer_id', $actor) ? $actor['dealer_id'] : $this->context->dealerId(),
                'action'         => mb_substr($action, 0, 60),
                'entity'         => mb_substr($entity, 0, 60),
                'entity_id'      => $entityId === null ? null : mb_substr($entityId, 0, 64),
                'ip'             => $this->context->ip(),
                'user_agent'     => $this->context->userAgent(),
                'previous_value' => $previous === null ? null : self::encode($previous),
                'new_value'      => $new === null ? null : self::encode($new),
                'reason'         => $reason,
                'request_id'     => $this->context->requestId,
            ];

            // The lock that makes the chain safe under concurrency.
            $head = $db->query('SELECT last_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE')->getRow();

            $row['prev_hash'] = $head->last_hash ?? null;
            $row['row_hash']  = AuditChain::hash($row['prev_hash'], $row);

            $db->table('audit_logs')->insert($row);
            $id = $db->insertID();

            $db->query(
                'INSERT INTO audit_chain_head (id, last_audit_id, last_hash) VALUES (1, ?, ?)
                   ON DUPLICATE KEY UPDATE last_audit_id = VALUES(last_audit_id), last_hash = VALUES(last_hash)',
                [$id, $row['row_hash']],
            );
        };

        $this->db->transDepth > 0 ? $work($this->db) : Tx::run($work, $this->db);
    }

    /**
     * Point-in-time snapshot of a record before it changes, numbered per entity.
     *
     * @param array<string,mixed> $data
     */
    public function version(string $entity, string $entityId, array $data, ?string $reason = null): void
    {
        $latest = $this->db->table('record_versions')
            ->selectMax('version', 'v')->where(['entity' => $entity, 'entity_id' => $entityId])
            ->get()->getRow();

        $this->db->table('record_versions')->insert([
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'version'    => (int) ($latest->v ?? 0) + 1,
            'data'       => self::encode($data),
            'changed_by' => $this->context->userId(),
            'changed_at' => utc_now(),
            'reason'     => $reason,
        ]);
    }

    /** @param array<string,mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode(self::sanitise($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function sanitise(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '[truncated]';
        }
        if (is_array($value)) {
            $out   = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if (++$count > 50) {
                    break;
                }
                $out[$key] = is_string($key) && in_array($key, self::SENSITIVE_KEYS, true)
                    ? '[redacted]'
                    : self::sanitise($item, $depth + 1);
            }

            return $out;
        }
        if (is_string($value) && mb_strlen($value) > 2000) {
            return mb_substr($value, 0, 2000) . '…[truncated]';
        }

        return $value;
    }
}

<?php

namespace App\Services\Audit;

use App\Services\Support\Clock;
use App\Services\Support\RequestContext;
use App\Core\Database;

/**
 * Append-only audit trail: who (user, employee), what (action, module, record),
 * previous / new values, IP, user agent, date and time.
 *
 * Call it inside the same transaction as the change, so the change and its audit
 * entry commit or roll back together. Secrets are always redacted.
 */
class AuditService
{
    private const REDACT = ['password', 'password_hash', 'new_password', 'current_password', 'security_stamp',
        'token', 'secret', 'credentials', 'private_key', 'idem_key'];

    /** @var array{id: int, username: string, employee_id: int|null}|null */
    private ?array $actor = null;

    public function __construct(
        private readonly Database $db,
        private readonly RequestContext $context,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Sets the acting user for this request (done by the authentication filter / CLI commands).
     *
     * @param array{id: int, username: string, employee_id: int|null}|null $actor
     */
    public function setActor(?array $actor): void
    {
        $this->actor = $actor;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function log(string $action, string $module, int|string|null $recordId = null, ?array $before = null, ?array $after = null, ?string $recordRef = null): void
    {
        [$before, $after] = $this->diff($before, $after);

        $this->db->table('audit_logs')->insert([
            'user_id'        => $this->actor['id'] ?? null,
            'employee_id'    => $this->actor['employee_id'] ?? null,
            'username'       => $this->actor['username'] ?? null,
            'action'         => mb_substr(strtoupper($action), 0, 40),
            'module'         => mb_substr($module, 0, 40),
            'record_id'      => $recordId === null ? null : (int) $recordId,
            'record_ref'     => $recordRef === null ? null : mb_substr($recordRef, 0, 60),
            'previous_value' => $before === null ? null : $this->json($before),
            'new_value'      => $after === null ? null : $this->json($after),
            'ip_address'     => mb_substr($this->context->ip(), 0, 45),
            'user_agent'     => $this->context->userAgent(),
            'request_id'     => $this->context->requestId(),
            'created_at'     => $this->clock->nowUtcString(),
        ]);
    }

    /**
     * For updates only the changed keys are kept; secrets are redacted.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    public function diff(?array $before, ?array $after): array
    {
        if ($before !== null && $after !== null) {
            $changedBefore = [];
            $changedAfter  = [];
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                $old = $before[$key] ?? null;
                $new = $after[$key] ?? null;
                if ((string) json_encode($old) !== (string) json_encode($new) && ! $this->sameScalar($old, $new)) {
                    $changedBefore[$key] = $old;
                    $changedAfter[$key]  = $new;
                }
            }
            $before = $changedBefore;
            $after  = $changedAfter;
        }

        return [$this->redact($before), $this->redact($after)];
    }

    private function sameScalar(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) || $a === null) {
            if (is_scalar($b) || $b === null) {
                return (string) $a === (string) $b;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $values
     *
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function json(array $values): string
    {
        return (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    }
}

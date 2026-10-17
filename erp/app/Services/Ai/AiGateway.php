<?php

namespace App\Services\Ai;

use CodeIgniter\Database\BaseConnection;

/**
 * The only door between DGR Office data and any AI. The AI is a service
 * identity, not a user: it never gets SQL, ORM, generic search/export,
 * files, .env, backups or credentials. Each request is checked against:
 *
 *  1. the capability switch (all capabilities are OFF by default);
 *  2. whether data may be shared with the external provider;
 *  3. the per-capability field allowlist;
 *  4. subject consent where required;
 *  5. record scope (token allowlist / capability scope config);
 *  6. hourly rate limit and monthly cost ceiling (0 = blocked).
 *
 * An approved request gets a short-lived grant; every decision is written to
 * ai_action_logs with field NAMES and record ids only — never contents.
 */
class AiGateway
{
    public const GRANT_TTL_MINUTES = 15;

    /** @var array<string, mixed>|null token authenticated by AiTokenFilter for this request */
    private ?array $token = null;

    public function __construct(private readonly BaseConnection $db, private readonly AiConfig $config)
    {
    }

    /** @return array<string, mixed>|null */
    public function capability(string $code): ?array
    {
        $c = $this->db->table('ai_capabilities')->where('code', $code)->get()->getRowArray();
        if ($c !== null) {
            $c['allowed_fields'] = json_decode((string) $c['allowed_fields'], true) ?: [];
            $c['scope_config']   = json_decode((string) $c['scope_config'], true) ?: [];
        }

        return $c;
    }

    public function enabled(string $code): bool
    {
        return (int) ($this->capability($code)['enabled'] ?? 0) === 1;
    }

    /**
     * @param list<int|string> $recordIds
     * @param list<string>     $fields
     */
    public function log(string $outcome, ?string $capability, ?string $recordType, array $recordIds, array $fields = [], array $extra = []): int
    {
        $this->db->table('ai_action_logs')->insert([
            'occurred_at' => date('Y-m-d H:i:s'), 'capability' => $capability, 'token_id' => $extra['token_id'] ?? ($this->token['id'] ?? null),
            'grant_id' => $extra['grant_id'] ?? null, 'actor_user_id' => $extra['actor_user_id'] ?? service('authz')->currentUserId(),
            'record_type' => $recordType, 'record_ids' => json_encode(array_values($recordIds)), 'field_names' => json_encode(array_values($fields)),
            'provider' => $extra['provider'] ?? null, 'model' => $extra['model'] ?? null, 'outcome' => $outcome,
            'deny_reason' => isset($extra['deny_reason']) ? mb_substr($extra['deny_reason'], 0, 255) : null,
            'http_path' => isset($extra['http_path']) ? mb_substr($extra['http_path'], 0, 255) : (is_cli() ? null : mb_substr(service('request')->getUri()->getPath(), 0, 255)),
            'input_tokens' => $extra['input_tokens'] ?? null, 'output_tokens' => $extra['output_tokens'] ?? null,
            'cost_estimate' => $extra['cost'] ?? null, 'duration_ms' => $extra['duration_ms'] ?? null,
        ]);

        return (int) $this->db->insertID();
    }

    private function deny(string $reason, string $message, ?string $capability, ?string $recordType, array $ids, array $fields = []): never
    {
        $this->log('denied', $capability, $recordType, $ids, $fields, ['deny_reason' => $reason]);

        throw new AiDeniedException($message, $reason);
    }

    public function usedThisHour(string $capability): int
    {
        return $this->db->table('ai_action_logs')->where('capability', $capability)->whereIn('outcome', ['allowed'])
            ->where('occurred_at >', date('Y-m-d H:i:s', time() - 3600))->countAllResults();
    }

    public function costThisMonth(string $capability): string
    {
        return (string) ($this->db->query("SELECT COALESCE(SUM(cost_estimate), 0) c FROM ai_action_logs WHERE capability = ? AND occurred_at >= ?", [$capability, date('Y-m-01 00:00:00')])->getRow()->c ?? '0');
    }

    /**
     * Policy check shared by in-app requests and token API calls. Returns a
     * human-readable reason when the request may not proceed.
     *
     * @param list<string> $fields
     */
    public function check(string $code, array $fields, bool $external, ?bool $consent, int $recordId = 0, string $scopeKey = ''): ?array
    {
        $cap = $this->capability($code);
        if ($cap === null || (int) $cap['enabled'] !== 1) {
            return ['capability_disabled', 'This AI capability is switched off (AI Permissions page).'];
        }
        if ($external && (int) $cap['share_with_provider'] !== 1) {
            return ['sharing_not_allowed', 'Sharing this data with the external AI provider is not allowed by the AI policy.'];
        }
        if ($external && $this->token === null && ! $this->config->isConfigured()) {
            return ['provider_not_configured', 'No AI provider is configured on this server.'];
        }
        $extra = array_diff($fields, $cap['allowed_fields']);
        if ($extra !== []) {
            return ['field_not_allowed', 'Requested fields are outside this capability\'s allowlist: ' . implode(', ', $extra)];
        }
        if ((int) $cap['requires_consent'] === 1 && $consent !== true) {
            return ['consent_missing', 'The data subject has not consented to this AI use.'];
        }
        if ($scopeKey !== '' && isset($cap['scope_config'][$scopeKey]) && ! in_array($recordId, array_map('intval', (array) $cap['scope_config'][$scopeKey]), true)) {
            return ['out_of_scope', 'This record is outside the capability\'s configured scope.'];
        }
        if ((float) $cap['monthly_cost_ceiling'] <= 0) {
            return ['no_budget', 'The monthly AI cost ceiling for this capability is 0, so it is blocked. Set a ceiling on the AI Permissions page.'];
        }
        if ((float) $this->costThisMonth($code) >= (float) $cap['monthly_cost_ceiling']) {
            return ['budget_exhausted', 'This month\'s AI cost ceiling for this capability has been reached.'];
        }
        if ($this->usedThisHour($code) >= (int) $cap['rate_limit_per_hour']) {
            return ['rate_limited', 'Hourly AI request limit reached for this capability. Try again later.'];
        }

        return null;
    }

    /**
     * Approve one scoped request and issue a short-lived grant.
     *
     * @param list<string> $fields
     *
     * @return array<string, mixed> grant row
     *
     * @throws AiDeniedException
     */
    public function authorize(string $code, string $recordType, int $recordId, array $fields, string $purpose, ?bool $consent, bool $external = true, string $scopeKey = ''): array
    {
        $why = $this->check($code, $fields, $external, $consent, $recordId, $scopeKey);
        if ($why !== null) {
            $this->deny($why[0], $why[1], $code, $recordType, [$recordId], $fields);
        }
        $this->db->table('ai_access_grants')->insert([
            'capability' => $code, 'token_id' => $this->token['id'] ?? null, 'record_type' => $recordType, 'record_id' => $recordId,
            'allowed_fields' => json_encode(array_values($fields)), 'purpose' => mb_substr($purpose, 0, 255),
            'requested_by' => service('authz')->currentUserId() ?? ($this->token['created_by'] ?? null),
            'expires_at' => date('Y-m-d H:i:s', time() + self::GRANT_TTL_MINUTES * 60), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $grant = $this->db->table('ai_access_grants')->where('id', $this->db->insertID())->get()->getRowArray();
        $this->log('allowed', $code, $recordType, [$recordId], $fields, ['grant_id' => (int) $grant['id'], 'provider' => $external ? $this->config->provider() : null, 'model' => $external ? $this->config->model() : null]);

        return $grant;
    }

    /** Close a grant and record the outcome, tokens and estimated cost (no content). */
    public function finish(array $grant, string $outcome, array $usage = []): void
    {
        $this->db->table('ai_access_grants')->where('id', $grant['id'])->update(['consumed_at' => date('Y-m-d H:i:s')]);
        $cost = isset($usage['input_tokens']) ? $this->config->costEstimate((string) ($usage['model'] ?? $this->config->model()), (int) $usage['input_tokens'], (int) ($usage['output_tokens'] ?? 0)) : null;
        $this->log($outcome, $grant['capability'], $grant['record_type'], [(int) $grant['record_id']], json_decode((string) $grant['allowed_fields'], true) ?: [], [
            'grant_id' => (int) $grant['id'], 'provider' => $usage['provider'] ?? null, 'model' => $usage['model'] ?? null,
            'input_tokens' => $usage['input_tokens'] ?? null, 'output_tokens' => $usage['output_tokens'] ?? null, 'cost' => $cost,
            'duration_ms' => $usage['duration_ms'] ?? null, 'deny_reason' => $usage['error'] ?? null,
        ]);
    }

    /** A grant that is still open, unexpired and (for token callers) owned by this token. */
    public function openGrant(int $grantId, string $code, string $recordType, int $recordId): ?array
    {
        $g = $this->db->table('ai_access_grants')->where(['id' => $grantId, 'capability' => $code, 'record_type' => $recordType, 'record_id' => $recordId])
            ->where('consumed_at', null)->where('expires_at >', date('Y-m-d H:i:s'))->get()->getRowArray();
        if ($g !== null && $this->token !== null && (int) $g['token_id'] !== (int) $this->token['id']) {
            return null;
        }

        return $g;
    }

    // ---- service tokens -------------------------------------------------------

    /** @return array{0: string, 1: int} [plain token shown once, token id] */
    public function issueToken(array $capabilities, array $scope, string $purpose, int $ttlHours, ?int $maxUses): array
    {
        service('authz')->authorize('ai.permissions.manage');
        $known = array_keys(config('Permissions')->aiCapabilities);
        $caps  = array_values(array_intersect($capabilities, $known));
        if ($caps === [] || trim($purpose) === '') {
            throw new \App\Exceptions\BusinessRuleException('Choose at least one capability and state the purpose.');
        }
        $account = $this->db->table('ai_service_accounts')->where('is_active', 1)->where('revoked_at', null)->orderBy('id')->get(1)->getRowArray()
            ?? throw new \App\Exceptions\BusinessRuleException('The AI service account is disabled.');
        $plain = 'dgrai_' . bin2hex(random_bytes(24));
        $this->db->table('ai_service_tokens')->insert([
            'service_account_id' => $account['id'], 'token_hash' => hash('sha256', $plain), 'token_hint' => substr($plain, -8),
            'capabilities' => json_encode($caps), 'scope' => json_encode($scope), 'purpose' => mb_substr(trim($purpose), 0, 255),
            'expires_at' => date('Y-m-d H:i:s', time() + max(1, min(72, $ttlHours)) * 3600), 'max_uses' => $maxUses ?: null,
            'created_by' => service('authz')->currentUserId(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();
        service('audit')->log('ai.token_issued', 'ai_service_token', $id, null, ['capabilities' => $caps, 'scope' => $scope, 'ttl_hours' => $ttlHours, 'max_uses' => $maxUses], 'AI service token issued');

        return [$plain, $id];
    }

    public function revokeToken(int $id): void
    {
        service('authz')->authorize('ai.permissions.manage');
        $this->db->table('ai_service_tokens')->where('id', $id)->where('revoked_at', null)->update(['revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => service('authz')->currentUserId()]);
        service('audit')->log('ai.token_revoked', 'ai_service_token', $id, null, null, 'AI service token revoked');
    }

    /**
     * Authenticate a bearer token for /api/ai/*. Returns the token row or a
     * denial reason; usage is counted only for valid tokens.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function authenticate(string $plain): array
    {
        if (! preg_match('/^dgrai_[a-f0-9]{48}$/', $plain)) {
            return [null, 'malformed_token'];
        }
        $t = $this->db->table('ai_service_tokens t')->select('t.*, a.is_active AS account_active, a.revoked_at AS account_revoked')
            ->join('ai_service_accounts a', 'a.id = t.service_account_id')->where('t.token_hash', hash('sha256', $plain))->get()->getRowArray();
        $why = match (true) {
            $t === null => 'unknown_token',
            $t['revoked_at'] !== null => 'token_revoked',
            $t['expires_at'] <= date('Y-m-d H:i:s') => 'token_expired',
            $t['max_uses'] !== null && (int) $t['use_count'] >= (int) $t['max_uses'] => 'token_exhausted',
            (int) $t['account_active'] !== 1 || $t['account_revoked'] !== null => 'account_disabled',
            default => null,
        };
        if ($why !== null) {
            return [null, $why];
        }
        $this->db->query('UPDATE ai_service_tokens SET use_count = use_count + 1, last_used_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $t['id']]);
        $t['capabilities'] = json_decode((string) $t['capabilities'], true) ?: [];
        $t['scope']        = json_decode((string) $t['scope'], true) ?: [];
        $this->token       = $t;

        return [$t, null];
    }

    /** @return array<string, mixed>|null */
    public function currentToken(): ?array
    {
        return $this->token;
    }

    /** Token holds the capability, the capability is on, and the record is in the token's allowlist. */
    public function tokenAllows(string $code, string $scopeKey, ?int $recordId): ?string
    {
        $t = $this->token;
        if ($t === null) {
            return 'no_token';
        }
        if (! in_array($code, $t['capabilities'], true)) {
            return 'capability_not_granted_to_token';
        }
        if (! $this->enabled($code)) {
            return 'capability_disabled';
        }
        if ($recordId !== null && ! in_array($recordId, array_map('intval', (array) ($t['scope'][$scopeKey] ?? [])), true)) {
            return 'record_out_of_token_scope';
        }

        return null;
    }
}

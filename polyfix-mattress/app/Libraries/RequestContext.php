<?php

namespace App\Libraries;

/**
 * Who is acting in this request, and from where.
 *
 * Filled by AuthFilter from the server-side session record — never from
 * anything the browser sent. Every service reads the actor from here, which is
 * what makes "the dealer can only act for their own dealership" a property of
 * the request rather than of each form.
 *
 * Shared for the lifetime of one request: service('requestContext').
 */
final class RequestContext
{
    public readonly string $requestId;

    private ?array $user = null;

    public function __construct()
    {
        $this->requestId = bin2hex(random_bytes(8));
    }

    /**
     * @param array{id:string, email:string, full_name:string, roles:list<string>, dealer_id:?string,
     *              mfa_enabled:bool, mfa_satisfied:bool, must_change_password:bool, session_id:string} $user
     */
    public function signIn(array $user): void
    {
        $user['permissions'] = Rbac::permissionsForRoles($user['roles']);
        $this->user          = $user;
    }

    public function signOut(): void
    {
        $this->user = null;
    }

    public function isSignedIn(): bool
    {
        return $this->user !== null;
    }

    public function user(): ?array
    {
        return $this->user;
    }

    public function userId(): ?string
    {
        return $this->user['id'] ?? null;
    }

    public function email(): ?string
    {
        return $this->user['email'] ?? null;
    }

    public function sessionId(): ?string
    {
        return $this->user['session_id'] ?? null;
    }

    /** The dealer this user acts for, or null for staff. */
    public function dealerId(): ?string
    {
        return $this->user['dealer_id'] ?? null;
    }

    public function isDealer(): bool
    {
        return $this->dealerId() !== null;
    }

    public function isStaff(): bool
    {
        return $this->user !== null && Rbac::isStaff($this->user['roles']) && $this->dealerId() === null;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->user['roles'] ?? [];
    }

    public function primaryRole(): ?string
    {
        return $this->user['roles'][0] ?? null;
    }

    /**
     * True only if the role grants the permission AND, for MFA-gated
     * permissions, two-factor authentication was satisfied in this session.
     */
    public function can(string $permission): bool
    {
        if ($this->user === null || ! in_array($permission, $this->user['permissions'], true)) {
            return false;
        }
        if (in_array($permission, Rbac::MFA_GATED, true)) {
            return $this->user['mfa_enabled'] && $this->user['mfa_satisfied'];
        }

        return true;
    }

    /** Holds the permission by role, whatever the MFA state. For explaining refusals. */
    public function hasRolePermission(string $permission): bool
    {
        return $this->user !== null && in_array($permission, $this->user['permissions'], true);
    }

    public function ip(): string
    {
        return client_ip();
    }

    public function userAgent(): ?string
    {
        $request = service('request');
        if (! $request instanceof \CodeIgniter\HTTP\IncomingRequest) {
            return 'spark (command line)';
        }
        $agent = (string) $request->getUserAgent();

        return $agent === '' ? null : mb_substr($agent, 0, 400);
    }
}

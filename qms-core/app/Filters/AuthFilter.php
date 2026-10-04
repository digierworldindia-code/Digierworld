<?php

declare(strict_types=1);

namespace App\Filters;

use App\Core\FilterInterface;
use App\Core\Request;
use App\Core\Response;

/**
 * Requires a logged-in, active user whose security stamp still matches; applies
 * idle / absolute timeouts and the forced password change.
 */
class AuthFilter implements FilterInterface
{
    use FilterResponses;

    private const PASSWORD_PATHS = ['account/password', 'logout'];

    public function before(Request $request, ?array $arguments = null): ?Response
    {
        $auth = service('auth');
        $user = $auth->user();

        if ($user === null) {
            return $this->toLogin($request, 'Please log in.');
        }

        if (! $auth->touch()) {
            return $this->toLogin($request, 'Your session expired. Please log in again.');
        }

        $path = trim($request->getUri()->getPath(), '/');
        if ($this->mustChangePassword($user) && ! in_array($path, self::PASSWORD_PATHS, true)) {
            if ($this->wantsJson($request)) {
                return $this->errorResponse($request, 403, 'You must change your password first.');
            }

            return redirect()->to(site_url('account/password'));
        }

        return null;
    }

    public function after(Request $request, Response $response, ?array $arguments = null): ?Response
    {
        return null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function mustChangePassword(array $user): bool
    {
        if ((int) $user['must_change_password'] === 1) {
            return true;
        }

        $days = service('settings')->int('security.password_expiry_days', 0);
        if ($days <= 0) {
            return false;
        }
        $changed = (string) ($user['password_changed_at'] ?? '');

        return $changed === '' || $changed < service('clock')->nowUtc()->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    private function toLogin(Request $request, string $message): Response
    {
        if ($this->wantsJson($request)) {
            return $this->errorResponse($request, 401, $message, ['login' => site_url('login')]);
        }

        if ($request->getMethod() === 'GET') {
            $target = '/' . ltrim($request->getUri()->getPath(), '/');
            $query  = $request->getUri()->getQuery();
            session()->set('qms_intended', $query !== '' ? $target . '?' . $query : $target);
        }

        return redirect()->to(site_url('login'))->with('info', $message);
    }
}

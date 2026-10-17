<?php

namespace App\Filters;

use App\Exceptions\AccessDeniedException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Runs after Shield's `session` filter on every authenticated route:
 *  - blocks AI/service accounts from interactive sessions
 *  - tracks the session in user_sessions and enforces immediate revocation
 *  - enforces account deactivation and forced password change
 */
class SessionGuardFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $auth = auth('session');
        if (! $auth->loggedIn()) {
            return null; // Shield's session filter handles redirects
        }
        $userId = (int) $auth->id();
        $db     = db_connect();

        $profile = $db->table('user_profiles')->where('user_id', $userId)->get()->getRowArray();
        $user    = $db->table('users')->select('active, deleted_at')->where('id', $userId)->get()->getRowArray();

        if ($profile !== null && (int) $profile['is_service_account'] === 1) {
            $auth->logout();

            throw new AccessDeniedException('Service accounts cannot sign in interactively.');
        }
        if ($user === null || (int) $user['active'] !== 1 || $user['deleted_at'] !== null) {
            $auth->logout();

            return redirect()->to(site_url('login'))->with('error', 'Your account is disabled. Contact the administrator.');
        }

        $sid = session_id();
        if ($sid !== false && $sid !== '') {
            $row = $db->table('user_sessions')->where('session_id', $sid)->get()->getRowArray();
            $now = date('Y-m-d H:i:s');
            if ($row === null) {
                $db->table('user_sessions')->insert([
                    'user_id'          => $userId,
                    'session_id'       => $sid,
                    'ip_address'       => $request->getIPAddress(),
                    'user_agent'       => mb_substr((string) $request->getUserAgent(), 0, 255),
                    'last_activity_at' => $now,
                ]);
            } elseif ($row['revoked_at'] !== null || (int) $row['user_id'] !== $userId) {
                $auth->logout();
                session()->destroy();

                return redirect()->to(site_url('login'))->with('error', 'Your session was ended by an administrator. Please sign in again.');
            } elseif ($row['last_activity_at'] === null || strtotime($row['last_activity_at']) < time() - 60) {
                $db->table('user_sessions')->where('id', $row['id'])->update(['last_activity_at' => $now]);
                $db->table('user_profiles')->where('user_id', $userId)->update(['last_seen_at' => $now]);
            }
        }

        if ($profile !== null && (int) $profile['must_change_password'] === 1) {
            $path = trim($request->getUri()->getPath(), '/');
            if (! in_array($path, ['profile/password', 'logout'], true)) {
                return redirect()->to(site_url('profile/password'))->with('warning', 'Please set a new password before continuing.');
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

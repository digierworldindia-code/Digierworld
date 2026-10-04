<?php

declare(strict_types=1);

namespace App\Config;

use App\Core\Env;

/**
 * Session cookie. On HTTPS the cookie is Secure and named __Host-qms_session
 * (bound to this exact host, path /). Idle and absolute session limits are
 * business settings (Admin → Settings → Security); $expiration is the upper bound.
 */
final class Session
{
    public string $cookieName;

    public bool $secure;

    public string $cookiePath;

    /** Seconds a session row may stay unused before it is garbage-collected. */
    public int $expiration = 43200;

    public function __construct()
    {
        $base         = config('App')->baseURL;
        $this->secure = Env::bool('COOKIE_SECURE', str_starts_with(strtolower($base), 'https://'));

        $name = (string) Env::get('SESSION_COOKIE', '');
        $name = preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name) ? $name : ($this->secure ? '__Host-qms_session' : 'qms_session');
        if (str_starts_with($name, '__Host-') && ! $this->secure) {
            $name = substr($name, 7); // browsers reject __Host- cookies without Secure
        }
        $this->cookieName = $name;

        $path             = '/' . trim((string) parse_url($base, PHP_URL_PATH), '/');
        $this->cookiePath = str_starts_with($name, '__Host-') ? '/' : rtrim($path, '/') . '/';
    }
}

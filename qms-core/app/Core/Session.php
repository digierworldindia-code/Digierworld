<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session access with flash data. Started lazily on first use, so public
 * endpoints (health check, logo) never create sessions.
 *
 * Cookie: HttpOnly, SameSite=Lax, Secure on HTTPS (name __Host-qms_session),
 * no session id in URLs, strict mode, id regenerated on login and logout.
 * Under the CLI (cron scripts, tests) $_SESSION is a plain array.
 */
final class Session
{
    private const FLASH = '__qms_flash';

    private bool $started = false;

    public function __construct(private readonly ?Database $db = null)
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        if (is_cli() || $this->db === null) {
            if (! isset($_SESSION) || ! is_array($_SESSION)) {
                $_SESSION = [];
            }
            $this->ageFlashdata();

            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->ageFlashdata();

            return;
        }

        $config = config('Session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.lazy_write', '1');
        ini_set('session.serialize_handler', 'php_serialize');
        ini_set('session.gc_maxlifetime', (string) $config->expiration);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        session_name($config->cookieName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $config->cookiePath,
            'domain'   => '',
            'secure'   => $config->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_set_save_handler(new DbSessionHandler($this->db, service('request')->getIPAddress()), true);

        if (! @session_start()) {
            throw new HttpException(503, 'The server is busy. Please try again in a moment.');
        }
        $this->ageFlashdata();
    }

    public function get(?string $key = null): mixed
    {
        $this->start();
        if ($key === null) {
            $all = $_SESSION;
            unset($all[self::FLASH]);

            return $all;
        }

        return $_SESSION[$key] ?? null;
    }

    public function has(string $key): bool
    {
        $this->start();

        return isset($_SESSION[$key]);
    }

    /**
     * @param array<string, mixed>|string $key
     */
    public function set(array|string $key, mixed $value = null): void
    {
        $this->start();
        foreach (is_array($key) ? $key : [$key => $value] as $k => $v) {
            $_SESSION[$k] = $v;
        }
    }

    /**
     * @param list<string>|string $key
     */
    public function remove(array|string $key): void
    {
        $this->start();
        foreach ((array) $key as $k) {
            unset($_SESSION[$k], $_SESSION[self::FLASH][$k]);
        }
    }

    /** New session id; the old session row is deleted when $destroy is true. */
    public function regenerate(bool $destroy = false): void
    {
        $this->start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($destroy);
        }
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->started = false;
    }

    /**
     * Value available on the next request only.
     *
     * @param array<string, mixed>|string $key
     */
    public function setFlashdata(array|string $key, mixed $value = null): void
    {
        $this->start();
        foreach (is_array($key) ? $key : [$key => $value] as $k => $v) {
            $_SESSION[$k]               = $v;
            $_SESSION[self::FLASH][$k] = 'new';
        }
    }

    public function getFlashdata(?string $key = null): mixed
    {
        $this->start();
        $flash = $_SESSION[self::FLASH] ?? [];
        if ($key === null) {
            $out = [];
            foreach (array_keys($flash) as $k) {
                if (array_key_exists($k, $_SESSION)) {
                    $out[$k] = $_SESSION[$k];
                }
            }

            return $out;
        }

        return isset($flash[$key]) ? ($_SESSION[$key] ?? null) : null;
    }

    public function keepFlashdata(string $key): void
    {
        $this->start();
        if (isset($_SESSION[self::FLASH][$key])) {
            $_SESSION[self::FLASH][$key] = 'new';
        }
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /** Flash values set by the previous request become readable; older ones are dropped. */
    private function ageFlashdata(): void
    {
        $flash = $_SESSION[self::FLASH] ?? [];
        if (! is_array($flash) || $flash === []) {
            unset($_SESSION[self::FLASH]);

            return;
        }
        foreach ($flash as $key => $state) {
            if ($state === 'old') {
                unset($_SESSION[$key], $flash[$key]);
            } else {
                $flash[$key] = 'old';
            }
        }
        if ($flash === []) {
            unset($_SESSION[self::FLASH]);
        } else {
            $_SESSION[self::FLASH] = $flash;
        }
    }
}

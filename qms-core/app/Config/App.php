<?php

declare(strict_types=1);

namespace App\Config;

use App\Core\Env;

/**
 * Site settings from .env: APP_URL (public address, trailing slash added),
 * APP_FORCE_HTTPS and TRUSTED_PROXIES (reverse proxies / load balancers whose
 * X-Forwarded-For and X-Forwarded-Proto headers are believed).
 */
final class App
{
    public string $baseURL;

    public bool $forceGlobalSecureRequests;

    /** @var list<string> IPs or CIDR ranges */
    public array $proxyIPs;

    public function __construct()
    {
        $url = trim((string) Env::get('APP_URL', ''));
        if ($url === '' || ! preg_match('#^https?://[^/\s]+#i', $url)) {
            $url = self::detect();
        }
        $this->baseURL                   = rtrim($url, '/') . '/';
        $this->forceGlobalSecureRequests = Env::bool('APP_FORCE_HTTPS', str_starts_with(strtolower($this->baseURL), 'https://'));
        $this->proxyIPs                  = array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', '')))));
    }

    /**
     * Fallback before APP_URL is configured (first run of the setup page):
     * scheme, host and folder of the current request.
     */
    private static function detect(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'http://localhost:8080/';
        }
        $https  = ! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host   = preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) ? $host : 'localhost';
        $folder = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        $folder = preg_replace('#/public$#', '', rtrim($folder, '/')) ?? '';

        return ($https ? 'https' : 'http') . '://' . $host . $folder . '/';
    }
}

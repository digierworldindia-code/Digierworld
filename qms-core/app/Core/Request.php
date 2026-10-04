<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;

/**
 * The current HTTP request (method, route path, input, headers, files).
 *
 * The route path is computed relative to APP_URL, so the application works in
 * a sub-folder and behind the shared-hosting rewrite to public/.
 */
final class Request
{
    private ?string $path = null;

    /** @var array<string, string>|null */
    private ?array $headers = null;

    /** @var array<string, mixed>|null */
    private ?array $oldInput = null;

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function __construct(
        private readonly array $server,
        private readonly array $get = [],
        private readonly array $post = [],
        private readonly array $files = [],
        private readonly string $body = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $body   = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? (string) file_get_contents('php://input') : '';

        return new self($server, $_GET, $_POST, $_FILES, $body);
    }

    /** HTTP method in upper case (GET, POST …). */
    public function getMethod(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Route path without leading/trailing slash ('' for the start page). */
    public function getPath(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        $uri  = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) (parse_url('http://x' . (str_starts_with($uri, '/') ? $uri : '/' . $uri), PHP_URL_PATH) ?? '/'));
        $base = (string) (parse_url(config('App')->baseURL, PHP_URL_PATH) ?? '/');
        $base = '/' . trim($base, '/');

        if ($base !== '/' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }
        // Front controller in the URL (no rewrite available): /index.php/login
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }

        return $this->path = trim($path, '/');
    }

    public function getUri(): Uri
    {
        return new Uri($this->getPath(), (string) ($this->server['QUERY_STRING'] ?? http_build_query($this->get)));
    }

    /**
     * getGet('name'), getGet(['a', 'b']) (missing keys → null) or getGet() (all).
     *
     * @param list<string>|string|null $key
     */
    public function getGet(array|string|null $key = null): mixed
    {
        if ($key === null) {
            return $this->get;
        }
        if (is_array($key)) {
            // List filters are plain values; ?q[]=x style arrays are ignored.
            $out = [];
            foreach ($key as $name) {
                $value      = $this->get[$name] ?? null;
                $out[$name] = is_array($value) ? null : $value;
            }

            return $out;
        }

        return $this->get[$key] ?? null;
    }

    /**
     * getPost('name'), getPost(['a', 'b']) (missing keys → null) or getPost() (all).
     * A JSON request body is not merged here; use getJSON().
     *
     * @param list<string>|string|null $key
     */
    public function getPost(array|string|null $key = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }
        if (is_array($key)) {
            $out = [];
            foreach ($key as $name) {
                $out[$name] = $this->post[$name] ?? null;
            }

            return $out;
        }

        return $this->post[$key] ?? null;
    }

    public function getVar(string $key): mixed
    {
        return $this->post[$key] ?? $this->get[$key] ?? null;
    }

    /**
     * Decoded JSON body. Invalid JSON throws a 400 error.
     */
    public function getJSON(bool $assoc = false): mixed
    {
        if ($this->body === '') {
            return null;
        }

        try {
            return json_decode($this->body, $assoc, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new HttpException(400, 'The request body is not valid JSON.');
        }
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers()[strtolower($name)] ?? '';
    }

    /**
     * @return array<string, string> lower-case name => value
     */
    public function headers(): array
    {
        if ($this->headers !== null) {
            return $this->headers;
        }

        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($this->server[$key])) {
                $headers[$name] = (string) $this->server[$key];
            }
        }

        return $this->headers = $headers;
    }

    public function isAJAX(): bool
    {
        return strtolower($this->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        return $this->isAJAX() || str_contains($this->getHeaderLine('Accept'), 'application/json');
    }

    public function isCLI(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /**
     * Client IP. X-Forwarded-For is only believed when the direct peer is one
     * of TRUSTED_PROXIES (comma-separated IPs or CIDR ranges).
     */
    public function getIPAddress(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (! $this->fromTrustedProxy()) {
            return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
        }

        $chain = array_reverse(array_map('trim', explode(',', $this->getHeaderLine('X-Forwarded-For'))));
        foreach ($chain as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (! self::ipInRanges($candidate, config('App')->proxyIPs)) {
                return $candidate;
            }
        }

        return $remote;
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        return $this->fromTrustedProxy() && strtolower($this->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }

    public function getUserAgent(): string
    {
        return $this->getHeaderLine('User-Agent');
    }

    public function getFile(string $name): ?UploadedFile
    {
        $file = $this->files[$name] ?? null;
        if (! is_array($file) || ! isset($file['error']) || is_array($file['error'])) {
            return null;
        }

        return new UploadedFile($file);
    }

    /**
     * Value from the previous request's input (after a failed form post).
     */
    public function getOldInput(string $key): mixed
    {
        if ($this->oldInput === null) {
            $old            = session()->getFlashdata('_qms_old_input');
            $this->oldInput = is_array($old) ? $old : [];
        }

        return $this->oldInput['post'][$key] ?? $this->oldInput['get'][$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function server(): array
    {
        return $this->server;
    }

    private function fromTrustedProxy(): bool
    {
        $proxies = config('App')->proxyIPs;

        return $proxies !== [] && self::ipInRanges((string) ($this->server['REMOTE_ADDR'] ?? ''), $proxies);
    }

    /**
     * @param list<string> $ranges
     */
    public static function ipInRanges(string $ip, array $ranges): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$subnet, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
            $net = @inet_pton((string) $subnet);
            if ($net === false || strlen($net) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($net) * 8 : max(0, min(strlen($net) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            $rest  = $bits % 8;
            if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if ((($packed[$bytes] ?? "\0") & $mask) === (($net[$bytes] ?? "\0") & $mask)) {
                return true;
            }
        }

        return false;
    }
}

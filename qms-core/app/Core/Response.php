<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response being built by the controller and middleware.
 */
class Response
{
    private const REASONS = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 307 => 'Temporary Redirect', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict', 413 => 'Payload Too Large', 419 => 'Page Expired',
        422 => 'Unprocessable Content', 423 => 'Locked', 429 => 'Too Many Requests', 500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    protected int $status = 200;

    /** @var array<string, array{0: string, 1: string}> lower-case name => [name, value] */
    protected array $headers = [];

    protected string $body = '';

    public function __construct()
    {
        $this->setHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function setStatusCode(int $code, string $reason = ''): static
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException("Invalid HTTP status {$code}");
        }
        $this->status = $code;

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getReasonPhrase(): string
    {
        return self::REASONS[$this->status] ?? '';
    }

    public function setHeader(string $name, string $value): static
    {
        // Header injection guard: no line breaks in names or values.
        if (preg_match('/[\r\n\0]/', $name . $value)) {
            throw new \InvalidArgumentException('Invalid header value.');
        }
        $this->headers[strtolower($name)] = [$name, $value];

        return $this;
    }

    public function removeHeader(string $name): static
    {
        unset($this->headers[strtolower($name)]);

        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers[strtolower($name)][1] ?? '';
    }

    /**
     * @return array<string, string> name => value
     */
    public function headers(): array
    {
        $out = [];
        foreach ($this->headers as [$name, $value]) {
            $out[$name] = $value;
        }

        return $out;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setContentType(string $mime, string $charset = 'UTF-8'): static
    {
        return $this->setHeader('Content-Type', $charset !== '' && (str_starts_with($mime, 'text/') || str_contains($mime, 'json')) ? "{$mime}; charset={$charset}" : $mime);
    }

    public function setJSON(mixed $data): static
    {
        $this->setHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        return $this;
    }

    /** Decoded JSON body (tests). */
    public function getJSON(): mixed
    {
        return json_decode($this->body, true);
    }

    public function send(): void
    {
        if (! headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as [$name, $value]) {
                header($name . ': ' . $value, true);
            }
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'HEAD' && $this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }
}

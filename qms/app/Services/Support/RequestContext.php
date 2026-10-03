<?php

namespace App\Services\Support;

/**
 * Per-request context for audit and logs: correlation id, client IP, user agent.
 */
class RequestContext
{
    private ?string $requestId = null;

    public function requestId(): string
    {
        return $this->requestId ??= bin2hex(random_bytes(16));
    }

    public function ip(): string
    {
        if (is_cli()) {
            return 'cli';
        }

        return (string) service('request')->getIPAddress();
    }

    public function userAgent(): string
    {
        if (is_cli()) {
            return 'spark';
        }

        $agent = (string) service('request')->getHeaderLine('User-Agent');

        return mb_substr($agent, 0, 255);
    }
}

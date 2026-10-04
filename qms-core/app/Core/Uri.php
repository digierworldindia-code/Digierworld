<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Path (relative to the application's base URL, with a leading slash) and
 * query string of the current request.
 */
final class Uri
{
    public function __construct(private readonly string $path, private readonly string $query = '')
    {
    }

    public function getPath(): string
    {
        return '/' . ltrim($this->path, '/');
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function __toString(): string
    {
        return site_url($this->path) . ($this->query !== '' ? '?' . $this->query : '');
    }
}

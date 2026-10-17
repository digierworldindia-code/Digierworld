<?php

namespace App\Services\Ai;

use RuntimeException;

/** Provider failure with a message that is safe to show HR (never contains keys or payloads). */
class AiProviderException extends RuntimeException
{
    public function __construct(string $message, private readonly bool $retryable = false)
    {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}

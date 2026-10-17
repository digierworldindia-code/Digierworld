<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A validation / business-rule violation that should be shown to the user
 * as a friendly message (e.g. "Place of supply is required before finalizing").
 */
class BusinessRuleException extends RuntimeException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(string $message, private array $errors = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}

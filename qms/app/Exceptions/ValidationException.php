<?php

namespace App\Exceptions;

/**
 * Business validation failed. Carries field => message errors for forms and JSON.
 */
class ValidationException extends QmsException
{
    protected int $httpStatus = 422;

    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => $message], $message);
    }
}

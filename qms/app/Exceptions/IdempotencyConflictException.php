<?php

namespace App\Exceptions;

class IdempotencyConflictException extends QmsException
{
    protected int $httpStatus = 422;

    public function __construct(string $message = 'This request key was already used with different data.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

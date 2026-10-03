<?php

namespace App\Exceptions;

class ConcurrencyException extends QmsException
{
    protected int $httpStatus = 409;

    public function __construct(string $message = 'Someone else changed this record. Reload and try again.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

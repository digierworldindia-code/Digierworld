<?php

namespace App\Exceptions;

class AuthorizationException extends QmsException
{
    protected int $httpStatus = 403;

    public function __construct(string $message = 'You are not allowed to perform this action.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

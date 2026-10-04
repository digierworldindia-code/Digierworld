<?php

namespace App\Exceptions;

class NotFoundException extends QmsException
{
    protected int $httpStatus = 404;

    public function __construct(string $message = 'The requested record was not found.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

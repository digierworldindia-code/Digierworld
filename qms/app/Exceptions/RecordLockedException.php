<?php

namespace App\Exceptions;

class RecordLockedException extends QmsException
{
    protected int $httpStatus = 423;

    public function __construct(string $message = 'This record is locked and can no longer be changed.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

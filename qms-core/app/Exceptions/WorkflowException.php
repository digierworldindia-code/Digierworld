<?php

namespace App\Exceptions;

class WorkflowException extends QmsException
{
    protected int $httpStatus = 409;

    public function __construct(string $message = 'This action is not possible in the current state.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

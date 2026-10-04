<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base class for expected business errors. The message is safe to show to the
 * user; the HTTP status tells controllers how to respond.
 */
class QmsException extends RuntimeException
{
    protected int $httpStatus = 400;

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

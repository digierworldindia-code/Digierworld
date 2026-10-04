<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\QmsException;

/**
 * An HTTP-level error with a safe message (bad request body, 404 …).
 */
final class HttpException extends QmsException
{
    /** Allowed method for 405 responses. */
    public ?string $allow = null;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->httpStatus = $status;
    }

    public static function notFound(string $message = 'The page you requested was not found.'): self
    {
        return new self(404, $message);
    }
}

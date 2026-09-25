<?php

namespace App\Exceptions;

use CodeIgniter\Exceptions\HTTPExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * A failure the person using the application should be told about.
 *
 * getMessage() is written for them: short, specific, and free of internals.
 * Anything that is NOT an AppException is treated as a fault — logged in full,
 * and shown to the visitor only as a generic error with a reference.
 *
 * It carries an HTTP status, so one thrown outside a form action (a dealer
 * opening another dealer's record, say) renders the matching error page rather
 * than a fault: CodeIgniter reads the exception code for HTTPExceptionInterface.
 */
class AppException extends RuntimeException implements HTTPExceptionInterface
{
    public function __construct(
        string $publicMessage,
        protected int $status = 422,
        /** Written to the log only, never shown. */
        protected ?string $internal = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function internal(): ?string
    {
        return $this->internal;
    }

    /** A domain rule refused the action: "This mattress has already been sold." */
    public static function rule(string $message, ?string $internal = null): self
    {
        return new self($message, 422, $internal);
    }

    /** Also used for "exists but is not yours": the caller learns nothing more. */
    public static function notFound(string $what, ?string $internal = null): self
    {
        return new self("We could not find that {$what}.", 404, $internal);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.', ?string $internal = null): self
    {
        return new self($message, 403, $internal);
    }
}

<?php

namespace App\Exceptions;

use CodeIgniter\Exceptions\HTTPExceptionInterface;
use RuntimeException;

/**
 * Thrown when the current identity lacks a permission or record scope.
 * Rendered as a generic 403 page/JSON that never echoes the protected data.
 */
class AccessDeniedException extends RuntimeException implements HTTPExceptionInterface
{
    public function __construct(string $message = 'You do not have permission to access this resource.')
    {
        parent::__construct($message, 403);
    }
}

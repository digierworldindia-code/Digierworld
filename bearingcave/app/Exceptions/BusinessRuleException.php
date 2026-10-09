<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a request violates a business rule (shown to the user as-is).
 */
class BusinessRuleException extends \RuntimeException
{
}

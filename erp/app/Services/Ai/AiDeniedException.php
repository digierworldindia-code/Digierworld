<?php

namespace App\Services\Ai;

use App\Exceptions\BusinessRuleException;

/** An AI request refused by the gateway (capability off, no consent, scope, budget, rate limit …). */
class AiDeniedException extends BusinessRuleException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Services\Ai;

/**
 * A model provider that returns schema-constrained JSON. Implementations
 * receive only the already-minimised, sanitised payload built by a
 * capability-specific service — never database handles or credentials.
 */
interface AiProvider
{
    public function name(): string;

    public function model(): string;

    /**
     * @param array<string, mixed> $schema JSON schema for the response
     *
     * @return array{data: array<string, mixed>, input_tokens: int, output_tokens: int, model: string}
     *
     * @throws AiProviderException
     */
    public function structured(string $system, string $user, array $schema, int $maxTokens = 8000): array;
}

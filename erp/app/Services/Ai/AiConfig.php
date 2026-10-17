<?php

namespace App\Services\Ai;

use App\Services\Settings\SettingsStore;

/**
 * Server-side AI provider configuration. Provider, model and limits can be
 * set on the AI Permissions page; the API key is read only from .env
 * (ai.apiKey) or the encrypted integration store, and is never returned to
 * views, logs or prompts. Only the Anthropic provider is implemented.
 */
class AiConfig
{
    public const PROVIDERS = ['anthropic' => 'Anthropic (Claude)'];

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /** USD per million tokens [input, output] for cost estimates; unknown models use the most expensive row. */
    public const PRICES = [
        'claude-fable-5-1' => ['10.00', '50.00'], 'claude-opus-5-5' => ['4.00', '20.00'], 'claude-opus-5' => ['5.00', '25.00'],
        'claude-sonnet-5-5' => ['2.00', '10.00'], 'claude-haiku-5-5' => ['0.10', '0.50'],
    ];

    /** Models that accept the server-side refusal fallback ("fallbacks: default"). */
    public const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    public function __construct(private readonly SettingsStore $settings)
    {
    }

    public function provider(): string
    {
        $p = (string) ($this->settings->integration('ai', 'provider') ?: env('ai.provider', 'anthropic'));

        return isset(self::PROVIDERS[$p]) ? $p : '';
    }

    public function model(): string
    {
        return (string) ($this->settings->integration('ai', 'model') ?: env('ai.model', '') ?: self::DEFAULT_MODEL);
    }

    public function baseUrl(): ?string
    {
        $u = (string) ($this->settings->integration('ai', 'base_url') ?: env('ai.baseUrl', ''));

        return $u !== '' ? $u : null;
    }

    public function apiKey(): string
    {
        return (string) (env('ai.apiKey', '') ?: $this->settings->integration('ai', 'api_key', ''));
    }

    public function hasKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function timeout(): int
    {
        return max(10, min(300, (int) ($this->settings->integration('ai', 'timeout') ?: env('ai.timeoutSeconds', 90))));
    }

    public function maxRetries(): int
    {
        return max(0, min(4, (int) ($this->settings->integration('ai', 'max_retries') ?? env('ai.maxRetries', 2))));
    }

    /** low | medium | high — reasoning effort for review requests. */
    public function effort(): string
    {
        $e = (string) ($this->settings->integration('ai', 'effort') ?: env('ai.effort', 'medium'));

        return in_array($e, ['low', 'medium', 'high'], true) ? $e : 'medium';
    }

    public function isConfigured(): bool
    {
        return $this->provider() !== '' && $this->model() !== '' && $this->hasKey();
    }

    public function costEstimate(string $model, int $in, int $out): string
    {
        [$pi, $po] = self::PRICES[$model] ?? ['10.00', '50.00'];

        return number_format(($in * (float) $pi + $out * (float) $po) / 1_000_000, 4, '.', '');
    }
}

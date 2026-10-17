<?php

namespace App\Services\Ai;

use App\Services\Settings\SettingsStore;

/**
 * Server-side AI provider configuration. Values come from the encrypted
 * integration settings (UI) or, if unset, from .env (ai.*). The API key is
 * never returned to views or logs.
 */
class AiConfig
{
    public function __construct(private readonly SettingsStore $settings)
    {
    }

    public function provider(): string
    {
        return (string) ($this->settings->integration('ai', 'provider') ?: env('ai.provider', ''));
    }

    public function model(): string
    {
        return (string) ($this->settings->integration('ai', 'model') ?: env('ai.model', ''));
    }

    public function baseUrl(): string
    {
        return (string) ($this->settings->integration('ai', 'base_url') ?: env('ai.baseUrl', ''));
    }

    public function apiKey(): string
    {
        return (string) ($this->settings->integration('ai', 'api_key') ?: env('ai.apiKey', ''));
    }

    public function timeout(): int
    {
        return max(5, (int) ($this->settings->integration('ai', 'timeout') ?: env('ai.timeoutSeconds', 45)));
    }

    public function isConfigured(): bool
    {
        return in_array($this->provider(), ['anthropic', 'openai_compatible'], true) && $this->model() !== '' && $this->apiKey() !== '';
    }
}

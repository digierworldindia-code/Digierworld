<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Claude via the official Anthropic PHP SDK, using structured outputs
 * (JSON schema) so the reply is machine-validated before anything is stored.
 * The client is built per request with the key from AiConfig; no credential
 * ever leaves this class.
 */
class AnthropicProvider implements AiProvider
{
    public function __construct(private readonly AiConfig $config)
    {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->config->model();
    }

    private function client(): Client
    {
        if (! $this->config->isConfigured()) {
            throw new AiProviderException('AI provider is not configured (set ai.apiKey in .env and choose a model).');
        }

        return new Client(
            apiKey: $this->config->apiKey(),
            baseUrl: $this->config->baseUrl(),
            requestOptions: ['timeout' => (float) $this->config->timeout(), 'maxRetries' => $this->config->maxRetries()],
            openTelemetry: false,
        );
    }

    public function structured(string $system, string $user, array $schema, int $maxTokens = 8000): array
    {
        $model    = $this->config->model();
        $fallback = in_array($model, AiConfig::FALLBACK_MODELS, true) && $this->config->baseUrl() === null;
        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                system: $system,
                outputConfig: ['effort' => $this->config->effort(), 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                fallbacks: $fallback ? 'default' : null,
                betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new AiProviderException('The AI provider rejected the API key or this account lacks access.');
        } catch (NotFoundException) {
            throw new AiProviderException('The configured AI model "' . $model . '" was not found.');
        } catch (RateLimitException) {
            throw new AiProviderException('The AI provider is rate-limiting requests. Try again later.', true);
        } catch (BadRequestException $e) {
            throw new AiProviderException('The AI provider rejected the request (' . mb_substr((string) $e->getMessage(), 0, 160) . ').');
        } catch (APIStatusException $e) {
            throw new AiProviderException('The AI provider returned an error (HTTP ' . (int) $e->status . ').', ($e->status ?? 0) >= 500);
        } catch (APITimeoutException) {
            throw new AiProviderException('The AI provider did not answer in time.', true);
        } catch (APIConnectionException) {
            throw new AiProviderException('Could not reach the AI provider (network).', true);
        } catch (APIException $e) {
            throw new AiProviderException('AI request failed: ' . mb_substr(get_class($e), 0, 80));
        }

        if ($message->stopReason === 'refusal') {
            throw new AiProviderException('The AI model declined to review this document.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new AiProviderException('The AI response was cut off before it was complete.');
        }
        $text = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (! is_array($data)) {
            throw new AiProviderException('The AI response was not valid JSON.');
        }

        return ['data' => $data, 'input_tokens' => (int) $message->usage->inputTokens, 'output_tokens' => (int) $message->usage->outputTokens, 'model' => (string) $message->model];
    }
}

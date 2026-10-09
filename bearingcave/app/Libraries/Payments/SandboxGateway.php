<?php

declare(strict_types=1);

namespace App\Libraries\Payments;

/**
 * DEVELOPMENT SANDBOX ONLY. Simulates a provider that confirms payments via
 * a signed webhook so the end-to-end flow (webhook → idempotent processing →
 * reconciliation) can be exercised. No money moves. Disabled in production.
 */
class SandboxGateway implements PaymentGatewayInterface
{
    public function code(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox (test mode — no real money)';
    }

    public function isAvailable(): bool
    {
        return ENVIRONMENT !== 'production' && (bool) policy('payments.sandbox_enabled', false);
    }

    public function isLive(): bool
    {
        return false;
    }

    public function supportsEscrow(): bool
    {
        return false;
    }

    public function initiate(array $payment, array $invoice): array
    {
        return ['status' => 'pending', 'provider_reference' => 'SBX-' . strtoupper(bin2hex(random_bytes(6))), 'instructions' => 'Sandbox payment created. Use "Simulate provider confirmation" to send a signed test webhook.'];
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::secret());
    }

    private static function secret(): string
    {
        return (string) (env('payments.sandbox_secret') ?: hash('sha256', (string) config('Encryption')->key . 'sandbox'));
    }

    public function parseWebhook(string $body, array $headers): ?array
    {
        $sig = $headers['x-sandbox-signature'] ?? '';
        if (! is_string($sig) || ! hash_equals(self::sign($body), $sig)) {
            return null;
        }
        $data = json_decode($body, true);
        if (! is_array($data) || empty($data['event_id']) || empty($data['payment_reference'])) {
            return null;
        }

        return [
            'event_id'          => (string) $data['event_id'],
            'type'              => (string) ($data['type'] ?? 'payment.updated'),
            'payment_reference' => (string) $data['payment_reference'],
            'status'            => (string) ($data['status'] ?? 'succeeded'),
            'amount'            => isset($data['amount']) ? (float) $data['amount'] : null,
        ];
    }
}

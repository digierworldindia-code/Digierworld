<?php

declare(strict_types=1);

namespace App\Libraries\Payments;

/**
 * Offline bank transfer: shows remittance instructions; a Finance Manager
 * records the payment after confirming receipt in the bank account.
 */
class BankTransferGateway implements PaymentGatewayInterface
{
    public function code(): string
    {
        return 'bank_transfer';
    }

    public function label(): string
    {
        return 'Bank transfer (manual confirmation)';
    }

    public function isAvailable(): bool
    {
        return (bool) policy('payments.bank_transfer_enabled', true);
    }

    public function isLive(): bool
    {
        return true;
    }

    public function supportsEscrow(): bool
    {
        return false;
    }

    public function initiate(array $payment, array $invoice): array
    {
        $details = trim((string) policy('payments.bank_instructions', ''));

        return [
            'status'       => 'pending',
            'instructions' => $details !== '' ? $details : 'Bank remittance details have not been configured yet. Please contact BearingCave finance.',
        ];
    }

    public function parseWebhook(string $body, array $headers): ?array
    {
        return null;
    }
}

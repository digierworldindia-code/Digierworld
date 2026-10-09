<?php

declare(strict_types=1);

namespace App\Libraries\Payments;

/**
 * Integration contract for payment / escrow providers.
 *
 * Real providers (e.g. a licensed escrow partner, Razorpay, Stripe) must be
 * implemented against this interface once the client approves a partner.
 * BearingCave never simulates holding funds itself.
 */
interface PaymentGatewayInterface
{
    public function code(): string;

    public function label(): string;

    /** Whether the gateway is configured and allowed in the current environment. */
    public function isAvailable(): bool;

    /** Whether payments through this gateway move real money. */
    public function isLive(): bool;

    public function supportsEscrow(): bool;

    /**
     * Starts a payment. Returns data the UI needs (instructions or redirect URL).
     *
     * @return array{status:string,instructions?:string,redirect_url?:string,provider_reference?:string}
     */
    public function initiate(array $payment, array $invoice): array;

    /**
     * Verifies a webhook signature and normalizes the event.
     *
     * @return array{event_id:string,type:string,payment_reference:string,status:string,amount:float|null}|null
     */
    public function parseWebhook(string $body, array $headers): ?array;
}

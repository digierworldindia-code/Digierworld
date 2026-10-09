<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Payments\BankTransferGateway;
use App\Libraries\Payments\PaymentGatewayInterface;
use App\Libraries\Payments\SandboxGateway;
use App\Models\InvoiceModel;
use App\Models\PaymentModel;
use App\Models\PaymentWebhookEventModel;

/**
 * Invoices, payment records, gateway abstraction and idempotent webhook
 * processing. Escrow is only offered through a configured, licensed
 * provider; until then escrow is shown as "pending partner approval".
 */
class BillingService
{
    public function __construct(
        private InvoiceModel $invoices = new InvoiceModel(),
        private PaymentModel $payments = new PaymentModel(),
    ) {
    }

    /**
     * @return array<string,PaymentGatewayInterface>
     */
    public function gateways(bool $availableOnly = true): array
    {
        $all = [new BankTransferGateway(), new SandboxGateway()];
        $out = [];
        foreach ($all as $g) {
            if (! $availableOnly || $g->isAvailable()) {
                $out[$g->code()] = $g;
            }
        }

        return $out;
    }

    public function gateway(string $code): PaymentGatewayInterface
    {
        $g = $this->gateways(false)[$code] ?? null;
        if ($g === null || ! $g->isAvailable()) {
            throw new BusinessRuleException('That payment method is not available.');
        }

        return $g;
    }

    public function escrowAvailable(): bool
    {
        foreach ($this->gateways() as $g) {
            if ($g->supportsEscrow() && $g->isLive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Issues an invoice. Tax uses the configured rate for the invoice type;
     * while that setting is pending client approval the invoice says so.
     */
    public function issueInvoice(int $companyId, string $type, string $currency, float $subtotal, ?string $refType, ?int $refId, ?string $notes = null): array
    {
        $taxKey  = "tax.{$type}_rate_pct";
        $rate    = (float) policy($taxKey, 0);
        $tax     = round($subtotal * $rate / 100, 2);
        $taxNote = service('settings_store')->isApproved($taxKey) ? null : 'Tax treatment pending client/tax-advisor approval';

        $row = [
            'invoice_number' => service('numbers')->next($type === 'proforma' ? 'PI' : 'INV'),
            'company_id'     => $companyId,
            'invoice_type'   => $type,
            'reference_type' => $refType,
            'reference_id'   => $refId,
            'currency'       => $currency,
            'subtotal'       => round($subtotal, 2),
            'tax_rate'       => $rate,
            'tax_amount'     => $tax,
            'total'          => round($subtotal + $tax, 2),
            'tax_note'       => $taxNote,
            'status'         => 'issued',
            'issued_at'      => date('Y-m-d H:i:s'),
            'due_at'         => date('Y-m-d H:i:s', strtotime('+' . (int) policy('billing.invoice_due_days', 15) . ' days')),
            'notes'          => $notes,
            'created_by'     => auth()->loggedIn() ? (int) auth()->id() : null,
        ];
        $row['id'] = (int) $this->invoices->insert($row);
        service('audit')->log('invoice.issued', ['entity_type' => 'invoice', 'entity_id' => $row['id'], 'company_id' => $companyId, 'description' => "{$row['invoice_number']} {$currency} {$row['total']}"]);

        return $row;
    }

    /**
     * Starts a payment for an invoice through a gateway.
     */
    public function startPayment(array $invoice, string $gatewayCode, ?string $idempotencyKey = null): array
    {
        if ($invoice['status'] !== 'issued') {
            throw new BusinessRuleException('This invoice is not awaiting payment.');
        }
        $g   = $this->gateway($gatewayCode);
        $key = $idempotencyKey ?: 'inv-' . $invoice['id'] . '-' . $g->code() . '-' . bin2hex(random_bytes(4));
        if ($existing = $this->payments->where('idempotency_key', $key)->first()) {
            return $existing; // same request replayed
        }
        $payment = [
            'company_id'      => $invoice['company_id'],
            'invoice_id'      => $invoice['id'],
            'order_id'        => $invoice['reference_type'] === 'order' ? $invoice['reference_id'] : null,
            'provider'        => $g->code(),
            'idempotency_key' => $key,
            'amount'          => $invoice['total'],
            'currency'        => $invoice['currency'],
            'status'          => 'initiated',
            'is_sandbox'      => $g->isLive() ? 0 : 1,
        ];
        $result = $g->initiate($payment, $invoice);
        $payment['status']             = $result['status'];
        $payment['provider_reference'] = $result['provider_reference'] ?? null;
        $payment['notes']              = $result['instructions'] ?? null;
        $payment['id']                 = (int) $this->payments->insert($payment);

        return $payment;
    }

    /**
     * Finance records a confirmed offline payment (bank transfer).
     */
    public function recordManualPayment(array $invoice, float $amount, string $reference, string $paidOn, int $userId, ?string $notes = null): array
    {
        if ($invoice['status'] !== 'issued') {
            throw new BusinessRuleException('Only issued invoices can be marked as paid.');
        }
        if (abs($amount - (float) $invoice['total']) > 0.009) {
            throw new BusinessRuleException('Partial payments are not supported yet; the amount must equal the invoice total (' . $invoice['total'] . ').');
        }
        if (trim($reference) === '') {
            throw new BusinessRuleException('Enter the bank/UTR reference of the received payment.');
        }
        $key = 'manual-' . $invoice['id'] . '-' . sha1($reference);
        if ($this->payments->where('idempotency_key', $key)->first()) {
            throw new BusinessRuleException('This payment reference has already been recorded.');
        }
        $id = (int) $this->payments->insert([
            'company_id' => $invoice['company_id'], 'invoice_id' => $invoice['id'],
            'order_id' => $invoice['reference_type'] === 'order' ? $invoice['reference_id'] : null,
            'provider' => 'bank_transfer', 'provider_reference' => $reference, 'idempotency_key' => $key, 'method' => 'bank_transfer',
            'amount' => $amount, 'currency' => $invoice['currency'], 'status' => 'succeeded', 'is_sandbox' => 0,
            'recorded_by' => $userId, 'paid_at' => $paidOn . ' 00:00:00', 'notes' => $notes,
        ]);
        $this->markInvoicePaid($invoice);

        return $this->payments->find($id);
    }

    /**
     * Idempotent webhook processing: each provider event id is processed once.
     */
    public function handleWebhook(string $provider, string $body, array $headers): array
    {
        $g      = $this->gateways(false)[$provider] ?? null;
        $parsed = $g?->parseWebhook($body, $headers);
        $events = model(PaymentWebhookEventModel::class);

        if ($parsed === null) {
            service('audit')->security('payment.webhook_rejected', "Invalid {$provider} webhook signature or payload");

            return ['ok' => false, 'status' => 'rejected'];
        }
        if ($events->where('provider', $provider)->where('event_id', $parsed['event_id'])->first()) {
            return ['ok' => true, 'status' => 'duplicate'];
        }
        $eventId = (int) $events->insert(['provider' => $provider, 'event_id' => $parsed['event_id'], 'event_type' => $parsed['type'], 'payload' => $body, 'signature_valid' => 1, 'status' => 'received']);

        $payment = $this->payments->where('provider', $provider)->where('provider_reference', $parsed['payment_reference'])->first();
        if (! $payment) {
            $events->update($eventId, ['status' => 'ignored', 'error' => 'Unknown payment reference', 'processed_at' => date('Y-m-d H:i:s')]);

            return ['ok' => true, 'status' => 'ignored'];
        }
        if ($parsed['amount'] !== null && abs($parsed['amount'] - (float) $payment['amount']) > 0.009) {
            $events->update($eventId, ['status' => 'failed', 'error' => 'Amount mismatch — needs reconciliation', 'processed_at' => date('Y-m-d H:i:s')]);
            service('audit')->log('payment.reconciliation_mismatch', ['severity' => 'warning', 'entity_type' => 'payment', 'entity_id' => (int) $payment['id']]);

            return ['ok' => true, 'status' => 'mismatch'];
        }

        $db = db_connect();
        $db->transBegin();

        try {
            if ($parsed['status'] === 'succeeded' && $payment['status'] !== 'succeeded') {
                $this->payments->update($payment['id'], ['status' => 'succeeded', 'paid_at' => date('Y-m-d H:i:s'), 'raw_payload' => $body]);
                if ($payment['invoice_id'] && ($inv = $this->invoices->find($payment['invoice_id'])) && $inv['status'] === 'issued') {
                    $this->markInvoicePaid($inv);
                }
            } elseif ($parsed['status'] === 'failed') {
                $this->payments->update($payment['id'], ['status' => 'failed', 'raw_payload' => $body]);
            }
            $events->update($eventId, ['status' => 'processed', 'processed_at' => date('Y-m-d H:i:s')]);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        return ['ok' => true, 'status' => 'processed'];
    }

    /**
     * Marks an invoice paid and triggers the business effect of the payment.
     */
    public function markInvoicePaid(array $invoice): void
    {
        $this->invoices->update($invoice['id'], ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')]);
        service('audit')->log('invoice.paid', ['entity_type' => 'invoice', 'entity_id' => (int) $invoice['id'], 'company_id' => (int) $invoice['company_id']]);

        switch ($invoice['invoice_type']) {
            case 'subscription':
                service('membership')->activateFromInvoice($invoice);
                break;

            case 'proforma':
                service('orders')->markPaid((int) $invoice['reference_id']);
                break;
        }
        service('notifications')->notifyCompany((int) $invoice['company_id'], 'payment.received', 'Payment received', "Invoice {$invoice['invoice_number']} is paid. Thank you.", null);
    }

    public function voidInvoice(array $invoice, string $reason): void
    {
        if ($invoice['status'] === 'paid') {
            throw new BusinessRuleException('Paid invoices cannot be voided; issue a refund/credit instead.');
        }
        $this->invoices->update($invoice['id'], ['status' => 'void', 'notes' => trim(($invoice['notes'] ?? '') . "\nVoided: " . $reason)]);
        service('audit')->log('invoice.voided', ['entity_type' => 'invoice', 'entity_id' => (int) $invoice['id'], 'company_id' => (int) $invoice['company_id'], 'description' => $reason]);
    }
}

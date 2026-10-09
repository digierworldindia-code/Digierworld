<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Libraries\Payments\SandboxGateway;
use App\Models\InvoiceModel;
use App\Models\PaymentModel;

class Billing extends AreaController
{
    public function index(): string
    {
        $inv = model(InvoiceModel::class)->where('company_id', $this->cid())->orderBy('id', 'DESC');

        return $this->page('account/billing', [
            'title' => 'Invoices & payments', 'invoices' => $inv->paginate(20), 'pager' => $inv->pager,
            'payments' => model(PaymentModel::class)->where('company_id', $this->cid())->orderBy('id', 'DESC')->findAll(20),
            'escrow' => service('billing')->escrowAvailable(),
        ]);
    }

    public function invoice(int $id): string
    {
        $invoice = $this->owned(model(InvoiceModel::class), $id);

        return $this->page('account/invoice', [
            'title' => 'Invoice ' . $invoice['invoice_number'], 'invoice' => $invoice,
            'payments' => model(PaymentModel::class)->where('invoice_id', $id)->orderBy('id', 'DESC')->findAll(),
            'gateways' => service('billing')->gateways(), 'escrow' => service('billing')->escrowAvailable(),
            'refundPolicy' => policy('billing.refund_policy'),
        ]);
    }

    public function pay(int $id)
    {
        $invoice = $this->owned(model(InvoiceModel::class), $id);
        $gateway = (string) $this->request->getPost('gateway');
        $key     = (string) $this->request->getPost('idempotency_key') ?: null;

        return $this->attempt(fn () => service('billing')->startPayment($invoice, $gateway, $key), 'Payment started. Follow the instructions shown below.');
    }

    /**
     * Sandbox only: sends a signed test webhook through the real webhook
     * handler so the full flow can be demonstrated without real money.
     */
    public function simulate(int $paymentId)
    {
        $payment = $this->owned(model(PaymentModel::class), $paymentId);
        if ($payment['provider'] !== 'sandbox' || ! (new SandboxGateway())->isAvailable()) {
            return redirect()->back()->with('error', 'Simulation is only available for sandbox payments in non-production environments.');
        }
        $body = json_encode(['event_id' => 'evt_' . bin2hex(random_bytes(8)), 'type' => 'payment.succeeded', 'payment_reference' => $payment['provider_reference'], 'status' => 'succeeded', 'amount' => (float) $payment['amount']]);
        $result = service('billing')->handleWebhook('sandbox', $body, ['x-sandbox-signature' => SandboxGateway::sign($body)]);

        return redirect()->back()->with($result['status'] === 'processed' ? 'success' : 'error', 'SANDBOX webhook result: ' . $result['status'] . '. No real money was moved.');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CompanyModel;
use App\Models\InvoiceModel;
use App\Models\PaymentModel;
use App\Models\PaymentWebhookEventModel;

class Payments extends AdminController
{
    public function index(): string
    {
        $m = model(PaymentModel::class)->select('payments.*, companies.legal_name')->join('companies', 'companies.id = payments.company_id')->orderBy('payments.id', 'DESC');

        return $this->page('payments', ['title' => 'Payments & escrow', 'rows' => $m->paginate(30), 'pager' => $m->pager,
            'gateways' => service('billing')->gateways(false), 'escrowProvider' => policy('payments.escrow_provider', 'none')]);
    }

    public function invoices(): string
    {
        $m = model(InvoiceModel::class)->select('invoices.*, companies.legal_name')->join('companies', 'companies.id = invoices.company_id');
        if ($s = $this->request->getGet('status')) {
            $m->where('invoices.status', $s);
        }
        if ($t = $this->request->getGet('type')) {
            $m->where('invoices.invoice_type', $t);
        }
        $m->orderBy('invoices.id', 'DESC');

        return $this->page('invoices', ['title' => 'Invoices', 'rows' => $m->paginate(30), 'pager' => $m->pager]);
    }

    public function invoice(int $id): string
    {
        $inv = model(InvoiceModel::class)->find($id) ?? $this->notFound();

        return $this->page('invoice', ['title' => 'Invoice ' . $inv['invoice_number'], 'inv' => $inv, 'company' => model(CompanyModel::class)->find($inv['company_id']),
            'payments' => model(PaymentModel::class)->where('invoice_id', $id)->orderBy('id', 'DESC')->findAll(), 'canRecord' => $this->can('payments.record')]);
    }

    public function record(int $id)
    {
        $inv = model(InvoiceModel::class)->find($id) ?? $this->notFound();
        if ($r = $this->invalid(['amount' => 'required|decimal', 'reference' => 'required|max_length[191]', 'paid_on' => 'required|valid_date[Y-m-d]'])) {
            return $r;
        }

        return $this->attempt(fn () => service('billing')->recordManualPayment($inv, (float) $this->request->getPost('amount'), (string) $this->request->getPost('reference'), (string) $this->request->getPost('paid_on'), $this->userId(), $this->request->getPost('notes') ?: null), 'Payment recorded and invoice marked paid.');
    }

    public function void(int $id)
    {
        $inv = model(InvoiceModel::class)->find($id) ?? $this->notFound();
        if ($r = $this->invalid(['reason' => 'required|max_length[500]'])) {
            return $r;
        }

        return $this->attempt(fn () => service('billing')->voidInvoice($inv, (string) $this->request->getPost('reason')), 'Invoice voided.');
    }

    public function webhooks(): string
    {
        return $this->page('webhooks', ['title' => 'Payment webhook events', 'rows' => model(PaymentWebhookEventModel::class)->orderBy('id', 'DESC')->findAll(100)]);
    }
}

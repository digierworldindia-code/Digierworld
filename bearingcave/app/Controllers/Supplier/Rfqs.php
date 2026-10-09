<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Models\CompanyModel;
use App\Models\QuotationModel;
use App\Models\RfqModel;

/**
 * Supplier view of RFQ leads. A supplier only sees RFQs it was invited to,
 * never the buyer's identity, and never other suppliers' quotations.
 */
class Rfqs extends BaseController
{
    public function index(): string
    {
        $c = $this->company();
        $entitled = entitled('premium_rfq_leads', $c);
        $rows = $entitled ? db_connect()->table('rfq_matches m')->select('m.*, r.rfq_number, r.title, r.deadline_at, r.status AS rfq_status, r.destination_country, (SELECT COUNT(*) FROM rfq_items i WHERE i.rfq_id = r.id) AS item_count')
            ->join('rfqs r', 'r.id = m.rfq_id')->where('m.supplier_company_id', $c['id'])->whereIn('m.status', ['invited', 'viewed', 'quoted', 'declined'])
            ->orderBy('r.deadline_at', 'DESC')->limit(200)->get()->getResultArray() : [];

        return view('supplier/rfqs', ['title' => 'RFQ leads & bidding', 'area' => 'supplier', 'rows' => $rows, 'entitled' => $entitled]);
    }

    private function load(int $rfqId): array
    {
        $c     = $this->company();
        $match = service('rfqs')->matchForSupplier($rfqId, (int) $c['id']);
        if (! $match || ! entitled('premium_rfq_leads', $c)) {
            service('audit')->security('access.denied', "Supplier #{$c['id']} tried to open RFQ #{$rfqId} without an invitation", ['entity_type' => 'rfq', 'entity_id' => $rfqId, 'company_id' => (int) $c['id']]);
            $this->notFound();
        }

        return [model(RfqModel::class)->find($rfqId), $match];
    }

    public function show(int $id): string
    {
        [$rfq, $match] = $this->load($id);
        service('rfqs')->markViewed($match);
        $c     = $this->company();
        $mine  = model(QuotationModel::class)->where('rfq_id', $id)->where('supplier_company_id', $c['id'])->where('is_current', 1)->first();
        $buyer = model(CompanyModel::class)->find($rfq['buyer_company_id']);

        return view('supplier/rfq', [
            'title' => 'RFQ ' . $rfq['rfq_number'], 'area' => 'supplier', 'rfq' => $rfq, 'match' => service('rfqs')->matchForSupplier($id, (int) $c['id']),
            'items' => service('rfqs')->itemsOf($id), 'quote' => $mine, 'quoteItems' => $mine ? array_column(service('quotations')->items((int) $mine['id']), null, 'rfq_item_id') : [],
            'history' => $mine ? service('quotations')->history($mine) : [], 'messages' => $mine ? service('quotations')->messages($mine) : [],
            'buyerLabel' => 'Buyer ' . $buyer['public_alias'] . ' · ' . country_name($buyer['country_code']), 'buyerVerified' => service('entitlements')->isVerifiedBuyer($buyer),
            'documents' => service('documents')->forEntity('rfq', $id), 'canBid' => entitled('supplier_bidding', $c),
            'products' => db_connect()->table('products')->select('id, sku, part_number, name')->where('company_id', $c['id'])->where('deleted_at', null)->whereIn('status', ['published', 'unpublished', 'draft'])->orderBy('part_number')->get()->getResultArray(),
            'direct' => entitled('direct_logistics', $c),
        ]);
    }

    public function quote(int $id)
    {
        [$rfq, $match] = $this->load($id);
        if ($r = $this->invalid(['valid_until' => 'required|valid_date[Y-m-d]', 'lead_time_days' => 'permit_empty|is_natural', 'currency' => 'required|exact_length[3]', 'payment_terms' => 'permit_empty|max_length[191]', 'notes' => 'permit_empty|max_length[3000]'])) {
            return $r;
        }

        return $this->attempt(fn () => service('quotations')->submit($rfq, $this->company(), $match, $this->request->getPost(['currency', 'valid_until', 'delivery_terms', 'lead_time_days', 'payment_terms', 'inspection_offered', 'logistics_option', 'notes']), (array) $this->request->getPost('lines'), $this->userId()), 'Quotation submitted confidentially to the buyer.');
    }

    public function decline(int $id)
    {
        [, $match] = $this->load($id);

        return $this->attempt(fn () => service('rfqs')->decline($match, (string) ($this->request->getPost('reason') ?: 'No stock available')), 'RFQ declined.');
    }

    public function message(int $id)
    {
        $this->load($id);
        $q = model(QuotationModel::class)->where('rfq_id', $id)->where('supplier_company_id', $this->company()['id'])->where('is_current', 1)->first();
        if (! $q) {
            return redirect()->back()->with('error', 'Submit a quotation before messaging the buyer.');
        }

        return $this->attempt(fn () => service('quotations')->message($q, 'supplier', $this->userId(), (string) $this->request->getPost('message')), 'Message sent.');
    }

    public function quotations(): string
    {
        $rows = db_connect()->table('quotations q')->select('q.*, r.rfq_number, r.title')->join('rfqs r', 'r.id = q.rfq_id')
            ->where('q.supplier_company_id', $this->company()['id'])->orderBy('q.id', 'DESC')->limit(200)->get()->getResultArray();

        return view('supplier/quotations', ['title' => 'My quotations', 'area' => 'supplier', 'rows' => $rows]);
    }
}

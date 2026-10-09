<?php

declare(strict_types=1);

namespace App\Controllers\Buyer;

use App\Controllers\BaseController;
use App\Models\CompanyModel;
use App\Models\ProductModel;
use App\Models\QuotationModel;
use App\Models\RfqModel;
use App\Services\RfqService;

class Rfqs extends BaseController
{
    public function index(): string
    {
        $m = model(RfqModel::class)->where('buyer_company_id', service('companyContext')->companyId());
        if ($s = $this->request->getGet('status')) {
            $m->where('status', $s);
        }
        $m->orderBy('id', 'DESC');

        return view('buyer/rfqs', ['title' => 'RFQs & quotations', 'area' => 'buyer', 'rfqs' => $m->paginate(20), 'pager' => $m->pager, 'statuses' => RfqService::STATUSES]);
    }

    public function new(): string
    {
        $prefill = [];
        if ($pid = (int) $this->request->getGet('product_id')) {
            $p = model(ProductModel::class)->find($pid);
            if ($p && service('visibility')->canView($p)) {
                $prefill = ['product_id' => $p['id'], 'part_number' => $p['part_number'], 'description' => $p['name'], 'brand_id' => $p['brand_id'], 'category_id' => $p['category_id'], 'quantity' => max(1, (int) $p['moq'])];
            }
        } elseif ($pn = $this->request->getGet('part_number')) {
            $prefill = ['part_number' => mb_substr((string) $pn, 0, 80), 'description' => '', 'quantity' => 1];
        }
        $c = $this->company();

        return view('buyer/rfq_new', [
            'title' => 'New request for quotation', 'area' => 'buyer', 'company' => $c, 'prefill' => $prefill,
            'categories' => category_options(), 'brands' => brand_options(),
            'deadline' => date('Y-m-d\TH:i', strtotime('+' . (int) policy('rfq.default_deadline_days', 7) . ' days')),
            'limit' => service('entitlements')->limit($c, 'max_open_rfqs'),
        ]);
    }

    public function create()
    {
        if ($r = $this->invalid([
            'title' => 'required|max_length[191]', 'destination_country' => 'required|exact_length[2]|is_not_unique[countries.iso2]', 'delivery_terms' => 'permit_empty|max_length[20]',
            'required_by' => 'permit_empty|valid_date[Y-m-d]', 'deadline_at' => 'required', 'currency' => 'required|exact_length[3]', 'comments' => 'permit_empty|max_length[4000]',
            'delivery_requirements' => 'permit_empty|max_length[2000]',
        ])) {
            return $r;
        }
        $items = [];
        foreach ((array) $this->request->getPost('items') as $it) {
            if (! is_array($it)) {
                continue;
            }
            $items[] = [
                'product_id' => isset($it['product_id']) && ctype_digit((string) $it['product_id']) ? (int) $it['product_id'] : null,
                'category_id' => isset($it['category_id']) && ctype_digit((string) $it['category_id']) ? (int) $it['category_id'] : null,
                'brand_id' => isset($it['brand_id']) && ctype_digit((string) $it['brand_id']) ? (int) $it['brand_id'] : null,
                'brand_text' => mb_substr(trim((string) ($it['brand_text'] ?? '')), 0, 120), 'part_number' => mb_substr(trim((string) ($it['part_number'] ?? '')), 0, 80),
                'description' => mb_substr(trim((string) ($it['description'] ?? '')), 0, 255), 'specifications' => mb_substr(trim((string) ($it['specifications'] ?? '')), 0, 2000),
                'quantity' => (int) ($it['quantity'] ?? 0), 'unit' => mb_substr((string) ($it['unit'] ?? 'pcs'), 0, 20) ?: 'pcs',
                'target_unit_price' => isset($it['target_unit_price']) && is_numeric($it['target_unit_price']) ? $it['target_unit_price'] : '',
            ];
            // Product references must point to listings the buyer can see.
            $last = array_key_last($items);
            if ($items[$last]['product_id']) {
                $p = model(ProductModel::class)->find($items[$last]['product_id']);
                if (! $p || ! service('visibility')->canView($p)) {
                    $items[$last]['product_id'] = null;
                }
            }
        }
        $rfq = null;
        $res = $this->attempt(function () use ($items, &$rfq) {
            $rfq = service('rfqs')->create($this->company(), $this->userId(), $this->request->getPost(), $items);
            $file = $this->request->getFile('attachment');
            if ($file && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                service('documents')->store($file, ['company_id' => (int) $this->company()['id'], 'entity_type' => 'rfq', 'entity_id' => (int) $rfq['id'], 'doc_type' => 'rfq_attachment', 'title' => 'RFQ attachment: ' . $file->getClientName()]);
            }
        }, 'RFQ submitted.');

        return $rfq ? redirect()->to(site_url('buyer/rfqs/' . $rfq['id']))->with('success', 'RFQ ' . $rfq['rfq_number'] . ' submitted. BearingCave will review it and send it to matched verified suppliers.') : $res;
    }

    private function load(int $id): array
    {
        return $this->owned(model(RfqModel::class), $id, 'buyer_company_id');
    }

    public function show(int $id): string
    {
        $rfq    = $this->load($id);
        $quotes = service('quotations')->currentForRfq($id);
        $vis    = service('visibility');
        $ent    = service('entitlements');
        $offers = [];
        foreach ($quotes as $q) {
            $s = model(CompanyModel::class)->find($q['supplier_company_id']);
            $offers[] = [
                'q' => $q, 'items' => service('quotations')->items((int) $q['id']), 'label' => $vis->supplierLabel($s),
                'verified' => $ent->isVerifiedSupplier($s), 'messages' => service('quotations')->messages($q),
                'perf' => $ent->has($this->company(), 'advanced_comparison') ? service('performance')->latestSupplier((int) $s['id']) : null,
            ];
        }

        return view('buyer/rfq', [
            'title' => 'RFQ ' . $rfq['rfq_number'], 'area' => 'buyer', 'company' => $this->company(), 'rfq' => $rfq, 'items' => service('rfqs')->itemsOf($id), 'offers' => $offers,
            'statuses' => RfqService::STATUSES, 'documents' => service('documents')->forEntity('rfq', $id),
            'invited' => db_connect()->table('rfq_matches')->where('rfq_id', $id)->whereIn('status', ['invited', 'viewed', 'quoted', 'declined'])->countAllResults(),
            'advanced' => service('entitlements')->has($this->company(), 'advanced_comparison'),
        ]);
    }

    public function cancel(int $id)
    {
        $rfq = $this->load($id);

        return $this->attempt(fn () => service('rfqs')->cancel($rfq, $this->userId(), (string) ($this->request->getPost('reason') ?: 'Cancelled by buyer')), 'RFQ cancelled.');
    }

    private function quote(int $qid): array
    {
        $q   = model(QuotationModel::class)->find($qid);
        $rfq = $q ? model(RfqModel::class)->find($q['rfq_id']) : null;
        if (! $q || ! $rfq || (int) $rfq['buyer_company_id'] !== (int) service('companyContext')->companyId()) {
            service('audit')->security('access.denied', "Buyer tried to act on quotation #{$qid}", ['entity_type' => 'quotation', 'entity_id' => $qid, 'company_id' => service('companyContext')->companyId()]);
            $this->notFound();
        }

        return $q;
    }

    public function shortlist(int $qid)
    {
        $q = $this->quote($qid);

        return $this->attempt(fn () => service('quotations')->shortlist($q), 'Quotation shortlisted.');
    }

    public function rejectQuote(int $qid)
    {
        $q = $this->quote($qid);

        return $this->attempt(fn () => service('quotations')->reject($q, $this->request->getPost('note') ?: null), 'Quotation rejected.');
    }

    public function acceptQuote(int $qid)
    {
        $q = $this->quote($qid);
        if ($r = $this->invalid(['delivery_address' => 'required|max_length[1000]', 'buyer_po_reference' => 'permit_empty|max_length[80]', 'accept_terms' => 'required'])) {
            return $r;
        }
        $order = null;
        $res   = $this->attempt(function () use ($q, &$order) {
            $order = service('quotations')->accept($q, $this->company(), $this->userId(), [
                'delivery_country' => model(RfqModel::class)->find($q['rfq_id'])['destination_country'],
                'delivery_address' => $this->request->getPost('delivery_address'), 'buyer_po_reference' => $this->request->getPost('buyer_po_reference') ?: null,
                'logistics_mode' => $q['logistics_option'],
            ]);
        }, 'Offer accepted.');

        return $order ? redirect()->to(site_url('buyer/orders/' . $order['id']))->with('success', 'Offer accepted. Order ' . $order['order_number'] . ' was created and a proforma invoice issued.') : $res;
    }

    public function message(int $qid)
    {
        $q = $this->quote($qid);

        return $this->attempt(fn () => service('quotations')->message($q, 'buyer', $this->userId(), (string) $this->request->getPost('message'), $this->request->getPost('proposed_total')), 'Message sent to the supplier.');
    }
}

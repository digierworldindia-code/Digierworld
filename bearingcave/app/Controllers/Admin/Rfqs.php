<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CompanyModel;
use App\Models\RfqModel;
use App\Services\RfqService;

class Rfqs extends AdminController
{
    public function index(): string
    {
        $m = model(RfqModel::class)->select('rfqs.*, companies.legal_name, companies.is_sample, (SELECT COUNT(*) FROM rfq_matches m WHERE m.rfq_id = rfqs.id) AS matches, (SELECT COUNT(*) FROM quotations q WHERE q.rfq_id = rfqs.id AND q.is_current = 1) AS quotes', false)
            ->join('companies', 'companies.id = rfqs.buyer_company_id');
        $status = $this->request->getGet('status') ?? '';
        if ($status === 'open') {
            $m->whereIn('rfqs.status', ['submitted', 'under_review']);
        } elseif ($status !== '') {
            $m->where('rfqs.status', $status);
        }
        if ($q = trim((string) $this->request->getGet('q'))) {
            $m->groupStart()->like('rfqs.rfq_number', $q)->orLike('rfqs.title', $q)->orLike('companies.legal_name', $q)->groupEnd();
        }
        $m->orderBy('rfqs.id', 'DESC');

        return $this->page('rfqs', ['title' => 'RFQs & matching', 'rows' => $m->paginate(25), 'pager' => $m->pager, 'statuses' => RfqService::STATUSES, 'status' => $status]);
    }

    public function show(int $id): string
    {
        $rfq = model(RfqModel::class)->find($id) ?? $this->notFound();
        $db  = db_connect();
        $ent = service('entitlements');
        $matches = $db->table('rfq_matches m')->select('m.*, c.legal_name, c.public_alias, c.country_code, c.is_sample, sp.performance_score, sp.risk_level')
            ->join('companies c', 'c.id = m.supplier_company_id')->join('supplier_profiles sp', 'sp.company_id = c.id', 'left')
            ->where('m.rfq_id', $id)->orderBy('m.match_score', 'DESC')->get()->getResultArray();
        foreach ($matches as &$mm) {
            $co = model(CompanyModel::class)->find($mm['supplier_company_id']);
            $mm['eligible'] = $ent->isVerifiedSupplier($co) && $ent->has($co, 'premium_rfq_leads') && $ent->has($co, 'supplier_bidding');
        }
        $quotes = [];
        foreach (service('quotations')->currentForRfq($id) as $q) {
            $q['items'] = service('quotations')->items((int) $q['id']);
            $q['supplier'] = model(CompanyModel::class)->find($q['supplier_company_id']);
            $quotes[] = $q;
        }

        return $this->page('rfq', [
            'title' => 'RFQ ' . $rfq['rfq_number'], 'rfq' => $rfq, 'buyer' => model(CompanyModel::class)->find($rfq['buyer_company_id']),
            'items' => service('rfqs')->itemsOf($id), 'matches' => $matches, 'quotes' => $quotes, 'statuses' => RfqService::STATUSES,
            'documents' => service('documents')->forEntity('rfq', $id), 'canManage' => $this->can('rfq.manage'),
            'eligibleSuppliers' => array_column(array_filter(model(CompanyModel::class)->where('company_type', 'supplier')->where('verification_status', 'verified')->where('status', 'active')->orderBy('legal_name')->findAll(),
                static fn ($c) => $ent->isVerifiedSupplier($c) && $ent->has($c, 'premium_rfq_leads')), 'legal_name', 'id'),
        ]);
    }

    public function review(int $id)
    {
        $rfq = model(RfqModel::class)->find($id) ?? $this->notFound();

        return $this->attempt(fn () => service('rfqs')->review($rfq, (string) $this->request->getPost('decision'), $this->request->getPost('notes') ?: null, $this->userId()), 'Review saved.');
    }

    public function match(int $id)
    {
        $rfq = model(RfqModel::class)->find($id) ?? $this->notFound();
        $m   = service('matching')->run($rfq);

        return redirect()->back()->with('success', 'Matching re-run: ' . count($m) . ' supplier(s) in the match list.');
    }

    public function distribute(int $id)
    {
        $rfq = model(RfqModel::class)->find($id) ?? $this->notFound();
        $ids = array_map('intval', (array) $this->request->getPost('suppliers'));
        if ($extra = (int) $this->request->getPost('extra_supplier')) {
            $ids[] = $extra;
        }

        return $this->attempt(function () use ($rfq, $ids) {
            $n = service('rfqs')->distribute($rfq, $ids, $this->userId());

            return redirect()->back()->with('success', "RFQ sent to {$n} supplier(s).");
        }, 'Distributed.');
    }

    public function deadline(int $id)
    {
        $rfq = model(RfqModel::class)->find($id) ?? $this->notFound();
        $ts  = strtotime((string) $this->request->getPost('deadline_at'));
        if (! $ts || $ts < time() + 3600) {
            return redirect()->back()->with('error', 'Choose a deadline at least one hour ahead.');
        }
        model(RfqModel::class)->update($id, ['deadline_at' => date('Y-m-d H:i:s', $ts)]);
        service('audit')->log('rfq.deadline', ['entity_type' => 'rfq', 'entity_id' => $id, 'description' => date('Y-m-d H:i', $ts)]);

        return redirect()->back()->with('success', 'Deadline updated.');
    }

    public function quotations(): string
    {
        $rows = db_connect()->table('quotations q')->select('q.*, r.rfq_number, c.legal_name AS supplier')->join('rfqs r', 'r.id = q.rfq_id')->join('companies c', 'c.id = q.supplier_company_id')
            ->orderBy('q.id', 'DESC')->limit(200)->get()->getResultArray();

        return $this->page('quotations', ['title' => 'Quotations / bids', 'rows' => $rows]);
    }
}

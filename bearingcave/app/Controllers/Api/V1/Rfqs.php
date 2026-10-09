<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApi;
use App\Exceptions\BusinessRuleException;
use App\Models\RfqModel;

/**
 * Buyer RFQ API (token auth). Only the caller's own company's RFQs are visible.
 */
class Rfqs extends BaseApi
{
    private function buyer(): ?array
    {
        $c = service('companyContext')->company((int) auth('tokens')->id());

        return $c && $c['company_type'] === 'buyer' ? $c : null;
    }

    public function index()
    {
        if (! $b = $this->buyer()) {
            return $this->error('Only buyer accounts can use the RFQ API', 403);
        }
        $rows = model(RfqModel::class)->select('id, rfq_number, title, status, destination_country, deadline_at, currency, created_at')->where('buyer_company_id', $b['id'])->orderBy('id', 'DESC')->findAll(100);

        return $this->ok($rows);
    }

    public function show(int $id)
    {
        if (! $b = $this->buyer()) {
            return $this->error('Only buyer accounts can use the RFQ API', 403);
        }
        $rfq = model(RfqModel::class)->find($id);
        if (! $rfq || (int) $rfq['buyer_company_id'] !== (int) $b['id']) {
            service('audit')->security('api.access_denied', "API RFQ #{$id} requested by company #{$b['id']}", ['company_id' => (int) $b['id']]);

            return $this->error('Not found', 404);
        }
        $quotes = array_map(static fn ($q) => ['quotation_number' => $q['quotation_number'], 'revision' => (int) $q['revision'], 'status' => $q['status'], 'currency' => $q['currency'], 'total' => (float) $q['total_amount'], 'valid_until' => $q['valid_until'], 'lead_time_days' => $q['lead_time_days']], service('quotations')->currentForRfq($id));

        return $this->ok(['rfq' => array_intersect_key($rfq, array_flip(['id', 'rfq_number', 'title', 'status', 'destination_country', 'delivery_terms', 'required_by', 'deadline_at', 'currency'])),
            'items' => array_map(static fn ($i) => array_intersect_key($i, array_flip(['id', 'part_number', 'description', 'quantity', 'unit', 'target_unit_price'])), service('rfqs')->itemsOf($id)),
            'quotations' => $quotes]);
    }

    public function create()
    {
        if (! $b = $this->buyer()) {
            return $this->error('Only buyer accounts can use the RFQ API', 403);
        }
        if (! service('companyContext')->can('rfq.manage', (int) auth('tokens')->id())) {
            return $this->error('Your company role does not allow creating RFQs', 403);
        }
        $in = $this->request->getJSON(true) ?? [];
        $rules = ['title' => 'required|max_length[191]', 'destination_country' => 'required|exact_length[2]|is_not_unique[countries.iso2]', 'deadline_at' => 'required', 'currency' => 'permit_empty|exact_length[3]', 'items' => 'required'];
        $v = service('validation')->setRules($rules);
        if (! $v->run($in)) {
            return $this->error('Validation failed', 422, $v->getErrors());
        }
        try {
            $rfq = service('rfqs')->create($b, (int) auth('tokens')->id(), $in + ['currency' => 'INR'], array_map(static fn ($i) => (array) $i, (array) $in['items']), 'form');
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(['id' => (int) $rfq['id'], 'rfq_number' => $rfq['rfq_number'], 'status' => $rfq['status']], [], 201);
    }
}

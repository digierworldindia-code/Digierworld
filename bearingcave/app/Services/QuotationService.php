<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\QuotationItemModel;
use App\Models\QuotationMessageModel;
use App\Models\QuotationModel;
use App\Models\RfqMatchModel;
use App\Models\RfqModel;

/**
 * Supplier quotations (sealed, confidential bids) with revision history,
 * negotiation messages and buyer decisions.
 *
 * Confidentiality: a supplier only ever sees its own quotations; buyers see
 * all offers on their RFQ with supplier identity masked per VisibilityService.
 */
class QuotationService
{
    public function __construct(
        private QuotationModel $quotes = new QuotationModel(),
        private QuotationItemModel $qitems = new QuotationItemModel(),
    ) {
    }

    /**
     * Submits (or revises) a quotation.
     *
     * @param array<int,array{unit_price:string,quantity?:string,lead_time_days?:string,notes?:string}> $lines keyed by rfq_item_id
     */
    public function submit(array $rfq, array $supplier, array $match, array $terms, array $lines, int $userId): array
    {
        $ent = service('entitlements');
        if (! $ent->isVerifiedSupplier($supplier) || ! $ent->has($supplier, 'supplier_bidding')) {
            throw new BusinessRuleException('Quotations on RFQs are available to verified suppliers with an active membership.');
        }
        if (! in_array($rfq['status'], ['distributed', 'evaluation'], true)) {
            throw new BusinessRuleException('This RFQ is not accepting quotations.');
        }
        if (strtotime($rfq['deadline_at']) < time()) {
            throw new BusinessRuleException('The quotation deadline has passed.');
        }
        if (! in_array($match['status'], ['invited', 'viewed', 'quoted'], true)) {
            throw new BusinessRuleException('You are not invited to quote on this RFQ.');
        }

        $items = service('rfqs')->itemsOf((int) $rfq['id']);
        $rows  = [];
        $total = 0.0;
        foreach ($items as $it) {
            $l = $lines[$it['id']] ?? null;
            if ($l === null || ($l['unit_price'] ?? '') === '') {
                continue; // supplier may quote a subset of items
            }
            if (! is_numeric($l['unit_price']) || (float) $l['unit_price'] < 0) {
                throw new BusinessRuleException('Unit prices must be valid positive numbers.');
            }
            $qty = (int) (($l['quantity'] ?? '') !== '' ? $l['quantity'] : $it['quantity']);
            if ($qty < 1) {
                throw new BusinessRuleException('Quoted quantities must be at least 1.');
            }
            $line   = round($qty * (float) $l['unit_price'], 2);
            $total += $line;
            $rows[] = [
                'rfq_item_id'    => $it['id'],
                'product_id'     => $this->ownProductFor($supplier, $it, $l['product_id'] ?? null),
                'description'    => $it['description'],
                'part_number'    => $it['part_number'],
                'quantity'       => $qty,
                'unit_price'     => $l['unit_price'],
                'line_total'     => $line,
                'lead_time_days' => ($l['lead_time_days'] ?? '') !== '' ? (int) $l['lead_time_days'] : null,
                'notes'          => isset($l['notes']) ? mb_substr($l['notes'], 0, 255) : null,
            ];
        }
        if ($rows === []) {
            throw new BusinessRuleException('Enter a unit price for at least one item.');
        }
        if (($terms['logistics_option'] ?? '') === 'supplier_direct' && ! $ent->has($supplier, 'direct_logistics')) {
            throw new BusinessRuleException('Supplier-direct logistics is not included in your plan.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $previous = $this->quotes->where('rfq_id', $rfq['id'])->where('supplier_company_id', $supplier['id'])->where('is_current', 1)->first();
            if ($previous && ! in_array($previous['status'], ['submitted', 'shortlisted'], true)) {
                throw new BusinessRuleException('Your quotation can no longer be revised (status: ' . $previous['status'] . ').');
            }
            if ($previous) {
                $max = (int) policy('rfq.max_revisions', 5);
                if ((int) $previous['revision'] >= $max) {
                    throw new BusinessRuleException("A quotation can be revised at most {$max} times.");
                }
                $this->quotes->update($previous['id'], ['is_current' => 0, 'status' => 'superseded']);
            }
            $qid = (int) $this->quotes->insert([
                'quotation_number'    => $previous ? preg_replace('/-R\d+$/', '', $previous['quotation_number']) . '-R' . ((int) $previous['revision'] + 1) : service('numbers')->next('QT'),
                'rfq_id'              => $rfq['id'],
                'supplier_company_id' => $supplier['id'],
                'rfq_match_id'        => $match['id'],
                'revision'            => $previous ? (int) $previous['revision'] + 1 : 1,
                'previous_id'         => $previous['id'] ?? null,
                'is_current'          => 1,
                'status'              => 'submitted',
                'currency'            => $terms['currency'] ?? $rfq['currency'],
                'total_amount'        => round($total, 2),
                'valid_until'         => ($terms['valid_until'] ?? '') ?: null,
                'delivery_terms'      => $terms['delivery_terms'] ?? null,
                'lead_time_days'      => ($terms['lead_time_days'] ?? '') !== '' ? (int) $terms['lead_time_days'] : null,
                'payment_terms'       => $terms['payment_terms'] ?? null,
                'inspection_offered'  => empty($terms['inspection_offered']) ? 0 : 1,
                'logistics_option'    => in_array($terms['logistics_option'] ?? '', ['platform_managed', 'supplier_direct', 'confidential', 'buyer_arranged'], true) ? $terms['logistics_option'] : 'platform_managed',
                'notes'               => $terms['notes'] ?? null,
                'submitted_by'        => $userId,
                'submitted_at'        => date('Y-m-d H:i:s'),
            ]);
            foreach ($rows as $r) {
                $this->qitems->insert($r + ['quotation_id' => $qid]);
            }
            model(RfqMatchModel::class)->update($match['id'], ['status' => 'quoted', 'responded_at' => $match['responded_at'] ?? date('Y-m-d H:i:s')]);
            if ($rfq['status'] === 'distributed') {
                model(RfqModel::class)->update($rfq['id'], ['status' => 'evaluation']);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $q = $this->quotes->find($qid);
        service('notifications')->notifyCompany((int) $rfq['buyer_company_id'], 'quotation.received', ($previous ? 'Revised quotation' : 'New quotation') . " on {$rfq['rfq_number']}", 'Compare offers on your RFQ page.', 'buyer/rfqs/' . $rfq['id']);
        service('audit')->log($previous ? 'quotation.revised' : 'quotation.submitted', ['entity_type' => 'quotation', 'entity_id' => $qid, 'company_id' => (int) $supplier['id'], 'description' => $q['quotation_number']]);

        return $q;
    }

    private function ownProductFor(array $supplier, array $item, $productId): ?int
    {
        $db = db_connect();
        if ($productId && ctype_digit((string) $productId)) {
            $p = $db->table('products')->where('id', (int) $productId)->where('company_id', $supplier['id'])->where('deleted_at', null)->get()->getRowArray();
            if ($p) {
                return (int) $p['id'];
            }
        }
        if ($item['part_number_norm']) {
            $p = $db->table('products')->select('id')->where('company_id', $supplier['id'])->where('part_number_norm', $item['part_number_norm'])->where('status', 'published')->where('deleted_at', null)->get()->getRowArray();

            return $p ? (int) $p['id'] : null;
        }

        return null;
    }

    public function currentForRfq(int $rfqId): array
    {
        return $this->quotes->where('rfq_id', $rfqId)->where('is_current', 1)->whereNotIn('status', ['draft', 'withdrawn'])->orderBy('total_amount', 'ASC')->findAll();
    }

    public function items(int $quotationId): array
    {
        return $this->qitems->where('quotation_id', $quotationId)->findAll();
    }

    public function history(array $quotation): array
    {
        return $this->quotes->where('rfq_id', $quotation['rfq_id'])->where('supplier_company_id', $quotation['supplier_company_id'])->orderBy('revision', 'DESC')->findAll();
    }

    public function shortlist(array $q): void
    {
        if ($q['status'] !== 'submitted') {
            throw new BusinessRuleException('Only submitted quotations can be shortlisted.');
        }
        $this->quotes->update($q['id'], ['status' => 'shortlisted']);
    }

    public function reject(array $q, ?string $note): void
    {
        if (! in_array($q['status'], ['submitted', 'shortlisted'], true)) {
            throw new BusinessRuleException('This quotation cannot be rejected.');
        }
        $this->quotes->update($q['id'], ['status' => 'rejected', 'buyer_decision_at' => date('Y-m-d H:i:s'), 'buyer_decision_note' => $note]);
        $rfq = model(RfqModel::class)->find($q['rfq_id']);
        service('notifications')->notifyCompany((int) $q['supplier_company_id'], 'quotation.rejected', "Quotation {$q['quotation_number']} not selected", (string) $note, 'supplier/rfqs/' . $q['rfq_id']);
        service('audit')->log('quotation.rejected', ['entity_type' => 'quotation', 'entity_id' => (int) $q['id'], 'company_id' => (int) $rfq['buyer_company_id']]);
    }

    /**
     * Buyer accepts an offer → order is created on the quotation's terms.
     */
    public function accept(array $q, array $buyer, int $userId, array $delivery): array
    {
        if (! in_array($q['status'], ['submitted', 'shortlisted'], true) || ! (int) $q['is_current']) {
            throw new BusinessRuleException('This quotation can no longer be accepted.');
        }
        if ($q['valid_until'] && $q['valid_until'] < date('Y-m-d')) {
            throw new BusinessRuleException('This quotation has expired. Ask the supplier for a revised offer.');
        }
        $rfq = model(RfqModel::class)->find($q['rfq_id']);

        $db = db_connect();
        $db->transBegin();

        try {
            $order = service('orders')->createFromQuotation($q, $rfq, $buyer, $userId, $delivery);
            $this->quotes->update($q['id'], ['status' => 'accepted', 'buyer_decision_at' => date('Y-m-d H:i:s')]);
            model(RfqModel::class)->update($rfq['id'], ['status' => 'awarded', 'awarded_quotation_id' => $q['id']]);
            $others = $this->quotes->where('rfq_id', $rfq['id'])->where('id !=', $q['id'])->where('is_current', 1)->whereIn('status', ['submitted', 'shortlisted'])->findAll();
            foreach ($others as $o) {
                $this->quotes->update($o['id'], ['status' => 'rejected', 'buyer_decision_at' => date('Y-m-d H:i:s'), 'buyer_decision_note' => 'Another offer was accepted']);
                service('notifications')->notifyCompany((int) $o['supplier_company_id'], 'quotation.rejected', "RFQ {$rfq['rfq_number']} awarded to another supplier", 'Thank you for quoting.', 'supplier/rfqs/' . $rfq['id']);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        service('notifications')->notifyCompany((int) $q['supplier_company_id'], 'quotation.accepted', "Offer accepted — order {$order['order_number']}", "Your quotation {$q['quotation_number']} was accepted.", 'supplier/orders/' . $order['id']);
        service('audit')->log('quotation.accepted', ['entity_type' => 'quotation', 'entity_id' => (int) $q['id'], 'company_id' => (int) $buyer['id'], 'description' => $order['order_number']]);

        return $order;
    }

    public function message(array $q, string $side, int $userId, string $message, ?string $proposedTotal = null): void
    {
        if (trim($message) === '') {
            throw new BusinessRuleException('Message cannot be empty.');
        }
        if (! in_array($q['status'], ['submitted', 'shortlisted'], true)) {
            throw new BusinessRuleException('Negotiation is closed for this quotation.');
        }
        model(QuotationMessageModel::class)->insert([
            'quotation_id'   => $q['id'],
            'sender_side'    => $side,
            'user_id'        => $userId,
            'message'        => $message,
            'proposed_total' => ($proposedTotal ?? '') !== '' && is_numeric($proposedTotal) ? $proposedTotal : null,
        ]);
        $rfq = model(RfqModel::class)->find($q['rfq_id']);
        if ($side === 'buyer') {
            service('notifications')->notifyCompany((int) $q['supplier_company_id'], 'quotation.negotiation', "Buyer message on {$q['quotation_number']}", mb_substr($message, 0, 200), 'supplier/rfqs/' . $q['rfq_id']);
        } else {
            service('notifications')->notifyCompany((int) $rfq['buyer_company_id'], 'quotation.negotiation', "Supplier message on {$rfq['rfq_number']}", mb_substr($message, 0, 200), 'buyer/rfqs/' . $q['rfq_id']);
        }
    }

    public function messages(array $q): array
    {
        $ids = array_column($this->history($q), 'id');

        return model(QuotationMessageModel::class)->whereIn('quotation_id', $ids ?: [0])->orderBy('id')->findAll();
    }
}

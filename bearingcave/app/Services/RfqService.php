<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Viewer;
use App\Models\CompanyModel;
use App\Models\RfqItemModel;
use App\Models\RfqMatchModel;
use App\Models\RfqModel;

/**
 * Buyer RFQs: submission → admin review → supplier matching → distribution
 * → quotations → evaluation → award.
 */
class RfqService
{
    public const STATUSES = [
        'draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'distributed' => 'Sent to suppliers',
        'evaluation' => 'Quotations received', 'awarded' => 'Awarded', 'closed' => 'Closed', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected',
    ];

    public function __construct(
        private RfqModel $rfqs = new RfqModel(),
        private RfqItemModel $items = new RfqItemModel(),
        private RfqMatchModel $matches = new RfqMatchModel(),
    ) {
    }

    /**
     * @param list<array{product_id?:int|null,category_id?:int|null,brand_id?:int|null,brand_text?:string,part_number?:string,description:string,specifications?:string,quantity:int,unit?:string,target_unit_price?:string|null}> $items
     */
    public function create(array $company, int $userId, array $data, array $items, string $source = 'form'): array
    {
        $ent = service('entitlements');
        if (! $ent->has($company, 'rfq_submission')) {
            throw new BusinessRuleException('Your account cannot submit RFQs at the moment.');
        }
        if ($company['status'] !== 'active') {
            throw new BusinessRuleException('Your account is suspended.');
        }
        $limit = $ent->limit($company, 'max_open_rfqs');
        if ($limit !== null) {
            $open = $this->rfqs->where('buyer_company_id', $company['id'])->whereIn('status', ['submitted', 'under_review', 'distributed', 'evaluation'])->countAllResults();
            if ($open >= $limit) {
                throw new BusinessRuleException("You can have up to {$limit} open RFQs. Close or award an existing RFQ, or complete buyer verification for a higher limit.");
            }
        }
        $items = array_values(array_filter($items, static fn ($i) => trim((string) ($i['description'] ?? '')) !== '' || trim((string) ($i['part_number'] ?? '')) !== ''));
        if ($items === []) {
            throw new BusinessRuleException('Add at least one item (part number or description).');
        }
        $deadline = strtotime((string) $data['deadline_at']);
        if ($deadline === false || $deadline < time() + 3600) {
            throw new BusinessRuleException('The quotation deadline must be at least one hour in the future.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $rfqId = (int) $this->rfqs->insert([
                'rfq_number'            => service('numbers')->next('RFQ'),
                'buyer_company_id'      => $company['id'],
                'created_by'            => $userId,
                'title'                 => mb_substr(trim($data['title']), 0, 191),
                'destination_country'   => $data['destination_country'],
                'delivery_terms'        => $data['delivery_terms'] ?? null,
                'delivery_requirements' => $data['delivery_requirements'] ?? null,
                'required_by'           => ($data['required_by'] ?? '') ?: null,
                'deadline_at'           => date('Y-m-d H:i:s', $deadline),
                'currency'              => $data['currency'] ?? 'INR',
                'inspection_required'   => empty($data['inspection_required']) ? 0 : 1,
                'logistics_required'    => empty($data['logistics_required']) ? 0 : 1,
                'comments'              => $data['comments'] ?? null,
                'status'                => 'submitted',
                'source'                => $source,
            ]);
            foreach ($items as $it) {
                $qty = (int) ($it['quantity'] ?? 0);
                if ($qty < 1) {
                    throw new BusinessRuleException('Each RFQ item needs a quantity of at least 1.');
                }
                $this->items->insert([
                    'rfq_id'            => $rfqId,
                    'product_id'        => ($it['product_id'] ?? null) ?: null,
                    'category_id'       => ($it['category_id'] ?? null) ?: null,
                    'brand_id'          => ($it['brand_id'] ?? null) ?: null,
                    'brand_text'        => ($it['brand_text'] ?? null) ?: null,
                    'part_number'       => ($it['part_number'] ?? null) ?: null,
                    'part_number_norm'  => normalize_part($it['part_number'] ?? '') ?: null,
                    'description'       => mb_substr(trim((string) ($it['description'] ?? '') ?: (string) $it['part_number']), 0, 255),
                    'specifications'    => $it['specifications'] ?? null,
                    'quantity'          => $qty,
                    'unit'              => $it['unit'] ?? 'pcs',
                    'target_unit_price' => ($it['target_unit_price'] ?? '') !== '' ? $it['target_unit_price'] : null,
                ]);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $rfq = $this->rfqs->find($rfqId);
        service('notifications')->notifyStaff(['procurement_manager'], 'rfq.new', 'New RFQ ' . $rfq['rfq_number'], $rfq['title'], 'admin/rfqs/' . $rfqId);
        service('audit')->log('rfq.created', ['entity_type' => 'rfq', 'entity_id' => $rfqId, 'company_id' => (int) $company['id'], 'description' => $rfq['rfq_number']]);

        if (policy('rfq.auto_match_on_submit', true)) {
            service('matching')->run($rfq);
        }

        return $rfq;
    }

    public function itemsOf(int $rfqId): array
    {
        return $this->items->where('rfq_id', $rfqId)->orderBy('id')->findAll();
    }

    /**
     * Admin review decision.
     */
    public function review(array $rfq, string $decision, ?string $notes, int $adminId): void
    {
        if (! in_array($rfq['status'], ['submitted', 'under_review'], true)) {
            throw new BusinessRuleException('This RFQ has already been processed.');
        }
        if ($decision === 'reject') {
            if (trim((string) $notes) === '') {
                throw new BusinessRuleException('Please give the buyer a reason.');
            }
            $this->rfqs->update($rfq['id'], ['status' => 'rejected', 'admin_notes' => $notes, 'reviewed_by' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s')]);
            service('notifications')->notifyCompany((int) $rfq['buyer_company_id'], 'rfq.rejected', "RFQ {$rfq['rfq_number']} was not accepted", (string) $notes, 'buyer/rfqs/' . $rfq['id']);
        } else {
            $this->rfqs->update($rfq['id'], ['status' => 'under_review', 'admin_notes' => $notes, 'reviewed_by' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s')]);
        }
        service('audit')->log('rfq.reviewed', ['entity_type' => 'rfq', 'entity_id' => (int) $rfq['id'], 'description' => $decision]);
    }

    /**
     * Sends the RFQ to chosen matched suppliers. Only suppliers that are
     * currently entitled to premium RFQ leads and bidding can be invited.
     *
     * @param list<int> $supplierIds
     */
    public function distribute(array $rfq, array $supplierIds, ?int $adminId): int
    {
        if (! in_array($rfq['status'], ['submitted', 'under_review', 'distributed', 'evaluation'], true)) {
            throw new BusinessRuleException('This RFQ cannot be distributed in its current status.');
        }
        if (strtotime($rfq['deadline_at']) <= time()) {
            throw new BusinessRuleException('The RFQ deadline has passed. Extend it before distributing.');
        }
        $ent     = service('entitlements');
        $invited = 0;
        foreach (array_unique(array_map('intval', $supplierIds)) as $sid) {
            $supplier = model(CompanyModel::class)->find($sid);
            if (! $supplier || ! $ent->isVerifiedSupplier($supplier) || ! $ent->has($supplier, 'premium_rfq_leads') || ! $ent->has($supplier, 'supplier_bidding')) {
                continue;
            }
            $match = $this->matches->where('rfq_id', $rfq['id'])->where('supplier_company_id', $sid)->first();
            if ($match && in_array($match['status'], ['invited', 'viewed', 'quoted', 'declined'], true)) {
                continue;
            }
            if ($match) {
                $this->matches->update($match['id'], ['status' => 'invited', 'invited_by' => $adminId, 'invited_at' => date('Y-m-d H:i:s')]);
            } else {
                $this->matches->insert(['rfq_id' => $rfq['id'], 'supplier_company_id' => $sid, 'match_score' => 0, 'match_reasons' => json_encode(['Manually invited by BearingCave']), 'status' => 'invited', 'invited_by' => $adminId, 'invited_at' => date('Y-m-d H:i:s')]);
            }
            service('notifications')->notifyCompany($sid, 'rfq.invited', "New RFQ opportunity {$rfq['rfq_number']}", 'Quotation deadline: ' . date('d M Y H:i', strtotime($rfq['deadline_at'])), 'supplier/rfqs/' . $rfq['id']);
            $invited++;
        }
        if ($invited === 0) {
            throw new BusinessRuleException('No eligible verified suppliers were selected.');
        }
        if (in_array($rfq['status'], ['submitted', 'under_review'], true)) {
            $this->rfqs->update($rfq['id'], ['status' => 'distributed', 'distributed_at' => date('Y-m-d H:i:s'), 'reviewed_by' => $rfq['reviewed_by'] ?: $adminId]);
            service('notifications')->notifyCompany((int) $rfq['buyer_company_id'], 'rfq.distributed', "RFQ {$rfq['rfq_number']} sent to suppliers", "Your RFQ was sent to {$invited} matched verified supplier(s).", 'buyer/rfqs/' . $rfq['id']);
        }
        service('audit')->log('rfq.distributed', ['entity_type' => 'rfq', 'entity_id' => (int) $rfq['id'], 'description' => "{$invited} supplier(s) invited"]);

        return $invited;
    }

    /**
     * Supplier-side access check: supplier must have been invited.
     */
    public function matchForSupplier(int $rfqId, int $supplierId): ?array
    {
        return $this->matches->where('rfq_id', $rfqId)->where('supplier_company_id', $supplierId)
            ->whereIn('status', ['invited', 'viewed', 'quoted', 'declined'])->first();
    }

    public function markViewed(array $match): void
    {
        if ($match['status'] === 'invited') {
            $this->matches->update($match['id'], ['status' => 'viewed', 'viewed_at' => date('Y-m-d H:i:s')]);
        }
    }

    public function decline(array $match, string $reason): void
    {
        if (! in_array($match['status'], ['invited', 'viewed'], true)) {
            throw new BusinessRuleException('You have already responded to this RFQ.');
        }
        $this->matches->update($match['id'], ['status' => 'declined', 'decline_reason' => mb_substr($reason, 0, 255), 'responded_at' => date('Y-m-d H:i:s')]);
    }

    public function cancel(array $rfq, int $userId, string $reason): void
    {
        if (! in_array($rfq['status'], ['submitted', 'under_review', 'distributed', 'evaluation'], true)) {
            throw new BusinessRuleException('This RFQ can no longer be cancelled.');
        }
        $this->rfqs->update($rfq['id'], ['status' => 'cancelled', 'admin_notes' => trim(($rfq['admin_notes'] ?? '') . "\nCancelled: " . $reason)]);
        db_connect()->table('quotations')->where('rfq_id', $rfq['id'])->whereIn('status', ['submitted', 'shortlisted'])->update(['status' => 'rejected', 'buyer_decision_note' => 'RFQ cancelled by buyer']);
        foreach ($this->matches->where('rfq_id', $rfq['id'])->whereIn('status', ['invited', 'viewed', 'quoted'])->findAll() as $m) {
            service('notifications')->notifyCompany((int) $m['supplier_company_id'], 'rfq.cancelled', "RFQ {$rfq['rfq_number']} cancelled", 'The buyer cancelled this request.', 'supplier/rfqs');
        }
        service('audit')->log('rfq.cancelled', ['entity_type' => 'rfq', 'entity_id' => (int) $rfq['id'], 'company_id' => (int) $rfq['buyer_company_id'], 'description' => $reason]);
    }

    /**
     * Viewer object representing the buyer company (used so that matching
     * respects the same visibility/country rules the buyer is subject to).
     */
    public function buyerViewer(array $rfq): Viewer
    {
        $buyer = model(CompanyModel::class)->find($rfq['buyer_company_id']);
        $ent   = service('entitlements');
        $prof  = db_connect()->table('buyer_profiles')->where('company_id', $buyer['id'])->get()->getRowArray();

        return new Viewer(
            null,
            $buyer,
            $rfq['destination_country'] ?: $buyer['country_code'],
            false,
            $ent->isVerifiedBuyer($buyer),
            $ent->isVerifiedBuyer($buyer) && $ent->has($buyer, 'restricted_inventory_access') && ! empty($prof['restricted_inventory_access']),
        );
    }
}

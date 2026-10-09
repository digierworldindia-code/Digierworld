<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\DisputeMessageModel;
use App\Models\DisputeModel;
use App\Models\OrderModel;

/**
 * Dispute management. Buyers and suppliers keep responsibility for product
 * quality and warranty under their commercial terms; BearingCave manages the
 * documentation trail, its own platform/inspection service issues and the
 * agreed intervention process (legal terms pending client approval).
 */
class DisputeService
{
    public const CATEGORIES = [
        'quality' => 'Product quality', 'quantity_shortfall' => 'Quantity shortfall', 'wrong_item' => 'Wrong item supplied',
        'damaged_goods' => 'Damaged goods', 'delayed_delivery' => 'Delayed delivery', 'documentation' => 'Documentation issue',
        'payment' => 'Payment issue', 'inspection_service' => 'BearingCave inspection service', 'platform_service' => 'BearingCave platform issue', 'other' => 'Other',
    ];

    public const STATUSES = [
        'open' => 'Open', 'awaiting_response' => 'Awaiting response', 'under_review' => 'Under review',
        'resolved' => 'Resolved', 'rejected' => 'Rejected', 'closed' => 'Closed',
    ];

    public function __construct(private DisputeModel $disputes = new DisputeModel())
    {
    }

    public function create(array $company, int $userId, int $orderId, array $data): array
    {
        $order = service('orders')->forParty($orderId, (int) $company['id'], $company['company_type']);
        if (! $order) {
            throw new BusinessRuleException('Order not found.');
        }
        if (! in_array($order['status'], ['paid', 'in_fulfilment', 'shipped', 'delivered', 'completed', 'disputed', 'awaiting_payment', 'confirmed'], true)) {
            throw new BusinessRuleException('Disputes can be raised on confirmed orders only.');
        }
        if (! isset(self::CATEGORIES[$data['category'] ?? ''])) {
            throw new BusinessRuleException('Choose a dispute category.');
        }
        if (trim((string) ($data['subject'] ?? '')) === '' || mb_strlen(trim((string) ($data['description'] ?? ''))) < 20) {
            throw new BusinessRuleException('Please add a subject and describe the issue (at least 20 characters).');
        }
        $against = (int) $order['buyer_company_id'] === (int) $company['id'] ? (int) $order['supplier_company_id'] : (int) $order['buyer_company_id'];
        $id      = (int) $this->disputes->insert([
            'dispute_number'       => service('numbers')->next('DSP'),
            'order_id'             => $order['id'],
            'raised_by_company_id' => $company['id'],
            'against_company_id'   => $against,
            'raised_by'            => $userId,
            'category'             => $data['category'],
            'subject'              => mb_substr(trim($data['subject']), 0, 191),
            'description'          => trim($data['description']),
            'desired_resolution'   => $data['desired_resolution'] ?? null,
            'status'               => 'awaiting_response',
        ]);
        if (in_array($order['status'], ['delivered', 'completed'], true)) {
            service('orders')->transition($order, 'disputed', 'system', $userId, 'Dispute raised');
        }
        $d = $this->disputes->find($id);
        $againstCompany = model(\App\Models\CompanyModel::class)->find($against);
        service('notifications')->notifyCompany($against, 'dispute.new', 'Dispute raised on ' . $order['order_number'], $d['subject'], $againstCompany['company_type'] . '/disputes/' . $id);
        service('notifications')->notifyStaff(['support_executive'], 'dispute.new', 'New dispute ' . $d['dispute_number'], $d['subject'], 'admin/disputes/' . $id);
        service('audit')->log('dispute.created', ['entity_type' => 'dispute', 'entity_id' => $id, 'company_id' => (int) $company['id']]);

        return $d;
    }

    public function forParty(int $id, int $companyId): ?array
    {
        $d = $this->disputes->find($id);
        if (! $d || ! in_array($companyId, [(int) $d['raised_by_company_id'], (int) $d['against_company_id']], true)) {
            service('audit')->security('access.denied', "Company #{$companyId} tried to open dispute #{$id}", ['entity_type' => 'dispute', 'entity_id' => $id, 'company_id' => $companyId]);

            return null;
        }

        return $d;
    }

    public function message(array $d, int $userId, string $side, string $message, bool $internal = false): void
    {
        if (in_array($d['status'], ['closed'], true)) {
            throw new BusinessRuleException('This dispute is closed.');
        }
        if (trim($message) === '') {
            throw new BusinessRuleException('Message cannot be empty.');
        }
        model(DisputeMessageModel::class)->insert(['dispute_id' => $d['id'], 'user_id' => $userId, 'sender_side' => $side, 'message' => $message, 'is_internal' => $internal ? 1 : 0]);
        if ($internal) {
            return;
        }
        if ($side !== 'admin' && $d['status'] === 'awaiting_response') {
            $this->disputes->update($d['id'], ['status' => 'under_review']);
        }
        foreach ([(int) $d['raised_by_company_id'], (int) $d['against_company_id']] as $cid) {
            $c = model(\App\Models\CompanyModel::class)->find($cid);
            service('notifications')->notifyCompany($cid, 'dispute.update', 'Dispute update ' . $d['dispute_number'], mb_substr($message, 0, 200), $c['company_type'] . '/disputes/' . $d['id']);
        }
    }

    public function messages(int $disputeId, bool $includeInternal): array
    {
        $q = model(DisputeMessageModel::class)->where('dispute_id', $disputeId);
        if (! $includeInternal) {
            $q->where('is_internal', 0);
        }

        return $q->orderBy('id')->findAll();
    }

    public function assign(array $d, ?int $userId): void
    {
        $this->disputes->update($d['id'], ['assigned_to' => $userId, 'status' => in_array($d['status'], ['open', 'awaiting_response'], true) ? 'under_review' : $d['status']]);
    }

    public function resolve(array $d, string $status, string $notes, int $userId): void
    {
        if (! in_array($status, ['resolved', 'rejected', 'closed'], true)) {
            throw new BusinessRuleException('Invalid resolution status.');
        }
        if (trim($notes) === '') {
            throw new BusinessRuleException('Resolution notes are required.');
        }
        $update = ['status' => $status, 'resolution_notes' => $notes];
        if ($status !== 'closed') {
            $update['resolved_at'] = date('Y-m-d H:i:s');
        } else {
            $update['closed_at'] = date('Y-m-d H:i:s');
        }
        $this->disputes->update($d['id'], $update);
        $order = model(OrderModel::class)->find($d['order_id']);
        $open  = $this->disputes->where('order_id', $d['order_id'])->whereIn('status', ['open', 'awaiting_response', 'under_review'])->countAllResults();
        if ($order && $order['status'] === 'disputed' && $open === 0) {
            service('orders')->transition($order, 'completed', 'staff', $userId, 'Dispute ' . $d['dispute_number'] . ' ' . $status);
        }
        foreach ([(int) $d['raised_by_company_id'], (int) $d['against_company_id']] as $cid) {
            $c = model(\App\Models\CompanyModel::class)->find($cid);
            service('notifications')->notifyCompany($cid, 'dispute.update', 'Dispute ' . $d['dispute_number'] . ' ' . self::STATUSES[$status], $notes, $c['company_type'] . '/disputes/' . $d['id']);
        }
        service('audit')->log('dispute.' . $status, ['entity_type' => 'dispute', 'entity_id' => (int) $d['id'], 'description' => $notes]);
    }
}

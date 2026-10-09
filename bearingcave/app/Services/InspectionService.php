<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\InspectionReportModel;
use App\Models\InspectionRequestModel;
use App\Models\ProductModel;

/**
 * Optional inspection services.
 *
 * In-house: estimated at the configured % of invoice value (spreadsheet:
 * 10%), with an optional configurable minimum charge (pending client
 * decision). Third-party: price depends on volume and location, so it is
 * always quoted manually by the Inspection Manager. Reports are only
 * recorded with an uploaded evidence document — nothing is auto-passed.
 */
class InspectionService
{
    public const SCOPES = [
        'physical'       => 'Physical inspection',
        'quantity'       => 'Quantity verification',
        'packaging'      => 'Packaging inspection',
        'authenticity'   => 'Batch authenticity checks',
        'test_certificates' => 'Relevant test certificates review',
    ];

    public const STATUSES = [
        'requested' => 'Requested', 'quoted' => 'Quoted', 'accepted' => 'Quote accepted', 'declined' => 'Quote declined',
        'assigned' => 'Inspector assigned', 'in_progress' => 'In progress', 'report_uploaded' => 'Report uploaded',
        'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ];

    public function __construct(private InspectionRequestModel $requests = new InspectionRequestModel())
    {
    }

    /**
     * @return array{fee:float|null,basis:string}
     */
    public function estimate(string $type, ?float $invoiceValue): array
    {
        if ($type === 'third_party') {
            return ['fee' => null, 'basis' => 'Third-party inspection is quoted by BearingCave based on shipment volume and location.'];
        }
        $pct = (float) policy('inspection.in_house_rate_pct', 10);
        $min = (float) policy('inspection.in_house_min_fee', 0);
        if ($invoiceValue === null || $invoiceValue <= 0) {
            return ['fee' => null, 'basis' => "In-house inspection: {$pct}% of total invoice value (invoice value required for an estimate)."];
        }
        $fee   = round($invoiceValue * $pct / 100, 2);
        $basis = "In-house inspection: {$pct}% of invoice value " . number_format($invoiceValue, 2);
        if ($min > 0 && $fee < $min) {
            $fee = $min;
            $basis .= ", minimum charge {$min} applied";
        }

        return ['fee' => $fee, 'basis' => $basis . '. Final fee confirmed in the quotation.'];
    }

    public function create(array $company, int $userId, array $data): array
    {
        $type = $data['inspection_type'] ?? '';
        if (! in_array($type, ['in_house', 'third_party'], true)) {
            throw new BusinessRuleException('Choose in-house or third-party inspection.');
        }
        $scope = array_values(array_intersect((array) ($data['scope'] ?? []), array_keys(self::SCOPES)));
        if ($scope === []) {
            throw new BusinessRuleException('Select at least one inspection service.');
        }
        $orderId = null;
        $invoice = ($data['invoice_value'] ?? '') !== '' ? (float) $data['invoice_value'] : null;
        $currency = $data['currency'] ?? 'INR';
        if (! empty($data['order_id'])) {
            $order = service('orders')->forParty((int) $data['order_id'], (int) $company['id'], $company['company_type']);
            if (! $order) {
                throw new BusinessRuleException('Order not found.');
            }
            $orderId  = (int) $order['id'];
            $invoice  = (float) $order['subtotal'];
            $currency = $order['currency'];
        }
        $productId = null;
        if (! empty($data['product_id'])) {
            $p = model(ProductModel::class)->find((int) $data['product_id']);
            if (! $p || ! service('visibility')->canView($p)) {
                throw new BusinessRuleException('Product not found.');
            }
            $productId = (int) $p['id'];
        }
        $est = $this->estimate($type, $invoice);
        $id  = (int) $this->requests->insert([
            'request_number'      => service('numbers')->next('INSP'),
            'company_id'          => $company['id'],
            'requested_by'        => $userId,
            'order_id'            => $orderId,
            'product_id'          => $productId,
            'inspection_type'     => $type,
            'scope'               => json_encode($scope),
            'location_country'    => $data['location_country'],
            'location_city'       => $data['location_city'] ?? null,
            'invoice_value'       => $invoice,
            'shipment_volume_cbm' => ($data['shipment_volume_cbm'] ?? '') !== '' ? $data['shipment_volume_cbm'] : null,
            'currency'            => $currency,
            'estimated_fee'       => $est['fee'],
            'fee_basis'           => $est['basis'],
            'status'              => 'requested',
            'notes'               => $data['notes'] ?? null,
        ]);
        if ($productId) {
            model(ProductModel::class)->update($productId, ['inspection_status' => 'requested']);
        }
        $req = $this->requests->find($id);
        service('notifications')->notifyStaff(['inspection_manager'], 'inspection.new', 'New inspection request ' . $req['request_number'], self::SCOPES[$scope[0]] . ' — ' . $type, 'admin/inspections/' . $id);
        service('audit')->log('inspection.requested', ['entity_type' => 'inspection_request', 'entity_id' => $id, 'company_id' => (int) $company['id']]);

        return $req;
    }

    public function quote(array $req, float $fee, ?string $validUntil, ?string $basis, int $userId): void
    {
        $this->assertStatus($req, ['requested', 'quoted', 'declined']);
        if ($fee <= 0) {
            throw new BusinessRuleException('Enter a fee greater than zero.');
        }
        $this->requests->update($req['id'], ['quoted_fee' => $fee, 'quote_valid_until' => $validUntil ?: null, 'fee_basis' => $basis ?: $req['fee_basis'], 'status' => 'quoted']);
        service('notifications')->notifyCompany((int) $req['company_id'], 'inspection.quoted', 'Inspection quotation ' . $req['request_number'], 'Fee: ' . $req['currency'] . ' ' . number_format($fee, 2), $this->ownerLink($req));
        service('audit')->log('inspection.quoted', ['entity_type' => 'inspection_request', 'entity_id' => (int) $req['id']]);
    }

    public function respond(array $req, bool $accept): void
    {
        $this->assertStatus($req, ['quoted']);
        if ($accept && $req['quote_valid_until'] && $req['quote_valid_until'] < date('Y-m-d')) {
            throw new BusinessRuleException('This quotation has expired. Please ask for a new quote.');
        }
        $this->requests->update($req['id'], ['status' => $accept ? 'accepted' : 'declined']);
        if ($accept) {
            $inv = service('billing')->issueInvoice((int) $req['company_id'], 'inspection', $req['currency'], (float) $req['quoted_fee'], 'inspection_request', (int) $req['id'], 'Inspection ' . $req['request_number']);
            $this->requests->update($req['id'], ['invoice_id' => $inv['id']]);
            if ($req['order_id']) {
                service('orders')->addFee((int) $req['order_id'], 'inspection', (float) $req['quoted_fee']);
            }
        }
        service('notifications')->notifyStaff(['inspection_manager'], 'inspection.response', 'Inspection quote ' . ($accept ? 'accepted' : 'declined'), $req['request_number'], 'admin/inspections/' . $req['id']);
    }

    public function assign(array $req, ?int $inspectorId, ?string $agency, ?string $scheduledOn): void
    {
        $this->assertStatus($req, ['accepted', 'assigned']);
        if (! $inspectorId && trim((string) $agency) === '') {
            throw new BusinessRuleException('Choose an in-house inspector or enter the third-party agency.');
        }
        $this->requests->update($req['id'], ['inspector_user_id' => $inspectorId, 'third_party_agency' => $agency ?: null, 'scheduled_on' => $scheduledOn ?: null, 'status' => 'assigned']);
        service('notifications')->notifyCompany((int) $req['company_id'], 'inspection.assigned', 'Inspection scheduled ' . $req['request_number'], $scheduledOn ? 'Scheduled for ' . date('d M Y', strtotime($scheduledOn)) : 'Inspector assigned', $this->ownerLink($req));
    }

    public function start(array $req): void
    {
        $this->assertStatus($req, ['assigned']);
        $this->requests->update($req['id'], ['status' => 'in_progress']);
    }

    public function uploadReport(array $req, array $data, ?\CodeIgniter\Files\File $file, int $userId): void
    {
        $this->assertStatus($req, ['assigned', 'in_progress']);
        if ($file === null) {
            throw new BusinessRuleException('Attach the signed inspection report or evidence file.');
        }
        if (! in_array($data['result'] ?? '', ['pass', 'fail', 'conditional'], true) || trim((string) ($data['summary'] ?? '')) === '' || empty($data['inspected_on'])) {
            throw new BusinessRuleException('Result, inspection date and summary are required.');
        }
        $db = db_connect();
        $db->transBegin();

        try {
            $doc = service('documents')->store($file, ['company_id' => (int) $req['company_id'], 'entity_type' => 'inspection_request', 'entity_id' => (int) $req['id'], 'doc_type' => 'inspection_report', 'title' => 'Inspection report ' . $req['request_number'], 'review_status' => 'approved'], ['pdf', 'jpg', 'jpeg', 'png']);
            model(InspectionReportModel::class)->insert([
                'inspection_request_id' => $req['id'], 'result' => $data['result'], 'inspected_on' => $data['inspected_on'],
                'quantity_expected' => ($data['quantity_expected'] ?? '') !== '' ? (int) $data['quantity_expected'] : null,
                'quantity_verified' => ($data['quantity_verified'] ?? '') !== '' ? (int) $data['quantity_verified'] : null,
                'packaging_ok' => empty($data['packaging_ok']) ? 0 : 1, 'authenticity_notes' => $data['authenticity_notes'] ?? null,
                'summary' => $data['summary'], 'findings' => $data['findings'] ?? null, 'document_id' => $doc['id'], 'uploaded_by' => $userId,
            ]);
            $this->requests->update($req['id'], ['status' => 'report_uploaded']);
            if ($req['product_id']) {
                model(ProductModel::class)->update($req['product_id'], ['inspection_status' => $data['result'] === 'fail' ? 'failed' : 'passed']);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
        service('notifications')->notifyCompany((int) $req['company_id'], 'inspection.result', 'Inspection result: ' . strtoupper($data['result']), $req['request_number'] . ' — ' . mb_substr($data['summary'], 0, 150), $this->ownerLink($req));
        if ($req['order_id'] && ($order = model(\App\Models\OrderModel::class)->find($req['order_id']))) {
            $other = (int) $order['buyer_company_id'] === (int) $req['company_id'] ? (int) $order['supplier_company_id'] : (int) $order['buyer_company_id'];
            service('notifications')->notifyCompany($other, 'inspection.result', 'Inspection report available for ' . $order['order_number'], 'Result: ' . strtoupper($data['result']), null);
        }
        service('audit')->log('inspection.report_uploaded', ['entity_type' => 'inspection_request', 'entity_id' => (int) $req['id'], 'description' => $data['result']]);
    }

    public function complete(array $req): void
    {
        $this->assertStatus($req, ['report_uploaded']);
        $this->requests->update($req['id'], ['status' => 'completed']);
        service('notifications')->notifyCompany((int) $req['company_id'], 'inspection.completed', 'Inspection completed ' . $req['request_number'], '', $this->ownerLink($req));
    }

    public function cancel(array $req, string $reason): void
    {
        $this->assertStatus($req, ['requested', 'quoted', 'accepted', 'declined']);
        $this->requests->update($req['id'], ['status' => 'cancelled', 'admin_notes' => trim(($req['admin_notes'] ?? '') . "\nCancelled: " . $reason)]);
    }

    public function report(int $requestId): ?array
    {
        return model(InspectionReportModel::class)->where('inspection_request_id', $requestId)->first();
    }

    private function assertStatus(array $req, array $allowed): void
    {
        if (! in_array($req['status'], $allowed, true)) {
            throw new BusinessRuleException('This action is not available while the request is "' . (self::STATUSES[$req['status']] ?? $req['status']) . '".');
        }
    }

    private function ownerLink(array $req): string
    {
        $c = model(\App\Models\CompanyModel::class)->find($req['company_id']);

        return ($c['company_type'] ?? 'buyer') . '/inspections/' . $req['id'];
    }
}

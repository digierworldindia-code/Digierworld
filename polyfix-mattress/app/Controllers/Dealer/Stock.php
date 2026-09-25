<?php

namespace App\Controllers\Dealer;

use App\Exceptions\AppException;
use App\Services\LogisticsService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Scanning, stock and incoming consignments.
 *
 * Scanning needs no app and no JavaScript library: the phone's own camera
 * opens the QR label's verification URL, and a signed-in dealer is offered the
 * portal view of that unit. Typing the serial does the same thing.
 */
class Stock extends DealerController
{
    public function scan(): string
    {
        $serial = strtoupper(trim((string) $this->request->getGet('serial')));
        $unit   = null;
        $error  = null;

        if ($serial !== '') {
            if (preg_match('/^[A-Z]{3}[0-9]{8,12}$/', $serial) !== 1) {
                $error = 'That does not look like a serial number. They are three letters followed by digits, for example ' . brand('serialPrefix') . '26000001.';
            } else {
                $unit = $this->lookup($serial);
                if ($unit === null) {
                    // "Not yours" and "does not exist" read the same.
                    $error = 'No mattress of yours carries that serial number. Check the law label, or ask ' . brand('shortName') . ' if it should be here.';
                }
            }
        }

        return $this->render('dealer/scan', 'Scan', 'scan', ['serial' => $serial, 'unit' => $unit, 'error' => $error]);
    }

    public function inventory(): string
    {
        $db      = db_connect();
        $q       = trim((string) $this->request->getGet('q'));
        $builder = $this->scope->apply(
            $db->table('mattresses m')->select('m.id, m.serial_number, m.current_status, m.received_at, p.name product, v.size_label, v.mrp')
                ->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id'),
            'mattresses', 'm',
        )->where('m.deleted_at', null)->where('m.current_status', 'DEALER_RECEIVED')->orderBy('m.received_at', 'DESC');
        if ($q !== '') {
            $builder->like('m.serial_number', strtoupper(str_replace(['%', '_'], ['\%', '\_'], $q)));
        }

        $summary = $this->scope->apply(
            $db->table('mattresses m')->select('p.name product, v.size_label, COUNT(*) units')
                ->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id'),
            'mattresses', 'm',
        )->where('m.deleted_at', null)->where('m.current_status', 'DEALER_RECEIVED')
            ->groupBy(['p.name', 'v.size_label'])->orderBy('p.name')->get()->getResultArray();

        return $this->render('dealer/inventory', 'Stock', 'inventory', [
            'list' => $this->paginate($builder), 'summary' => $summary, 'q' => $q,
        ]);
    }

    public function incoming(): string
    {
        $builder = $this->scope->apply(
            db_connect()->table('dispatches')->select('id, dispatch_code, status, dispatched_at, expected_at, transporter, lr_number, invoice_number,
                (SELECT COUNT(*) FROM dispatch_items i WHERE i.dispatch_id = dispatches.id) units', false),
            'dispatches',
        )->where('deleted_at', null)->whereIn('status', ['DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED'])
            ->orderBy('dispatched_at', 'DESC');

        return $this->render('dealer/incoming', 'Incoming stock', 'inventory', ['list' => $this->paginate($builder)]);
    }

    public function consignment(string $id): string
    {
        $db       = db_connect();
        $dispatch = $this->scope->assertOwns('dispatches',
            $db->table('dispatches')->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray(), 'consignment');

        $items = $db->query(
            "SELECT m.id, m.serial_number, m.current_status, p.name product, v.size_label,
                    (SELECT ri.condition FROM dealer_receipt_items ri JOIN dealer_receipts r ON r.id = ri.receipt_id
                      WHERE r.dispatch_id = ? AND ri.mattress_id = m.id ORDER BY r.received_at DESC LIMIT 1) received_condition
               FROM dispatch_items di JOIN mattresses m ON m.id = di.mattress_id
               JOIN product_variants v ON v.id = m.product_variant_id JOIN products p ON p.id = v.product_id
              WHERE di.dispatch_id = ? ORDER BY m.serial_number",
            [$id, $id],
        )->getResultArray();

        return $this->render('dealer/consignment', 'Consignment ' . $dispatch['dispatch_code'], 'inventory', [
            'd' => $dispatch, 'items' => $items,
        ]);
    }

    /** Confirms a consignment unit by unit: received, damaged, or not on the vehicle. */
    public function receive(string $id): RedirectResponse
    {
        $conditions = (array) $this->request->getPost('condition');
        $notes      = (array) $this->request->getPost('note');
        $lines      = [];
        foreach ($conditions as $serial => $condition) {
            $serial = strtoupper(trim((string) $serial));
            if ($serial === '' || ! in_array($condition, ['OK', 'DAMAGED', 'MISSING'], true)) {
                continue;
            }
            $lines[] = ['serial' => $serial, 'condition' => $condition, 'remarks' => mb_substr(trim((string) ($notes[$serial] ?? '')), 0, 300) ?: null];
        }

        return $this->act(function () use ($id, $lines) {
            if ($lines === []) {
                throw AppException::rule('Mark at least one mattress before confirming.');
            }

            return LogisticsService::instance()->receive($id, $lines, $this->post('remarks', 500) ?: null);
        }, static function ($r): string {
            // `received` counts every unit taken into stock, damaged ones included.
            $good  = $r['received'] - $r['damaged'];
            $parts = [$good . ' into stock'];
            if ($r['damaged'] > 0) {
                $parts[] = $r['damaged'] . ' received damaged';
            }
            if ($r['missing'] > 0) {
                $parts[] = $r['missing'] . ' not on the vehicle';
            }

            return implode(', ', $parts) . '. ' . ($r['status'] === 'RECEIVED'
                ? 'The consignment is complete.'
                : 'The rest of the consignment is still outstanding.')
                . ($r['damaged'] + $r['missing'] > 0 ? ' ' . brand('shortName') . ' has been told.' : '');
        }, site_url('dealer/incoming/' . $id));
    }

    /** One unit of this dealer's, with what they can do with it next. */
    private function lookup(string $serial): ?array
    {
        $db   = db_connect();
        $unit = $db->table('mattresses m')
            ->select('m.id, m.serial_number, m.current_status, m.current_dealer_id, m.manufactured_at, m.received_at, m.sold_at, m.is_replacement, m.grade_note,
                      p.name product, p.slug, p.comfort_level, p.warranty_years, v.size_label, v.mrp')
            ->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id')
            ->where('m.serial_number', $serial)->where('m.deleted_at', null)->get()->getRowArray();

        if ($unit === null || $unit['current_dealer_id'] !== $this->scope->dealerId) {
            return null;
        }

        $unit['warranty'] = $db->table('warranties')->select('status, start_date, end_date, years')
            ->where(['mattress_id' => $unit['id'], 'deleted_at' => null])->orderBy('created_at', 'DESC')->get()->getRowArray();
        $unit['sale'] = $db->table('sales s')->select('s.invoice_number, s.sold_at, c.full_name, c.city')
            ->join('customers c', 'c.id = s.customer_id')
            ->where(['s.mattress_id' => $unit['id'], 's.deleted_at' => null])->orderBy('s.sold_at', 'DESC')->get()->getRowArray();
        $unit['claims'] = $this->scope->apply($db->table('warranty_claims'), 'warranty_claims')
            ->select('id, claim_number, status, submitted_at, reported_issue')
            ->where(['mattress_id' => $unit['id'], 'deleted_at' => null])->orderBy('submitted_at', 'DESC')->limit(3)->get()->getResultArray();

        $unit['actions'] = array_values(array_filter([
            $unit['current_status'] === 'DISPATCHED' ? 'RECEIVE' : null,
            $unit['current_status'] === 'DEALER_RECEIVED' ? 'SELL' : null,
            $unit['current_status'] === 'SOLD' ? 'CLAIM' : null,
        ]));

        return $unit;
    }
}

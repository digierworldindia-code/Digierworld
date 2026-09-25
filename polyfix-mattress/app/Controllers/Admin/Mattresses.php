<?php

namespace App\Controllers\Admin;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Labels;
use App\Libraries\Tx;
use App\Services\Lifecycle;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\RedirectResponse;

/** Mattress records: the list, the full "digital passport" of one unit, and its label. */
class Mattresses extends AdminController
{
    private const STATUSES = ['MANUFACTURED', 'IN_DISPATCH', 'DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED', 'RETURNED', 'SCRAPPED'];

    public function index(): string
    {
        $db      = db_connect();
        $status  = $this->oneOf('status', self::STATUSES);
        $dealer  = $this->filter('dealer', 36);
        $batch   = $this->filter('batch', 36);
        $product = $this->filter('product', 36);
        $q       = $this->filter('q', 40);

        $builder = $db->table('mattresses m')
            ->select('m.id, m.serial_number, m.current_status, m.manufactured_at, m.sold_at, m.is_replacement, v.size_label, p.name product, b.batch_code, d.business_name dealer')
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('manufacturing_batches b', 'b.id = m.batch_id')
            ->join('dealers d', 'd.id = m.current_dealer_id', 'left')
            ->where('m.deleted_at', null)
            ->orderBy('m.manufactured_at', 'DESC')->orderBy('m.serial_number', 'DESC');
        if ($status) {
            $builder->where('m.current_status', $status);
        }
        if ($dealer && is_uuid($dealer)) {
            $builder->where('m.current_dealer_id', $dealer);
        }
        if ($batch && is_uuid($batch)) {
            $builder->where('m.batch_id', $batch);
        }
        if ($product && is_uuid($product)) {
            $builder->where('p.id', $product);
        }
        if ($q) {
            $builder->like('m.serial_number', strtoupper($this->like($q)), 'both', null, true);
        }

        return $this->render('admin/mattresses/index', 'Mattresses', $status === 'DEALER_RECEIVED' ? 'admin/mattresses?status=DEALER_RECEIVED' : 'admin/mattresses', [
            'list'     => $this->paginate($builder),
            'statuses' => self::STATUSES,
            'dealers'  => $db->table('dealers')->select('id, business_name')->where('deleted_at', null)->orderBy('business_name')->get()->getResultArray(),
            'products' => $db->table('products')->select('id, name')->where('deleted_at', null)->orderBy('sort_order')->get()->getResultArray(),
            'f'        => compact('status', 'dealer', 'batch', 'product', 'q'),
        ]);
    }

    public function show(string $id): string
    {
        $db = db_connect();
        $m  = $db->table('mattresses m')
            ->select('m.*, v.sku, v.size_label, v.mrp, p.name product, p.slug, p.warranty_years, b.batch_code, b.id batch_id, w.name plant,
                      d.code dealer_code, d.business_name dealer, d.city dealer_city, d.state dealer_state, d.id dealer_id')
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('manufacturing_batches b', 'b.id = m.batch_id')
            ->join('warehouses w', 'w.id = b.warehouse_id')
            ->join('dealers d', 'd.id = m.current_dealer_id', 'left')
            ->where('m.id', $id)->get()->getRowArray() ?? $this->notFound();

        $sale = $db->table('sales s')->select('s.id, s.invoice_number, s.sold_at, s.sale_price, s.payment_mode, c.full_name, c.city, c.state, d.business_name dealer')
            ->join('customers c', 'c.id = s.customer_id')->join('dealers d', 'd.id = s.dealer_id')
            ->where(['s.mattress_id' => $id, 's.deleted_at' => null])->orderBy('s.sold_at', 'DESC')->get()->getRowArray();

        return $this->render('admin/mattresses/show', $m['serial_number'], 'admin/mattresses', [
            'm'        => $m,
            'warranty' => $db->table('warranties')->where(['mattress_id' => $id, 'deleted_at' => null])->orderBy('created_at', 'DESC')->get()->getRowArray(),
            'sale'     => $sale,
            'claims'   => $db->table('warranty_claims')->select('id, claim_number, status, risk_level, risk_score, submitted_at, reported_issue')
                ->where(['mattress_id' => $id, 'deleted_at' => null])->orderBy('submitted_at', 'DESC')->get()->getResultArray(),
            'replacedBy' => $db->table('replacements r')->select('r.issued_at, n.id, n.serial_number')->join('mattresses n', 'n.id = r.replacement_mattress_id')
                ->where('r.original_mattress_id', $id)->get()->getRowArray(),
            'replacementFor' => $db->table('replacements r')->select('o.id, o.serial_number, c.claim_number, c.id claim_id')->join('mattresses o', 'o.id = r.original_mattress_id')
                ->join('warranty_claims c', 'c.id = r.claim_id')->where('r.replacement_mattress_id', $id)->get()->getRowArray(),
            'events' => $db->table('mattress_events e')->select('e.*, u.full_name actor, d.business_name dealer')
                ->join('users u', 'u.id = e.actor_user_id', 'left')->join('dealers d', 'd.id = e.dealer_id', 'left')
                ->where('e.mattress_id', $id)->orderBy('e.occurred_at')->get()->getResultArray(),
        ]);
    }

    /** Printable label: QR (verification URL with the opaque token) and Code 128 barcode of the serial. */
    public function label(string $id): string
    {
        $m = db_connect()->table('mattresses m')->select('m.id, m.serial_number, m.qr_token, m.manufactured_at, v.size_label, p.name product, b.batch_code')
            ->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id')
            ->join('manufacturing_batches b', 'b.id = m.batch_id')
            ->where(['m.id' => $id, 'm.deleted_at' => null])->get()->getRowArray() ?? $this->notFound();

        return $this->render('admin/mattresses/label', 'Label ' . $m['serial_number'], 'admin/mattresses', [
            'm'       => $m,
            'qr'      => Labels::qrSvg($m['qr_token'], 4),
            'barcode' => Labels::barcodeSvg($m['serial_number']),
            'url'     => Labels::verifyUrl($m['qr_token']),
        ]);
    }

    /** The console search box: a serial, a claim number or a dispatch code. */
    public function lookup(): RedirectResponse
    {
        $q  = strtoupper(preg_replace('/\s+/', '', (string) $this->filter('q', 60)));
        $db = db_connect();

        // A pasted verification URL works too.
        if (preg_match('~[?&]q=([A-Za-z0-9_-]{22,64})~', (string) $this->request->getGet('q'), $mm)) {
            $row = $db->table('mattresses')->select('id')->where('qr_token', $mm[1])->get()->getRow();

            return $row ? redirect()->to(site_url('admin/mattresses/' . $row->id)) : redirect()->back()->with('error', 'No mattress carries that QR code.');
        }
        if ($q !== '' && ($row = $db->table('mattresses')->select('id')->where('serial_number', $q)->get()->getRow())) {
            return redirect()->to(site_url('admin/mattresses/' . $row->id));
        }
        if ($q !== '' && $this->ctx->can('claim:read') && ($row = $db->table('warranty_claims')->select('id')->where('claim_number', $q)->get()->getRow())) {
            return redirect()->to(site_url('admin/claims/' . $row->id));
        }
        if ($q !== '' && $this->ctx->can('dispatch:read') && ($row = $db->table('dispatches')->select('id')->where('dispatch_code', $q)->get()->getRow())) {
            return redirect()->to(site_url('admin/dispatches/' . $row->id));
        }

        return redirect()->to(site_url('admin/mattresses') . '?q=' . rawurlencode($q))->with('notice', 'No exact match — showing serials that contain "' . $q . '".');
    }

    /** Soft delete with a reason. A sold unit or one with an open claim is part of a live warranty and cannot be removed. */
    public function delete(string $id): RedirectResponse
    {
        $reason = $this->post('reason', 500);

        return $this->act(function () use ($id, $reason): void {
            if (mb_strlen($reason) < 5) {
                throw AppException::rule('Give a reason of at least 5 characters.');
            }
            Tx::run(function (BaseConnection $db) use ($id, $reason): void {
                $m = $db->query('SELECT id, serial_number, current_status FROM mattresses WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id])->getRowArray()
                    ?? throw AppException::notFound('mattress');
                if (in_array($m['current_status'], ['SOLD', 'CLAIM_OPEN'], true)) {
                    throw AppException::rule('A mattress that has been sold or has an open claim cannot be removed. Its history is part of a live warranty obligation.');
                }
                $db->table('mattresses')->where('id', $id)->update([
                    'deleted_at' => utc_now(), 'deleted_by' => $this->ctx->userId(), 'delete_reason' => $reason,
                ]);
                Audit::instance($db)->record('MATTRESS_DELETED', 'mattress', $id,
                    ['serialNumber' => $m['serial_number'], 'status' => $m['current_status']], ['deleted' => true], $reason);
            }, db_connect());
        }, 'The mattress record was removed. It stays in the audit trail.', site_url('admin/mattresses'));
    }
}

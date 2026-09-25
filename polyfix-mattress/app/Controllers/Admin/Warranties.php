<?php

namespace App\Controllers\Admin;

use App\Services\SalesService;
use CodeIgniter\HTTP\RedirectResponse;

/** Warranty records, and the replacements issued against claims. */
class Warranties extends AdminController
{
    public function index(): string
    {
        $db     = db_connect();
        $status = $this->oneOf('status', ['ACTIVE', 'EXPIRED', 'VOID', 'SUPERSEDED']);
        $dealer = $this->filter('dealer', 36);
        $q      = $this->filter('q', 40);
        $expiring = $this->request->getGet('expiring') === '1';

        $builder = $db->table('warranties w')
            ->select('w.id, w.status, w.start_date, w.end_date, w.years, m.serial_number, m.id mattress_id, p.name product, d.business_name dealer, c.full_name customer')
            ->join('mattresses m', 'm.id = w.mattress_id')->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id')
            ->join('dealers d', 'd.id = w.dealer_id')->join('customers c', 'c.id = w.customer_id', 'left')
            ->where('w.deleted_at', null)->orderBy('w.end_date');
        if ($status) {
            $builder->where('w.status', $status);
        }
        if ($dealer && is_uuid($dealer)) {
            $builder->where('w.dealer_id', $dealer);
        }
        if ($expiring) {
            $builder->where('w.status', 'ACTIVE')->where('w.end_date <=', gmdate('Y-m-d', strtotime('+90 days')))->where('w.end_date >=', utc_today());
        }
        if ($q) {
            $builder->like('m.serial_number', strtoupper($this->like($q)));
        }

        return $this->render('admin/warranties/index', 'Warranties', 'admin/warranties', [
            'list'    => $this->paginate($builder),
            'dealers' => $db->table('dealers')->select('id, business_name')->where('deleted_at', null)->orderBy('business_name')->get()->getResultArray(),
            'f'       => compact('status', 'dealer', 'q', 'expiring'),
        ]);
    }

    public function show(string $id): string
    {
        $db = db_connect();
        $w  = $db->table('warranties w')
            ->select('w.*, m.serial_number, m.id mattress_id, p.name product, v.size_label, d.business_name dealer, d.id dealer_id,
                      c.full_name customer, c.city customer_city, s.invoice_number, s.sold_at, s.sale_price')
            ->join('mattresses m', 'm.id = w.mattress_id')->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id')
            ->join('dealers d', 'd.id = w.dealer_id')->join('customers c', 'c.id = w.customer_id', 'left')->join('sales s', 's.id = w.sale_id', 'left')
            ->where(['w.id' => $id, 'w.deleted_at' => null])->get()->getRowArray() ?? $this->notFound();

        return $this->render('admin/warranties/show', 'Warranty ' . $w['serial_number'], 'admin/warranties', [
            'w'       => $w,
            'claims'  => $db->table('warranty_claims')->select('id, claim_number, status, submitted_at')->where(['warranty_id' => $id, 'deleted_at' => null])->orderBy('submitted_at', 'DESC')->get()->getResultArray(),
            'history' => $db->table('record_versions')->select('version, changed_at, reason')->where(['entity' => 'warranty', 'entity_id' => $id])->orderBy('version', 'DESC')->get()->getResultArray(),
        ]);
    }

    public function void(string $id): RedirectResponse
    {
        return $this->act(
            fn () => SalesService::instance()->voidWarranty($id, $this->post('reason', 500)),
            'Warranty voided. The public verification page now says the cover is no longer valid.',
            site_url('admin/warranties/' . $id),
        );
    }

    public function override(string $id): RedirectResponse
    {
        return $this->act(
            fn () => SalesService::instance()->overrideWarrantyDates($id, $this->post('start_date', 10), $this->post('end_date', 10), $this->post('reason', 500)),
            'Warranty dates changed. The previous values are kept in the record history.',
            site_url('admin/warranties/' . $id),
        );
    }

    public function replacements(): string
    {
        $builder = db_connect()->table('replacements r')
            ->select('r.id, r.issued_at, r.remarks, c.claim_number, c.id claim_id, o.serial_number original, o.id original_id,
                      n.serial_number replacement, n.id replacement_id, d.business_name dealer, u.full_name approved_by')
            ->join('warranty_claims c', 'c.id = r.claim_id')
            ->join('mattresses o', 'o.id = r.original_mattress_id')->join('mattresses n', 'n.id = r.replacement_mattress_id')
            ->join('dealers d', 'd.id = r.dealer_id')->join('users u', 'u.id = r.approved_by_user_id', 'left')
            ->orderBy('r.issued_at', 'DESC');

        return $this->render('admin/warranties/replacements', 'Replacements', 'admin/replacements', ['list' => $this->paginate($builder)]);
    }
}

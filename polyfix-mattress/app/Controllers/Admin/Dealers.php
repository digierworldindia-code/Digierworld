<?php

namespace App\Controllers\Admin;

use App\Services\NetworkService;
use CodeIgniter\HTTP\RedirectResponse;

/** The dealer network. */
class Dealers extends AdminController
{
    private const STATUSES = ['PENDING', 'ACTIVE', 'SUSPENDED', 'TERMINATED'];

    private const RULES = [
        'business_name' => 'required|max_length[180]|safe_text',
        'owner_name'    => 'required|max_length[160]|safe_text',
        'email'         => 'permit_empty|max_length[255]|valid_email',
        'phone'         => 'required|indian_mobile',
        'gst_number'    => 'permit_empty|gstin',
        'address_line1' => 'required|max_length[200]|safe_text',
        'address_line2' => 'permit_empty|max_length[200]|safe_text',
        'city'          => 'required|max_length[80]|safe_text',
        'state'         => 'required|max_length[80]|safe_text',
        'pincode'       => 'required|pincode',
        'notes'         => 'permit_empty|max_length[2000]|safe_text',
    ];

    public function index(): string
    {
        $status = $this->oneOf('status', self::STATUSES);
        $q      = $this->filter('q', 60);
        $builder = db_connect()->table('dealers d')
            ->select('d.id, d.code, d.business_name, d.owner_name, d.phone, d.city, d.state, d.status, d.public_listed, d.is_showroom, d.onboarded_at,
                      (SELECT COUNT(*) FROM mattresses m WHERE m.current_dealer_id = d.id AND m.current_status = "DEALER_RECEIVED" AND m.deleted_at IS NULL) stock,
                      (SELECT COUNT(*) FROM sales s WHERE s.dealer_id = d.id AND s.deleted_at IS NULL) sales,
                      (SELECT COUNT(*) FROM warranty_claims c WHERE c.dealer_id = d.id AND c.deleted_at IS NULL) claims', false)
            ->where('d.deleted_at', null)->orderBy('d.business_name');
        if ($status) {
            $builder->where('d.status', $status);
        }
        if ($q) {
            $term = $this->like($q);
            $builder->groupStart()->like('d.business_name', $term)->orLike('d.code', strtoupper($term))->orLike('d.city', $term)->orLike('d.phone', $term)->groupEnd();
        }

        return $this->render('admin/dealers/index', 'Dealers', 'admin/dealers', [
            'list' => $this->paginate($builder), 'statuses' => self::STATUSES, 'f' => compact('status', 'q'),
        ]);
    }

    public function new(): string
    {
        return $this->render('admin/dealers/form', 'New dealer', 'admin/dealers', ['d' => null]);
    }

    public function create(): RedirectResponse
    {
        $in = $this->validated(self::RULES);
        if ($in instanceof RedirectResponse) {
            return $in;
        }
        $in['public_listed'] = $this->request->getPost('public_listed') === '1';
        $in['is_showroom']   = $this->request->getPost('is_showroom') === '1';

        return $this->act(
            fn () => NetworkService::instance()->createDealer($in),
            static fn ($r) => "Dealer {$r['code']} created.",
            site_url('admin/dealers'),
        );
    }

    public function show(string $id): string
    {
        $db = db_connect();
        $d  = $db->table('dealers')->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray() ?? $this->notFound();

        return $this->render('admin/dealers/show', $d['business_name'], 'admin/dealers', [
            'd'     => $d,
            'users' => $db->table('dealer_users du')->select('u.id, u.email, u.full_name, u.status, u.last_login_at, du.is_primary')
                ->join('users u', 'u.id = du.user_id')->where('du.dealer_id', $id)->orderBy('u.full_name')->get()->getResultArray(),
            'stats' => $db->query(
                'SELECT (SELECT COUNT(*) FROM mattresses WHERE current_dealer_id = ? AND current_status = "DEALER_RECEIVED" AND deleted_at IS NULL) stock,
                        (SELECT COUNT(*) FROM sales WHERE dealer_id = ? AND deleted_at IS NULL) sales,
                        (SELECT COUNT(*) FROM warranty_claims WHERE dealer_id = ? AND deleted_at IS NULL) claims,
                        (SELECT COUNT(*) FROM dispatches WHERE dealer_id = ? AND status IN ("DISPATCHED","PARTIALLY_RECEIVED") AND deleted_at IS NULL) incoming',
                [$id, $id, $id, $id],
            )->getRowArray(),
            'recentSales' => $db->table('sales s')->select('s.id, s.invoice_number, s.sold_at, s.sale_price, m.serial_number, m.id mattress_id')
                ->join('mattresses m', 'm.id = s.mattress_id')->where(['s.dealer_id' => $id, 's.deleted_at' => null])
                ->orderBy('s.sold_at', 'DESC')->limit(10)->get()->getResultArray(),
            'recentClaims' => $db->table('warranty_claims c')->select('c.id, c.claim_number, c.status, c.risk_level, c.submitted_at')
                ->where(['c.dealer_id' => $id, 'c.deleted_at' => null])->orderBy('c.submitted_at', 'DESC')->limit(10)->get()->getResultArray(),
            'versions' => $db->table('record_versions')->select('version, changed_at, reason')->where(['entity' => 'dealer', 'entity_id' => $id])
                ->orderBy('version', 'DESC')->limit(5)->get()->getResultArray(),
        ]);
    }

    public function update(string $id): RedirectResponse
    {
        $in = $this->validated(self::RULES);
        if ($in instanceof RedirectResponse) {
            return $in;
        }
        $in['public_listed'] = $this->request->getPost('public_listed') === '1';
        $in['is_showroom']   = $this->request->getPost('is_showroom') === '1';

        return $this->act(
            fn () => NetworkService::instance()->updateDealer($id, $in),
            static fn ($r) => "{$r['business_name']} updated.",
            site_url('admin/dealers/' . $id),
        );
    }

    public function status(string $id): RedirectResponse
    {
        return $this->act(
            fn () => NetworkService::instance()->changeStatus($id, (string) $this->request->getPost('status'), $this->post('reason', 500)),
            static fn ($r) => "{$r['code']} is now " . strtolower(humanise($r['status'])) . '.',
            site_url('admin/dealers/' . $id),
        );
    }
}

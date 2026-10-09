<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use CodeIgniter\Shield\Models\UserModel;

class Dashboard extends BaseController
{
    public function index(): string
    {
        $c   = $this->company();
        $cid = (int) $c['id'];
        $db  = db_connect();
        $one = static fn (string $sql, array $b) => (int) $db->query($sql, $b)->getRow()->v;
        $ent = service('entitlements');

        return view('supplier/dashboard', [
            'title' => 'Supplier dashboard', 'area' => 'supplier', 'company' => $c,
            'plan' => $ent->plan($c), 'verified' => $ent->isVerifiedSupplier($c),
            'subscription' => service('membership')->activeSubscription($cid), 'pending' => service('membership')->pendingSubscription($cid),
            'kpi' => [
                'published' => $one("SELECT COUNT(*) v FROM products WHERE company_id = ? AND status = 'published' AND deleted_at IS NULL", [$cid]),
                'review'    => $one("SELECT COUNT(*) v FROM products WHERE company_id = ? AND status = 'pending_review' AND deleted_at IS NULL", [$cid]),
                'units'     => $one('SELECT COALESCE(SUM(stock_on_hand),0) v FROM products WHERE company_id = ? AND deleted_at IS NULL', [$cid]),
                'enquiries' => $one("SELECT COUNT(*) v FROM product_enquiries e JOIN products p ON p.id = e.product_id WHERE p.company_id = ? AND e.status = 'open'", [$cid]),
                'rfqs'      => $one("SELECT COUNT(*) v FROM rfq_matches m JOIN rfqs r ON r.id = m.rfq_id WHERE m.supplier_company_id = ? AND m.status IN ('invited','viewed') AND r.deadline_at > NOW() AND r.status IN ('distributed','evaluation')", [$cid]),
                'orders'    => $one("SELECT COUNT(*) v FROM orders WHERE supplier_company_id = ? AND status NOT IN ('completed','cancelled')", [$cid]),
                'to_confirm' => $one("SELECT COUNT(*) v FROM orders WHERE supplier_company_id = ? AND status = 'pending_supplier_confirmation'", [$cid]),
            ],
            'ageing' => $db->query("SELECT
                SUM(CASE WHEN inventory_age_date > DATE_SUB(CURDATE(), INTERVAL 6 MONTH) THEN stock_on_hand ELSE 0 END) lt6,
                SUM(CASE WHEN inventory_age_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND DATE_SUB(CURDATE(), INTERVAL 6 MONTH) THEN stock_on_hand ELSE 0 END) m612,
                SUM(CASE WHEN inventory_age_date < DATE_SUB(CURDATE(), INTERVAL 12 MONTH) THEN stock_on_hand ELSE 0 END) gt12
                FROM products WHERE company_id = ? AND deleted_at IS NULL", [$cid])->getRowArray(),
            'orders' => $db->table('orders')->where('supplier_company_id', $cid)->orderBy('id', 'DESC')->limit(5)->get()->getResultArray(),
            'onboarding' => [
                ['Complete company profile', ! empty($c['address_line1']), 'supplier/company'],
                ['Add your first inventory', $one('SELECT COUNT(*) v FROM products WHERE company_id = ?', [$cid]) > 0, 'supplier/products/new'],
                ['Submit verification', in_array($c['verification_status'], ['in_review', 'verified'], true), 'supplier/verification'],
                ['Activate Verified membership', $ent->isVerifiedSupplier($c), 'supplier/membership'],
            ],
        ]);
    }

    public function accountManager(): string
    {
        $c = $this->company();
        $manager = null;
        if (entitled('dedicated_account_manager', $c) && $c['account_manager_user_id']) {
            $manager = model(UserModel::class)->find($c['account_manager_user_id']);
        }

        return view('supplier/account_manager', ['title' => 'Account manager', 'area' => 'supplier', 'company' => $c, 'manager' => $manager, 'entitled' => entitled('dedicated_account_manager', $c)]);
    }
}

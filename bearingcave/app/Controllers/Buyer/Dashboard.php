<?php

declare(strict_types=1);

namespace App\Controllers\Buyer;

use App\Controllers\BaseController;

class Dashboard extends BaseController
{
    public function index(): string
    {
        $c   = $this->company();
        $cid = (int) $c['id'];
        $db  = db_connect();
        $one = static fn (string $sql, array $b) => (int) $db->query($sql, $b)->getRow()->v;

        $profile = $db->table('buyer_profiles')->where('company_id', $cid)->get()->getRowArray();
        $analytics = entitled('buyer_analytics', $c);
        $searches  = $analytics ? $db->query(
            'SELECT query, COUNT(*) AS searches, MAX(results_count) AS max_results, MAX(created_at) AS last_at FROM search_logs
             WHERE company_id = ? AND query IS NOT NULL AND query <> \'\' AND created_at >= ? GROUP BY query ORDER BY searches DESC, last_at DESC LIMIT 8',
            [$cid, date('Y-m-d H:i:s', strtotime('-90 days'))]
        )->getResultArray() : [];

        return view('buyer/dashboard', [
            'title' => 'Buyer dashboard', 'area' => 'buyer', 'company' => $c, 'profile' => $profile, 'analytics' => $analytics, 'searches' => $searches,
            'kpi' => [
                'open_rfqs'   => $one("SELECT COUNT(*) v FROM rfqs WHERE buyer_company_id = ? AND status IN ('submitted','under_review','distributed','evaluation')", [$cid]),
                'quotations'  => $one("SELECT COUNT(*) v FROM quotations q JOIN rfqs r ON r.id = q.rfq_id WHERE r.buyer_company_id = ? AND q.is_current = 1 AND q.status IN ('submitted','shortlisted')", [$cid]),
                'orders'      => $one("SELECT COUNT(*) v FROM orders WHERE buyer_company_id = ? AND status NOT IN ('completed','cancelled')", [$cid]),
                'saved'       => $one('SELECT COUNT(*) v FROM saved_products WHERE user_id = ?', [$this->userId()]),
                'unpaid'      => $one("SELECT COUNT(*) v FROM invoices WHERE company_id = ? AND status = 'issued'", [$cid]),
                'disputes'    => $one("SELECT COUNT(*) v FROM disputes WHERE (raised_by_company_id = ? OR against_company_id = ?) AND status IN ('open','awaiting_response','under_review')", [$cid, $cid]),
            ],
            'rfqs'   => $db->table('rfqs')->where('buyer_company_id', $cid)->orderBy('id', 'DESC')->limit(5)->get()->getResultArray(),
            'orders' => $db->table('orders')->where('buyer_company_id', $cid)->orderBy('id', 'DESC')->limit(5)->get()->getResultArray(),
            'notifications' => $db->table('notifications')->where('user_id', $this->userId())->orderBy('id', 'DESC')->limit(6)->get()->getResultArray(),
            'onboarding' => [
                ['Complete company profile', ! empty($c['address_line1']), 'buyer/company'],
                ['Add KYC details & documents', $db->table('documents')->where('company_id', $cid)->where('entity_type', 'company')->countAllResults() > 0, 'buyer/verification'],
                ['Submit verification', in_array($c['verification_status'], ['in_review', 'verified'], true), 'buyer/verification'],
                ['Send your first RFQ', $db->table('rfqs')->where('buyer_company_id', $cid)->countAllResults() > 0, 'buyer/rfqs/new'],
            ],
        ]);
    }
}

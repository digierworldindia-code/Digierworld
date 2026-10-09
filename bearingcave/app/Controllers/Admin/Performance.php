<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

class Performance extends AdminController
{
    public function index(): string
    {
        $db = db_connect();

        return $this->page('performance', [
            'title' => 'Performance scores',
            'suppliers' => $db->query("SELECT c.id, c.legal_name, c.is_sample, sp.ranking_score, sp.performance_score, sp.risk_level, p.on_time_delivery_pct, p.quality_rejection_pct, p.cost_competitiveness, p.avg_response_hours, p.rfq_response_rate_pct, p.service_level, p.orders_count, p.computed_at
                FROM companies c JOIN supplier_profiles sp ON sp.company_id = c.id LEFT JOIN supplier_performance p ON p.id = (SELECT MAX(id) FROM supplier_performance WHERE company_id = c.id)
                WHERE c.deleted_at IS NULL ORDER BY sp.ranking_score DESC LIMIT 200")->getResultArray(),
            'buyers' => $db->query('SELECT c.id, c.legal_name, c.verification_status, bp.engagement_score, bp.rfq_genuineness_score, bp.transaction_score, bp.scores_computed_at FROM companies c JOIN buyer_profiles bp ON bp.company_id = c.id WHERE c.deleted_at IS NULL ORDER BY bp.engagement_score DESC LIMIT 200')->getResultArray(),
        ]);
    }

    public function recompute()
    {
        $n = 0;
        foreach (db_connect()->table('companies')->select('id, company_type')->where('deleted_at', null)->get()->getResultArray() as $c) {
            $c['company_type'] === 'supplier' ? service('performance')->computeSupplier((int) $c['id']) : service('performance')->computeBuyer((int) $c['id']);
            $n++;
        }
        service('audit')->log('performance.recomputed', ['description' => "{$n} companies"]);

        return redirect()->back()->with('success', "Scores recalculated for {$n} companies from stored records.");
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BuyerProfileModel;
use App\Models\SupplierPerformanceModel;
use App\Models\SupplierProfileModel;

/**
 * Supplier performance and buyer engagement scores computed ONLY from
 * stored transactions. A metric without data stays NULL ("not enough data")
 * rather than being estimated.
 */
class PerformanceService
{
    public function computeSupplier(int $companyId): array
    {
        $db    = db_connect();
        $days  = (int) policy('performance.window_days', 365);
        $from  = date('Y-m-d', strtotime("-{$days} days"));
        $to    = date('Y-m-d');
        $since = $from . ' 00:00:00';

        $orders = (int) $db->table('orders')->where('supplier_company_id', $companyId)->whereIn('status', ['delivered', 'completed', 'disputed'])->where('created_at >=', $since)->countAllResults();

        $ship = $db->query('SELECT COUNT(*) total, SUM(s.delivered_on_time = 1) ontime FROM shipments s JOIN orders o ON o.id = s.order_id
            WHERE o.supplier_company_id = ? AND s.status = ? AND s.delivered_on_time IS NOT NULL AND s.delivered_at >= ?', [$companyId, 'delivered', $since])->getRowArray();
        $onTime = (int) $ship['total'] > 0 ? round(100 * (int) $ship['ontime'] / (int) $ship['total'], 2) : null;

        $qualityIssues = (int) $db->query("SELECT COUNT(*) c FROM disputes WHERE against_company_id = ? AND category IN ('quality','wrong_item','damaged_goods') AND status = 'resolved' AND created_at >= ?", [$companyId, $since])->getRow()->c;
        $failedInsp    = (int) $db->query("SELECT COUNT(*) c FROM inspection_reports r JOIN inspection_requests q ON q.id = r.inspection_request_id JOIN orders o ON o.id = q.order_id WHERE o.supplier_company_id = ? AND r.result = 'fail' AND r.created_at >= ?", [$companyId, $since])->getRow()->c;
        $rejection     = $orders > 0 ? round(100 * min($orders, $qualityIssues + $failedInsp) / $orders, 2) : null;

        $resp = $db->query("SELECT COUNT(*) invited, SUM(status IN ('quoted','declined')) responded,
            AVG(CASE WHEN responded_at IS NOT NULL AND invited_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, invited_at, responded_at) END) mins
            FROM rfq_matches WHERE supplier_company_id = ? AND invited_at >= ?", [$companyId, $since])->getRowArray();
        $responseRate = (int) $resp['invited'] > 0 ? round(100 * (int) $resp['responded'] / (int) $resp['invited'], 2) : null;
        $avgHours     = $resp['mins'] !== null ? round((float) $resp['mins'] / 60, 2) : null;

        // Cost competitiveness: lowest offer on the RFQ / this supplier's offer.
        $cmp = $db->query("SELECT AVG(best.min_total / q.total_amount) r FROM quotations q
            JOIN (SELECT rfq_id, MIN(total_amount) min_total FROM quotations WHERE is_current = 1 AND total_amount > 0 GROUP BY rfq_id HAVING COUNT(*) > 1) best ON best.rfq_id = q.rfq_id
            WHERE q.supplier_company_id = ? AND q.is_current = 1 AND q.total_amount > 0 AND q.created_at >= ?", [$companyId, $since])->getRow()->r;
        $cost = $cmp !== null ? round(100 * (float) $cmp, 2) : null;

        $disputes = (int) $db->table('disputes')->where('against_company_id', $companyId)->where('created_at >=', $since)->countAllResults();
        $service  = $orders > 0 ? round(max(0, 100 - 100 * $disputes / $orders), 2) : null;

        $parts   = array_filter([$onTime, $rejection !== null ? 100 - $rejection : null, $cost, $responseRate, $service], static fn ($v) => $v !== null);
        $overall = $parts ? round(array_sum($parts) / count($parts), 2) : null;

        $row = [
            'company_id' => $companyId, 'period_start' => $from, 'period_end' => $to, 'orders_count' => $orders,
            'on_time_delivery_pct' => $onTime, 'quality_rejection_pct' => $rejection, 'cost_competitiveness' => $cost,
            'avg_response_hours' => $avgHours, 'rfq_response_rate_pct' => $responseRate, 'service_level' => $service,
            'overall_score' => $overall, 'source' => 'computed', 'computed_at' => date('Y-m-d H:i:s'),
        ];
        $m = model(SupplierPerformanceModel::class);
        if ($existing = $m->where('company_id', $companyId)->where('period_start', $from)->where('period_end', $to)->first()) {
            $m->update($existing['id'], $row);
        } else {
            $m->insert($row);
        }
        model(SupplierProfileModel::class)->where('company_id', $companyId)->set(['performance_score' => $overall])->update();
        service('ranking')->refresh($companyId);

        return $row;
    }

    public function latestSupplier(int $companyId): ?array
    {
        return model(SupplierPerformanceModel::class)->where('company_id', $companyId)->orderBy('computed_at', 'DESC')->first();
    }

    /**
     * Buyer engagement, RFQ genuineness and transaction scores (0–100).
     */
    public function computeBuyer(int $companyId): array
    {
        $db       = db_connect();
        $since    = date('Y-m-d H:i:s', strtotime('-365 days'));
        $searches = (int) $db->table('search_logs')->where('company_id', $companyId)->where('created_at >=', $since)->countAllResults();
        $enq      = (int) $db->table('product_enquiries')->where('buyer_company_id', $companyId)->where('created_at >=', $since)->countAllResults();
        $rfqs     = (int) $db->table('rfqs')->where('buyer_company_id', $companyId)->where('created_at >=', $since)->countAllResults();
        $awarded  = (int) $db->table('rfqs')->where('buyer_company_id', $companyId)->where('status', 'awarded')->where('created_at >=', $since)->countAllResults();
        $cancel   = (int) $db->table('rfqs')->where('buyer_company_id', $companyId)->where('status', 'cancelled')->where('created_at >=', $since)->countAllResults();
        $orders   = (int) $db->table('orders')->where('buyer_company_id', $companyId)->where('created_at >=', $since)->countAllResults();
        $done     = (int) $db->table('orders')->where('buyer_company_id', $companyId)->where('status', 'completed')->where('created_at >=', $since)->countAllResults();

        $engagement  = min(100, $searches * 1 + $enq * 5 + $rfqs * 10);
        $genuine     = $rfqs > 0 ? round(100 * ($awarded + 0.5 * max(0, $rfqs - $awarded - $cancel)) / $rfqs, 2) : 0;
        $transaction = $orders > 0 ? round(100 * $done / $orders, 2) : 0;

        model(BuyerProfileModel::class)->where('company_id', $companyId)->set([
            'engagement_score' => $engagement, 'rfq_genuineness_score' => $genuine, 'transaction_score' => $transaction, 'scores_computed_at' => date('Y-m-d H:i:s'),
        ])->update();

        return compact('searches', 'enq', 'rfqs', 'awarded', 'orders', 'done', 'engagement', 'genuine', 'transaction');
    }
}

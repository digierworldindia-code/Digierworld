<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;

/**
 * Basic monitoring for every supplier; advanced analytics and demand
 * insights for plans that include them. All figures come from stored data.
 */
class Analytics extends BaseController
{
    public function index(): string
    {
        $c   = $this->company();
        $cid = (int) $c['id'];
        $db  = db_connect();
        $advanced = entitled('advanced_analytics', $c);
        $data = [
            'title' => 'Analytics & performance', 'area' => 'supplier', 'company' => $c, 'advanced' => $advanced, 'demand' => entitled('demand_insights', $c),
            'basic' => $db->query("SELECT COUNT(*) listings, COALESCE(SUM(view_count),0) views,
                (SELECT COUNT(*) FROM product_enquiries e JOIN products p2 ON p2.id = e.product_id WHERE p2.company_id = ?) enquiries,
                (SELECT COUNT(*) FROM orders o WHERE o.supplier_company_id = ?) orders
                FROM products WHERE company_id = ? AND deleted_at IS NULL", [$cid, $cid, $cid])->getRowArray(),
            'perf' => service('performance')->latestSupplier($cid),
        ];
        if ($advanced) {
            $data['topProducts'] = $db->query("SELECT p.part_number, p.name, p.view_count, (SELECT COUNT(*) FROM product_enquiries e WHERE e.product_id = p.id) enquiries,
                (SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.product_id = p.id AND o.status NOT IN ('cancelled')) ordered
                FROM products p WHERE p.company_id = ? AND p.deleted_at IS NULL ORDER BY p.view_count DESC LIMIT 10", [$cid])->getResultArray();
            $data['monthly'] = $db->query("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) orders, SUM(total) value, currency FROM orders WHERE supplier_company_id = ? AND status <> 'cancelled' GROUP BY m, currency ORDER BY m DESC LIMIT 12", [$cid])->getResultArray();
            $data['rfq'] = $db->query("SELECT COUNT(*) invited, SUM(status='quoted') quoted, SUM(status='declined') declined FROM rfq_matches WHERE supplier_company_id = ? AND invited_at IS NOT NULL", [$cid])->getRowArray();
            $data['won'] = (int) $db->query("SELECT COUNT(*) v FROM quotations WHERE supplier_company_id = ? AND status = 'accepted'", [$cid])->getRow()->v;
        }
        if ($data['demand']) {
            // Demand trends: anonymised search terms in categories this supplier lists (last 90 days).
            $data['searches'] = $db->query("SELECT s.query, COUNT(*) searches, MAX(s.results_count) max_results FROM search_logs s
                WHERE s.query IS NOT NULL AND s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND (s.query IN (SELECT part_number FROM products WHERE company_id = ?) OR EXISTS (SELECT 1 FROM products p WHERE p.company_id = ? AND (p.part_number_norm LIKE CONCAT(REPLACE(UPPER(s.query), '-', ''), '%') OR p.name LIKE CONCAT('%', s.query, '%'))))
                GROUP BY s.query ORDER BY searches DESC LIMIT 15", [$cid, $cid])->getResultArray();
            // Workbook: "Future production and demand planning insights based on platform RFQ trends".
            // RFQ lines in the supplier's categories/brands, aggregated; individual parts only
            // when requested by at least two different buyers (no buyer can be singled out).
            $scope = "(ri.category_id IN (SELECT DISTINCT category_id FROM products WHERE company_id = {$cid} AND deleted_at IS NULL)
                OR ri.brand_id IN (SELECT DISTINCT brand_id FROM products WHERE company_id = {$cid} AND brand_id IS NOT NULL AND deleted_at IS NULL)
                OR ri.part_number_norm IN (SELECT part_number_norm FROM products WHERE company_id = {$cid} AND deleted_at IS NULL))";
            $data['rfqTrend'] = $db->query("SELECT DATE_FORMAT(r.created_at, '%Y-%m') m, COUNT(DISTINCT r.id) rfqs, COUNT(*) line_count, SUM(ri.quantity) qty
                FROM rfq_items ri JOIN rfqs r ON r.id = ri.rfq_id
                WHERE r.status NOT IN ('draft','cancelled') AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND {$scope}
                GROUP BY m ORDER BY m")->getResultArray();
            $data['rfqTop'] = $db->query("SELECT COALESCE(ri.part_number, ri.description) part, SUM(ri.quantity) qty, COUNT(DISTINCT r.id) rfqs
                FROM rfq_items ri JOIN rfqs r ON r.id = ri.rfq_id
                WHERE r.status NOT IN ('draft','cancelled') AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 180 DAY) AND {$scope}
                GROUP BY part HAVING COUNT(DISTINCT r.buyer_company_id) >= 2 ORDER BY rfqs DESC, qty DESC LIMIT 10")->getResultArray();
            $data['unmet'] = $db->query("SELECT s.query, COUNT(*) searches FROM search_logs s WHERE s.results_count = 0 AND s.query IS NOT NULL AND s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY s.query ORDER BY searches DESC LIMIT 10")->getResultArray();
        }

        return view('supplier/analytics', $data);
    }
}

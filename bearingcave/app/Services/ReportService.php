<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Admin reports built only from stored records. Each report returns
 * columns + rows so it can be rendered as a table or exported to CSV/Excel.
 */
class ReportService
{
    public const REPORTS = [
        'supplier_registrations' => ['Supplier registrations', 'New supplier companies per day and their current verification status.'],
        'verification_funnel'    => ['Verification funnel', 'Verification applications by type and status.'],
        'inventory_by_category'  => ['Inventory by category', 'Published listings, units and listed value per category.'],
        'aged_stock'             => ['Aged stock', 'Units on hand by inventory-age bucket (<6, 6–12, >12 months).'],
        'stock_liquidation'      => ['Stock liquidation', 'Units dispatched against orders per supplier.'],
        'buyer_activity'         => ['Buyer activity', 'Searches, enquiries, RFQs and orders per buyer company.'],
        'rfq_matching'           => ['RFQ matching', 'Suppliers matched, invited and quoting per RFQ.'],
        'rfq_conversion'         => ['RFQ conversion', 'RFQs by status and award rate.'],
        'supplier_response'      => ['Supplier response rates', 'Invitations, responses, quotations and response time per supplier.'],
        'order_volume'           => ['Order volume', 'Orders and value by status and currency.'],
        'membership_revenue'     => ['Membership revenue', 'Paid membership invoices by month and currency.'],
        'inspection_requests'    => ['Inspection requests', 'Inspection requests by type and status.'],
        'logistics_requests'     => ['Logistics requests', 'Logistics requests by mode and status.'],
        'supplier_performance'   => ['Supplier performance', 'Latest computed performance snapshot per supplier.'],
        'buyer_engagement'       => ['Buyer engagement', 'Computed buyer engagement, RFQ genuineness and transaction scores.'],
    ];

    /**
     * @return array{columns:list<string>,rows:list<array>}
     */
    public function run(string $key, string $from, string $to): array
    {
        if (! isset(self::REPORTS[$key])) {
            throw new \InvalidArgumentException('Unknown report');
        }
        $db = db_connect();
        $f  = $from . ' 00:00:00';
        $t  = $to . ' 23:59:59';

        $sql = match ($key) {
            'supplier_registrations' => ["SELECT DATE(created_at) AS day, COUNT(*) AS registered, SUM(verification_status='verified') AS verified_now, SUM(verification_status='in_review') AS in_review, SUM(is_sample) AS sample_records
                FROM companies WHERE company_type='supplier' AND deleted_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY day", [$f, $t]],
            'verification_funnel' => ["SELECT application_type AS type, status, COUNT(*) AS applications FROM verification_applications WHERE created_at BETWEEN ? AND ? GROUP BY application_type, status ORDER BY application_type, status", [$f, $t]],
            'inventory_by_category' => ["SELECT c.name AS category, COUNT(p.id) AS listings, SUM(p.stock_on_hand) AS units_on_hand, SUM(p.stock_reserved) AS units_reserved,
                SUM(CASE WHEN p.unit_price IS NOT NULL THEN p.unit_price * p.stock_on_hand WHEN p.lot_price IS NOT NULL AND p.lot_quantity > 0 THEN p.lot_price / p.lot_quantity * p.stock_on_hand ELSE 0 END) AS listed_value_mixed_currency
                FROM products p JOIN categories c ON c.id = p.category_id WHERE p.status='published' AND p.deleted_at IS NULL GROUP BY c.id, c.name ORDER BY units_on_hand DESC", []],
            'aged_stock' => ["SELECT CASE WHEN inventory_age_date > DATE_SUB(CURDATE(), INTERVAL 6 MONTH) THEN 'Less than 6 months'
                WHEN inventory_age_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) THEN '6–12 months' WHEN inventory_age_date IS NULL THEN 'No stock' ELSE 'More than 12 months' END AS age_bucket,
                COUNT(*) AS listings, SUM(stock_on_hand) AS units FROM products WHERE deleted_at IS NULL AND status IN ('published','pending_review','unpublished') GROUP BY age_bucket ORDER BY age_bucket", []],
            'stock_liquidation' => ["SELECT co.legal_name AS supplier, SUM(-m.quantity) AS units_dispatched, COUNT(DISTINCT m.reference_id) AS orders
                FROM inventory_movements m JOIN products p ON p.id = m.product_id JOIN companies co ON co.id = p.company_id
                WHERE m.movement_type='dispatch' AND m.created_at BETWEEN ? AND ? GROUP BY co.id, co.legal_name ORDER BY units_dispatched DESC", [$f, $t]],
            'buyer_activity' => ["SELECT c.legal_name AS buyer, c.verification_status,
                (SELECT COUNT(*) FROM search_logs s WHERE s.company_id=c.id AND s.created_at BETWEEN ? AND ?) AS searches,
                (SELECT COUNT(*) FROM product_enquiries e WHERE e.buyer_company_id=c.id AND e.created_at BETWEEN ? AND ?) AS enquiries,
                (SELECT COUNT(*) FROM rfqs r WHERE r.buyer_company_id=c.id AND r.created_at BETWEEN ? AND ?) AS rfqs,
                (SELECT COUNT(*) FROM orders o WHERE o.buyer_company_id=c.id AND o.created_at BETWEEN ? AND ?) AS orders
                FROM companies c WHERE c.company_type='buyer' AND c.deleted_at IS NULL ORDER BY rfqs DESC, searches DESC", [$f, $t, $f, $t, $f, $t, $f, $t]],
            'rfq_matching' => ["SELECT r.rfq_number, r.status, COUNT(m.id) AS suppliers_matched, SUM(m.status IN ('invited','viewed','quoted','declined')) AS invited,
                SUM(m.status='quoted') AS quoted, SUM(m.status='declined') AS declined FROM rfqs r LEFT JOIN rfq_matches m ON m.rfq_id = r.id
                WHERE r.created_at BETWEEN ? AND ? GROUP BY r.id, r.rfq_number, r.status ORDER BY r.id DESC", [$f, $t]],
            'rfq_conversion' => ["SELECT status, COUNT(*) AS rfqs, ROUND(100 * COUNT(*) / NULLIF((SELECT COUNT(*) FROM rfqs WHERE created_at BETWEEN ? AND ?), 0), 1) AS pct
                FROM rfqs WHERE created_at BETWEEN ? AND ? GROUP BY status ORDER BY rfqs DESC", [$f, $t, $f, $t]],
            'supplier_response' => ["SELECT co.legal_name AS supplier, COUNT(m.id) AS invited, SUM(m.status IN ('quoted','declined')) AS responded,
                SUM(m.status='quoted') AS quoted, ROUND(100 * SUM(m.status IN ('quoted','declined')) / NULLIF(COUNT(m.id),0), 1) AS response_rate_pct,
                ROUND(AVG(CASE WHEN m.responded_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, m.invited_at, m.responded_at) END) / 60, 1) AS avg_response_hours
                FROM rfq_matches m JOIN companies co ON co.id = m.supplier_company_id WHERE m.invited_at BETWEEN ? AND ? GROUP BY co.id, co.legal_name ORDER BY invited DESC", [$f, $t]],
            'order_volume' => ["SELECT status, currency, COUNT(*) AS orders, SUM(total) AS total_value FROM orders WHERE created_at BETWEEN ? AND ? GROUP BY status, currency ORDER BY orders DESC", [$f, $t]],
            'membership_revenue' => ["SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, currency, COUNT(*) AS invoices_paid, SUM(subtotal) AS net, SUM(tax_amount) AS tax, SUM(total) AS gross
                FROM invoices WHERE invoice_type='subscription' AND status='paid' AND paid_at BETWEEN ? AND ? GROUP BY month, currency ORDER BY month", [$f, $t]],
            'inspection_requests' => ["SELECT inspection_type, status, COUNT(*) AS requests, SUM(quoted_fee) AS quoted_fees FROM inspection_requests WHERE created_at BETWEEN ? AND ? GROUP BY inspection_type, status", [$f, $t]],
            'logistics_requests' => ["SELECT mode, status, COUNT(*) AS requests, SUM(quoted_fee) AS quoted_fees FROM logistics_requests WHERE created_at BETWEEN ? AND ? GROUP BY mode, status", [$f, $t]],
            'supplier_performance' => ["SELECT co.legal_name AS supplier, sp.period_start, sp.period_end, sp.orders_count, sp.on_time_delivery_pct, sp.quality_rejection_pct,
                sp.cost_competitiveness, sp.avg_response_hours, sp.rfq_response_rate_pct, sp.service_level, sp.overall_score, sp.computed_at
                FROM supplier_performance sp JOIN companies co ON co.id = sp.company_id
                WHERE sp.id IN (SELECT MAX(id) FROM supplier_performance GROUP BY company_id) ORDER BY sp.overall_score DESC", []],
            'buyer_engagement' => ["SELECT c.legal_name AS buyer, c.verification_status, bp.engagement_score, bp.rfq_genuineness_score, bp.transaction_score, bp.scores_computed_at
                FROM buyer_profiles bp JOIN companies c ON c.id = bp.company_id WHERE c.deleted_at IS NULL ORDER BY bp.engagement_score DESC", []],
        };

        $rows = $db->query($sql[0], $sql[1])->getResultArray();
        $cols = $rows ? array_keys($rows[0]) : [];

        return ['columns' => $cols, 'rows' => $rows];
    }

    /**
     * Live KPI counters for the admin dashboard (no estimates).
     */
    public function kpis(): array
    {
        $db = db_connect();
        $one = static fn (string $sql, array $b = []) => (float) ($db->query($sql, $b)->getRowArray()['v'] ?? 0);

        return [
            'suppliers'          => $one("SELECT COUNT(*) v FROM companies WHERE company_type='supplier' AND deleted_at IS NULL"),
            'verified_suppliers' => $one("SELECT COUNT(*) v FROM companies WHERE company_type='supplier' AND verification_status='verified' AND deleted_at IS NULL"),
            'buyers'             => $one("SELECT COUNT(*) v FROM companies WHERE company_type='buyer' AND deleted_at IS NULL"),
            'verified_buyers'    => $one("SELECT COUNT(*) v FROM companies WHERE company_type='buyer' AND verification_status='verified' AND deleted_at IS NULL"),
            'active_products'    => $one("SELECT COUNT(*) v FROM products WHERE status='published' AND deleted_at IS NULL"),
            'total_inventory'    => $one("SELECT COALESCE(SUM(stock_on_hand),0) v FROM products WHERE status='published' AND deleted_at IS NULL"),
            'pending_rfqs'       => $one("SELECT COUNT(*) v FROM rfqs WHERE status IN ('submitted','under_review')"),
            'active_quotations'  => $one("SELECT COUNT(*) v FROM quotations WHERE is_current=1 AND status IN ('submitted','shortlisted')"),
            'orders'             => $one('SELECT COUNT(*) v FROM orders'),
            'inspection_requests' => $one("SELECT COUNT(*) v FROM inspection_requests WHERE status NOT IN ('completed','cancelled','declined')"),
            'logistics_requests' => $one("SELECT COUNT(*) v FROM logistics_requests WHERE status NOT IN ('delivered','cancelled')"),
            'membership_revenue' => $db->query("SELECT currency, SUM(total) total FROM invoices WHERE invoice_type='subscription' AND status='paid' GROUP BY currency")->getResultArray(),
            'pending_disputes'   => $one("SELECT COUNT(*) v FROM disputes WHERE status IN ('open','awaiting_response','under_review')"),
            'approval_queue'     => $one("SELECT COUNT(*) v FROM verification_applications WHERE status IN ('submitted','in_review')"),
            'products_pending'   => $one("SELECT COUNT(*) v FROM products WHERE status='pending_review' AND deleted_at IS NULL"),
            'sample_companies'   => $one('SELECT COUNT(*) v FROM companies WHERE is_sample=1'),
        ];
    }
}

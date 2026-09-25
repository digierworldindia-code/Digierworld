<?php

namespace App\Controllers\Admin;

class Dashboard extends AdminController
{
    private const OPEN = ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'];

    public function index(): string
    {
        $db    = db_connect();
        $count = static fn (string $sql, array $binds = []): int => (int) $db->query($sql, $binds)->getRow()->n;
        $month = (new \DateTimeImmutable('first day of this month 00:00', new \DateTimeZone('Asia/Kolkata')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $d30   = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $open  = "'" . implode("','", self::OPEN) . "'";

        $units = $db->query('SELECT current_status s, COUNT(*) n FROM mattresses WHERE deleted_at IS NULL GROUP BY current_status')->getResultArray();
        $by    = array_column($units, 'n', 's');
        $sum   = static fn (array $keys): int => array_sum(array_map(static fn ($k) => (int) ($by[$k] ?? 0), $keys));

        $stats = [
            'totalUnits'   => array_sum(array_map('intval', $by)),
            'inWarehouse'  => $sum(['MANUFACTURED']),
            'inTransit'    => $sum(['IN_DISPATCH', 'DISPATCHED']),
            'atDealers'    => $sum(['DEALER_RECEIVED']),
            'sold'         => $sum(['SOLD', 'CLAIM_OPEN', 'REPLACED']),
            'activeDealers' => $count("SELECT COUNT(*) n FROM dealers WHERE status = 'ACTIVE' AND deleted_at IS NULL"),
            'pendingApplications' => $count("SELECT COUNT(*) n FROM dealer_applications WHERE status = 'NEW'"),
            'openClaims'   => $count("SELECT COUNT(*) n FROM warranty_claims WHERE deleted_at IS NULL AND status IN ({$open})"),
            'highRisk'     => $count("SELECT COUNT(*) n FROM warranty_claims WHERE deleted_at IS NULL AND risk_level = 'HIGH' AND status IN ({$open})"),
            'claims30'     => $count('SELECT COUNT(*) n FROM warranty_claims WHERE deleted_at IS NULL AND submitted_at >= ?', [$d30]),
            'salesMonth'   => $count('SELECT COUNT(*) n FROM sales WHERE deleted_at IS NULL AND sold_at >= ?', [$month]),
            'newLeads'     => $count("SELECT COUNT(*) n FROM contact_submissions WHERE status = 'NEW'"),
            'draftDispatches' => $count("SELECT COUNT(*) n FROM dispatches WHERE status = 'DRAFT'"),
        ];

        $queue = $db->query(
            "SELECT c.id, c.claim_number, c.status, c.risk_level, c.risk_score, c.submitted_at, c.reported_issue, d.business_name, m.serial_number
               FROM warranty_claims c JOIN dealers d ON d.id = c.dealer_id JOIN mattresses m ON m.id = c.mattress_id
              WHERE c.deleted_at IS NULL AND c.status IN ({$open})
              ORDER BY c.risk_score DESC, c.submitted_at DESC LIMIT 8",
        )->getResultArray();

        $topDealers = $db->query(
            'SELECT d.business_name, d.city, COUNT(*) n FROM sales s JOIN dealers d ON d.id = s.dealer_id
              WHERE s.deleted_at IS NULL AND s.sold_at >= ? GROUP BY d.id, d.business_name, d.city ORDER BY n DESC LIMIT 5',
            [$d30],
        )->getResultArray();

        // Units made and sold per month, last six months (IST months).
        $trend = $db->query(
            "SELECT DATE_FORMAT(CONVERT_TZ(manufactured_at, '+00:00', '+05:30'), '%Y-%m') ym, COUNT(*) n FROM mattresses
              WHERE deleted_at IS NULL AND manufactured_at >= ? GROUP BY ym",
            [gmdate('Y-m-01 00:00:00', strtotime('-5 months'))],
        )->getResultArray();
        $salesTrend = $db->query(
            "SELECT DATE_FORMAT(CONVERT_TZ(sold_at, '+00:00', '+05:30'), '%Y-%m') ym, COUNT(*) n FROM sales
              WHERE deleted_at IS NULL AND sold_at >= ? GROUP BY ym",
            [gmdate('Y-m-01 00:00:00', strtotime('-5 months'))],
        )->getResultArray();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[date('Y-m', strtotime("first day of -{$i} months"))] = ['made' => 0, 'sold' => 0];
        }
        foreach ($trend as $r) {
            if (isset($months[$r['ym']])) {
                $months[$r['ym']]['made'] = (int) $r['n'];
            }
        }
        foreach ($salesTrend as $r) {
            if (isset($months[$r['ym']])) {
                $months[$r['ym']]['sold'] = (int) $r['n'];
            }
        }

        return $this->render('admin/dashboard', 'Dashboard', 'admin', compact('stats', 'queue', 'topDealers', 'months'));
    }
}

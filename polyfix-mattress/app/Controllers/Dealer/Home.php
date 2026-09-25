<?php

namespace App\Controllers\Dealer;

use CodeIgniter\HTTP\RedirectResponse;

/** The dealer's own dashboard: what is arriving, what is in stock, what needs an answer. */
class Home extends DealerController
{
    public function index(): string
    {
        $db     = db_connect();
        $dealer = $this->scope->dealerId;

        $counts = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM mattresses WHERE current_dealer_id = ? AND current_status = 'DEALER_RECEIVED' AND deleted_at IS NULL) in_stock,
                (SELECT COUNT(*) FROM dispatches WHERE dealer_id = ? AND status IN ('DISPATCHED','PARTIALLY_RECEIVED') AND deleted_at IS NULL) incoming,
                (SELECT COUNT(*) FROM sales WHERE dealer_id = ? AND deleted_at IS NULL AND sold_at >= ?) sales_month,
                (SELECT COUNT(*) FROM warranty_claims WHERE dealer_id = ? AND deleted_at IS NULL AND status IN ('SUBMITTED','UNDER_REVIEW','INFO_REQUESTED')) open_claims,
                (SELECT COUNT(*) FROM warranty_claims WHERE dealer_id = ? AND deleted_at IS NULL AND status = 'INFO_REQUESTED') waiting_on_you,
                (SELECT COUNT(*) FROM notifications WHERE dealer_id = ? AND read_at IS NULL) unread",
            [$dealer, $dealer, $dealer, (new \DateTimeImmutable('first day of this month 00:00', new \DateTimeZone('Asia/Kolkata')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $dealer, $dealer, $dealer],
        )->getRowArray();

        return $this->render('dealer/home', 'Home', 'home', [
            'counts'   => $counts,
            'incoming' => $this->scope->apply($db->table('dispatches')->select('id, dispatch_code, status, dispatched_at, expected_at,
                    (SELECT COUNT(*) FROM dispatch_items i WHERE i.dispatch_id = dispatches.id) units', false), 'dispatches')
                ->whereIn('status', ['DISPATCHED', 'PARTIALLY_RECEIVED'])->where('deleted_at', null)
                ->orderBy('dispatched_at', 'DESC')->limit(5)->get()->getResultArray(),
            'claims'   => $this->scope->apply($db->table('warranty_claims')->select('id, claim_number, status, submitted_at, reported_issue'), 'warranty_claims')
                ->where('deleted_at', null)->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'])
                ->orderBy('submitted_at', 'DESC')->limit(5)->get()->getResultArray(),
        ]);
    }

    public function notifications(): string
    {
        $builder = $this->scope->apply(db_connect()->table('notifications'), 'notifications')->orderBy('created_at', 'DESC');

        return $this->render('dealer/notifications', 'Notifications', 'home', ['list' => $this->paginate($builder, 30)]);
    }

    public function read(string $id): RedirectResponse
    {
        return $this->act(function () use ($id): void {
            $this->scope->apply(db_connect()->table('notifications'), 'notifications')
                ->where('id', $id)->where('read_at', null)->update(['read_at' => utc_now()]);
        }, 'Marked as read.', site_url('dealer/notifications'));
    }
}

<?php

namespace App\Controllers\Admin;

use App\Services\WarrantyReviewService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Deciding the warranty rejections a field agent recommended.
 *
 * Everything the brief asked the office to be able to do is here and
 * nowhere else: approve the recommendation (which voids the warranty),
 * reject it, ask the dealer for the invoice, restore a warranty that was
 * ended in error, and comment. The agent's screen can do none of it.
 */
class WarrantyReviews extends AdminController
{
    public function index(): string
    {
        $status  = $this->oneOf('status', ['RECOMMENDED', 'UNDER_REVIEW', 'INFORMATION_REQUESTED', 'UPHELD', 'DISMISSED', 'WITHDRAWN']);
        $service = WarrantyReviewService::instance();

        return $this->render('admin/reviews/index', 'Warranty reviews', 'admin/warranty-reviews', [
            'rows'    => $service->cases($status !== null ? ['status' => $status] : ['open' => true], 200),
            'status'  => $status,
            'reasons' => WarrantyReviewService::REASONS,
            'counts'  => array_column(
                db_connect()->table('warranty_review_cases')->select('status, COUNT(*) AS n', false)
                    ->groupBy('status')->get()->getResultArray(),
                'n',
                'status',
            ),
        ]);
    }

    /**
     * One case: who raised it, against what, with the sale and warranty
     * beside it so the decision can be made without leaving the page.
     */
    public function show(string $id): string
    {
        $service = WarrantyReviewService::instance();
        $case    = $service->case($id);

        return $this->render('admin/reviews/show', $case['reference'], 'admin/warranty-reviews', [
            'c'       => $case,
            'events'  => $service->events($id),
            'reasons' => WarrantyReviewService::REASONS,
            'invoice' => $case['invoice_media_id'],
        ]);
    }

    public function take(string $id): RedirectResponse
    {
        return $this->act(
            static fn () => WarrantyReviewService::instance()->take($id),
            static fn ($r) => "{$r['reference']} is now under review.",
            site_url('admin/warranty-reviews/' . $id),
        );
    }

    public function requestInformation(string $id): RedirectResponse
    {
        return $this->act(
            fn () => WarrantyReviewService::instance()->requestInformation($id, $this->post('message', 1000)),
            static fn ($r) => "The dealer has been asked. {$r['reference']} is waiting on them.",
            site_url('admin/warranty-reviews/' . $id),
        );
    }

    public function comment(string $id): RedirectResponse
    {
        $visible = $this->request->getPost('dealer_visible') === '1';

        return $this->act(
            fn () => WarrantyReviewService::instance()->comment($id, $this->post('note', 2000), $visible),
            static fn () => 'Note added.',
            site_url('admin/warranty-reviews/' . $id),
        );
    }

    /** Approve the recommendation: the warranty is rejected. */
    public function uphold(string $id): RedirectResponse
    {
        return $this->act(
            fn () => WarrantyReviewService::instance()->uphold($id, $this->post('reason', 1000)),
            static fn ($r) => $r['warranty_voided']
                ? "{$r['reference']} upheld. The warranty is now void and the dealer can see why."
                : "{$r['reference']} upheld. There was no active warranty to void.",
            site_url('admin/warranty-reviews/' . $id),
        );
    }

    /** Reject the recommendation: the warranty stands. */
    public function dismiss(string $id): RedirectResponse
    {
        return $this->act(
            fn () => WarrantyReviewService::instance()->dismiss($id, $this->post('reason', 1000)),
            static fn ($r) => "{$r['reference']} dismissed. The warranty is unchanged.",
            site_url('admin/warranty-reviews/' . $id),
        );
    }

    /** Put back a warranty this review ended in error. */
    public function restore(string $id): RedirectResponse
    {
        return $this->act(
            fn () => WarrantyReviewService::instance()->restore($id, $this->post('reason', 1000)),
            static fn ($r) => $r['warranty_restored']
                ? "Warranty restored under {$r['reference']}."
                : "{$r['reference']} reopened, but there was no voided warranty to restore.",
            site_url('admin/warranty-reviews/' . $id),
        );
    }
}

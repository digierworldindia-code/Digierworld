<?php

namespace App\Controllers\Dealer;

use App\Exceptions\AppException;
use App\Libraries\Tx;
use App\Services\MediaService;
use App\Services\WarrantyReviewService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The dealer's side of a warranty review.
 *
 * They see that a review was opened against one of their warranties, the
 * reason, the decision when it comes, and anything addressed to them. They
 * do not see the reviewer's internal notes, and they cannot decide anything
 * — the one action here is supplying the invoice that was asked for.
 */
class Reviews extends DealerController
{
    public function index(): string
    {
        $cases = WarrantyReviewService::instance()->cases(['dealer_id' => $this->scope->dealerId], 100);

        return $this->render('dealer/reviews/index', 'Warranty reviews', 'claims', [
            'rows'    => $cases,
            'reasons' => WarrantyReviewService::REASONS,
        ]);
    }

    public function show(string $id): string
    {
        $service = WarrantyReviewService::instance();
        $case    = $service->case($id);
        $this->scope->assertOwns('warranty_review_cases', $case, 'warranty review');

        return $this->render('dealer/reviews/show', $case['reference'], 'claims', [
            'c' => $case,
            // Only what was addressed to them: the decisions and the
            // requests, not the reviewer's notes to themselves.
            'events'  => $service->events($id, true),
            'reasons' => WarrantyReviewService::REASONS,
        ]);
    }

    /**
     * Uploads the invoice the office asked for.
     *
     * The file goes through the same inspection every other upload does —
     * the format is read from the bytes, not from what the browser claimed
     * — and is stored outside the web root, served only through the media
     * controller to someone entitled to see it.
     */
    public function invoice(string $id): RedirectResponse
    {
        $file = $this->request->getFile('invoice');

        return $this->act(
            function () use ($id, $file) {
                if ($file === null) {
                    throw AppException::rule('Choose the invoice file to upload.');
                }

                $service = WarrantyReviewService::instance();
                $case    = $service->case($id);
                $this->scope->assertOwns('warranty_review_cases', $case, 'warranty review');

                $media = MediaService::instance();
                $meta  = $media->inspect($file);

                if (! in_array($meta['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
                    throw AppException::rule('An invoice has to be a PDF or a photograph.');
                }

                $mediaId = Tx::run(function (BaseConnection $db) use ($case, $file, $meta, $media): string {
                    $key = $media->storeUnder('reviews', $case['id'], $file, $meta['extension']);
                    $id  = uuid4();

                    $db->table('claim_media')->insert([
                        'id'                  => $id,
                        'claim_id'            => null,
                        'review_case_id'      => $case['id'],
                        'dealer_id'           => $this->scope->dealerId,
                        'kind'                => 'REVIEW_INVOICE',
                        'storage_key'         => $key,
                        'mime_type'           => $meta['mime'],
                        'byte_size'           => $meta['bytes'],
                        'width'               => $meta['width'],
                        'height'              => $meta['height'],
                        'sha256'              => $meta['sha256'],
                        'caption'             => 'Invoice for ' . $case['reference'],
                        'uploaded_by_user_id' => $this->ctx->userId(),
                    ]);

                    return $id;
                });

                return $service->attachInvoice($id, $mediaId, $this->post('note', 500));
            },
            static fn ($r) => "Invoice uploaded against {$r['reference']}. The office will look at it.",
            site_url('dealer/reviews/' . $id),
        );
    }
}

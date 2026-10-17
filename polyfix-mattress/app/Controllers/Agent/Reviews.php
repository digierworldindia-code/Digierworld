<?php

namespace App\Controllers\Agent;

use App\Controllers\Warehouse\FloorController;
use App\Libraries\ScanCode;
use App\Services\WarrantyReviewService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The agent's side of a warranty review: raising one, and watching it.
 *
 * Nothing here decides anything. The agent records what they found wrong
 * with the paperwork and the case goes to whoever reviews warranties; the
 * customer's cover is untouched until that person says otherwise.
 */
class Reviews extends FloorController
{
    public function index(): string
    {
        return $this->render('agent/reviews/index', 'Warranty reviews', [
            'rows'    => WarrantyReviewService::instance()->cases(['raised_by' => $this->ctx->userId()]),
            'reasons' => WarrantyReviewService::REASONS,
        ], site_url('agent'));
    }

    /** The form, opened from a mattress on a dealer's stock list. */
    public function new(): string
    {
        $code = $this->request->getGet('code');
        $code = is_string($code) ? mb_substr(trim($code), 0, 300) : '';
        $unit = $code === '' ? null : ScanCode::find(db_connect(), $code);

        $dealer = null;
        if ($unit !== null && $unit['current_dealer_id'] !== null) {
            $dealer = db_connect()->table('dealers')->select('id, business_name, city')
                ->where('id', $unit['current_dealer_id'])->get()->getRowArray();
        }

        return $this->render('agent/reviews/new', 'Report a warranty problem', [
            'code'    => $code,
            'unit'    => $unit,
            'dealer'  => $dealer,
            'survey'  => $this->request->getGet('survey'),
            'reasons' => WarrantyReviewService::REASONS,
        ], site_url('agent/reviews'));
    }

    public function create(): RedirectResponse
    {
        $code = $this->post('code', 300);
        $unit = ScanCode::find(db_connect(), $code);

        if ($unit === null) {
            return redirect()->back()->withInput()->with('error', 'That serial does not match any mattress.');
        }

        $surveyId = $this->request->getPost('survey_id');
        $surveyId = is_string($surveyId) && is_uuid($surveyId) ? $surveyId : null;

        return $this->act(
            fn () => WarrantyReviewService::instance()->recommend(
                $unit['id'],
                (string) $this->request->getPost('reason'),
                $this->post('remark', 1000),
                $surveyId,
            ),
            static fn ($r) => "Recorded as {$r['reference']}. The office will review it; the warranty is unchanged until they decide.",
            site_url('agent/reviews'),
        );
    }

    public function show(string $id): string
    {
        $service = WarrantyReviewService::instance();
        $case    = $service->case($id);

        if ($case['raised_by'] !== $this->ctx->userId() && ! $this->ctx->can('warranty_review:decide')) {
            $this->notFound();
        }

        return $this->render('agent/reviews/show', $case['reference'], [
            'c'       => $case,
            'events'  => $service->events($id),
            'reasons' => WarrantyReviewService::REASONS,
        ], site_url('agent/reviews'));
    }

    public function withdraw(string $id): RedirectResponse
    {
        return $this->act(
            fn () => WarrantyReviewService::instance()->withdraw($id, $this->post('reason', 500)),
            static fn ($r) => "{$r['reference']} withdrawn.",
            site_url('agent/reviews'),
        );
    }
}

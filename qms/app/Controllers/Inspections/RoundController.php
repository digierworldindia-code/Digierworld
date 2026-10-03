<?php

namespace App\Controllers\Inspections;

use App\Controllers\BaseController;

/**
 * In-Process inspection rounds (inspection times on the shift grid).
 */
class RoundController extends BaseController
{
    public function add(int $reportId)
    {
        return $this->perform(
            fn (): int => service('inspections')->addRound($reportId, (int) $this->input('shift_id'), (string) $this->input('inspection_time'), $this->currentUser()),
            fn (int $roundId): string => site_url("inspections/{$reportId}/edit") . '#round-' . $roundId,
            'Inspection time added.',
        );
    }

    public function sign(int $reportId, int $roundId)
    {
        return $this->perform(
            fn () => service('inspections')->signRound($reportId, $roundId, $this->currentUser()),
            $this->back($reportId, $roundId),
            'Inspection signed by the operator.',
        );
    }

    public function verify(int $reportId, int $roundId)
    {
        return $this->perform(
            fn () => service('inspections')->verifyRound($reportId, $roundId, (string) $this->request->getPost('password'), $this->currentUser()),
            $this->back($reportId, $roundId),
            'Inspection verified by the quality engineer.',
        );
    }

    public function reopen(int $reportId, int $roundId)
    {
        return $this->perform(
            fn () => service('inspections')->reopenRound($reportId, $roundId, (string) $this->input('remarks'), $this->currentUser()),
            $this->back($reportId, $roundId),
            'Inspection reopened for correction.',
        );
    }

    public function delete(int $reportId, int $roundId)
    {
        return $this->perform(
            fn () => service('inspections')->deleteRound($reportId, $roundId, $this->currentUser()),
            site_url("inspections/{$reportId}/edit"),
            'Empty inspection removed.',
        );
    }

    private function back(int $reportId, int $roundId): string
    {
        $page = $this->input('return_to') === 'show' ? "inspections/{$reportId}" : "inspections/{$reportId}/edit";

        return site_url($page) . '#round-' . $roundId;
    }
}

<?php

namespace App\Controllers\Agent;

use App\Controllers\Warehouse\FloorController;
use App\Services\SurveyService;
use App\Services\WarrantyReviewService;

/**
 * The field agent's front screen.
 *
 * What is owed, what is in hand, and what has been raised — each as a number
 * and a way in. Counted from the records on every request.
 */
class Home extends FloorController
{
    public function index(): string
    {
        $agentId = (string) $this->ctx->userId();
        $survey  = SurveyService::instance();

        return $this->render('agent/home', 'My round', [
            'figures'     => $survey->dashboard($agentId),
            'assignments' => array_slice($survey->assignments($agentId), 0, 5),
            'agentCode'   => self::agentCode($agentId),
        ]);
    }

    /**
     * The agent's own identifier, shown on their screen and on every report
     * they sign.
     *
     * Derived from the account id rather than stored: there is already
     * exactly one account per agent, and a second identifier to keep in step
     * would be a second thing to get wrong.
     */
    public static function agentCode(string $userId): string
    {
        return 'AGT-' . strtoupper(substr(str_replace('-', '', $userId), 0, 6));
    }
}

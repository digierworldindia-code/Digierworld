<?php

namespace App\Controllers\Agent;

use App\Controllers\Warehouse\FloorController;
use App\Exceptions\AppException;
use App\Services\SurveyService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Conducting a survey, and reading one back afterwards.
 *
 * The agent scans down the shelf with "found" selected and only stops for
 * the exceptions. Each scan posts the observation along with the code, so
 * the common case is one tap per mattress and the uncommon case is two.
 */
class Surveys extends FloorController
{
    private const OBSERVATIONS = ['PRESENT', 'DEALER_SAYS_SOLD', 'NOT_FOUND', 'DAMAGED', 'OTHER'];

    /** Opens the survey for a dealer, or carries on with the one already open. */
    public function open(string $dealerId): RedirectResponse
    {
        try {
            $survey = SurveyService::instance()->open($dealerId);
        } catch (AppException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('agent/surveys/' . $survey['id']))
            ->with($survey['resumed'] ? 'notice' : 'success', $survey['resumed']
                ? "Carrying on with survey {$survey['reference']}."
                : "Survey {$survey['reference']} started.");
    }

    /** The survey screen: camera, what is being recorded, and what is left. */
    public function show(string $id): string|RedirectResponse
    {
        $service = SurveyService::instance();
        $survey  = $service->survey($id);
        $this->mine($survey);

        if ($survey['status'] !== 'IN_PROGRESS') {
            return redirect()->to(site_url('agent/surveys/' . $id . '/report'));
        }

        $counts   = $service->counts($id, $survey['dealer_id']);
        $examined = array_column($service->surveyItems($id), null, 'mattress_id');

        return $this->render('agent/surveys/conduct', 'Survey ' . $survey['reference'], [
            's'        => $survey,
            'counts'   => $counts,
            'items'    => array_values($examined),
            'stock'    => $service->dealerStock($survey['dealer_id']),
            'examined' => $examined,
            'scanner'  => true,
        ], site_url('agent/dealers/' . $survey['dealer_id']));
    }

    /** One observation, from the camera or from the list. Answers JSON. */
    public function record(string $id): ResponseInterface
    {
        $code = $this->scannedCode();
        if ($code === '') {
            return $this->scanFailed('Nothing scanned', 'No code was read. Try again, or pick the mattress from the list.');
        }

        $observation = (string) $this->request->getPost('observation');
        if (! in_array($observation, self::OBSERVATIONS, true)) {
            return $this->scanFailed('Nothing recorded', 'Choose what you found before scanning.');
        }

        try {
            return $this->scanned(SurveyService::instance()->record(
                $id,
                $code,
                $observation,
                $this->post('remark', 500),
                $this->scanSource() === 'CAMERA',
            ));
        } catch (AppException $e) {
            return $this->scanFailed('Not recorded', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[{rid}] survey record failed: {msg} at {file}:{line}', [
                'rid' => $this->ctx->requestId, 'msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);

            return $this->scanFailed('Not recorded', 'Something went wrong. Reference ' . $this->ctx->requestId . '.');
        }
    }

    /** The same thing from a form, for a mattress picked off the list. */
    public function observe(string $id): RedirectResponse
    {
        $code        = $this->post('code', 300);
        $observation = (string) $this->request->getPost('observation');

        if (! in_array($observation, self::OBSERVATIONS, true)) {
            return redirect()->back()->with('error', 'Choose what you found.');
        }

        return $this->act(
            fn () => SurveyService::instance()->record($id, $code, $observation, $this->post('remark', 500), false),
            static fn ($r) => $r['ok'] ? $r['title'] . ' — ' . $r['message'] : $r['message'],
            site_url('agent/surveys/' . $id),
        );
    }

    public function submit(string $id): RedirectResponse
    {
        return $this->act(
            fn () => SurveyService::instance()->submit($id, $this->post('remarks', 4000)),
            static fn ($r) => "Survey {$r['reference']} finished: {$r['examined']} checked, {$r['discrepancies']} to look into.",
            site_url('agent/surveys/' . $id . '/report'),
        );
    }

    public function abandon(string $id): RedirectResponse
    {
        return $this->act(
            fn () => SurveyService::instance()->abandon($id, $this->post('reason', 500)),
            static fn ($r) => "Survey {$r['reference']} abandoned.",
            site_url('agent'),
        );
    }

    /** Every survey this agent has done. History, never overwritten. */
    public function index(): string
    {
        return $this->render('agent/surveys/index', 'My surveys', [
            'rows' => SurveyService::instance()->history((string) $this->ctx->userId()),
        ], site_url('agent'));
    }

    /**
     * The DEALER STOCK VERIFICATION REPORT.
     *
     * Printable, and the figures are the ones frozen onto the survey when it
     * was submitted — the position on the day of the visit, not a live
     * query that would quietly rewrite the past every time stock moved.
     */
    public function report(string $id): string
    {
        $service = SurveyService::instance();
        $survey  = $service->survey($id);
        $this->mine($survey);

        return $this->render('agent/surveys/report', 'Report ' . $survey['reference'], [
            's'         => $survey,
            'items'     => $service->surveyItems($id),
            'counts'    => SurveyService::figuresFor($service, $survey),
            'agentCode' => Home::agentCode($survey['agent_user_id']),
        ], site_url('agent/surveys'));
    }

    /** Discrepancies this agent has raised, and where each one has got to. */
    public function discrepancies(): string
    {
        return $this->render('agent/discrepancies', 'Discrepancies I raised', [
            'rows' => SurveyService::instance()->discrepancies((string) $this->ctx->userId()),
        ], site_url('agent'));
    }

    /**
     * An agent reads their own surveys. Somebody else's answers exactly as
     * one that does not exist.
     *
     * @param array<string,mixed> $survey
     */
    private function mine(array $survey): void
    {
        if ($survey['agent_user_id'] !== $this->ctx->userId() && ! $this->ctx->can('survey:assign')) {
            $this->notFound();
        }
    }
}

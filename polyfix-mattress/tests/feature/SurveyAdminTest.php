<?php

namespace Tests\Feature;

use App\Services\SalesService;
use App\Services\SurveyService;
use App\Services\WarrantyReviewService;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\PolyfixTestCase;

/**
 * The office's side of the survey programme, over HTTP.
 *
 * Three questions: does every screen render, can the office actually settle
 * what the agents raise, and — the one that matters — is the warranty safe
 * from everybody who should not be able to touch it.
 *
 * @internal
 */
final class SurveyAdminTest extends PolyfixTestCase
{
    use FeatureTestTrait;

    private string $adminId;
    private string $agentId;
    private string $dealerId;
    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealerId = $this->makeDealer();
        $this->adminId  = $this->makeUser(['SUPER_ADMIN']);
        $this->agentId  = $this->makeUser(['FIELD_AGENT']);
        $this->variant  = $this->makeProduct()['variant'];

        $this->insert('agent_dealer_assignments', [
            'id' => uuid4(), 'agent_user_id' => $this->agentId, 'dealer_id' => $this->dealerId,
            'survey_interval_days' => SurveyService::DEFAULT_INTERVAL_DAYS, 'active' => 1, 'created_at' => utc_now(),
        ]);
    }

    // =====================================================================
    // The screens
    // =====================================================================

    public function testEveryAdminSurveyScreenRenders(): void
    {
        $survey = $this->surveyWithADiscrepancy();
        $case   = $this->aReviewCase();

        $this->signedInAs($this->adminId);

        foreach ([
            'admin/surveys',
            'admin/surveys?status=SUBMITTED',
            'admin/surveys/' . $survey['id'],
            'admin/discrepancies',
            'admin/discrepancies?status=OPEN',
            'admin/assignments',
            'admin/warranty-reviews',
            'admin/warranty-reviews?status=RECOMMENDED',
            'admin/warranty-reviews/' . $case['id'],
            'admin/surveys/' . $survey['id'] . '/report',
        ] as $path) {
            $result = $this->get($path);
            $this->assertSame(200, $result->response()->getStatusCode(), "{$path} did not render");
        }
    }

    /**
     * The office and the agent read the same document.
     *
     * Two renderings of a verification report would drift, and the one on
     * the agent's phone is the one the dealer countersigned.
     */
    public function testTheOfficeAndTheAgentSeeTheSameReport(): void
    {
        $survey = $this->surveyWithADiscrepancy();

        $this->signedInAs($this->agentId);
        $theirs = $this->reportBody((string) $this->get('agent/surveys/' . $survey['id'] . '/report')->response()->getBody());

        $this->signedInAs($this->adminId);
        $ours = $this->reportBody((string) $this->get('admin/surveys/' . $survey['id'] . '/report')->response()->getBody());

        $this->assertNotSame('', $theirs, 'the report body was not found on the page');
        $this->assertSame($theirs, $ours);
    }

    /**
     * How often a new round is scheduled is a setting, so the office can
     * change it without a deploy — and changing it must not rewrite a
     * schedule an agent is already working to.
     */
    public function testTheDefaultSurveyIntervalComesFromTheSettings(): void
    {
        $this->signedInAs($this->adminId);

        $this->db->table('system_settings')->where('key', 'survey.default_interval_days')->update(['value' => '90']);
        \App\Libraries\Settings::forget();

        $this->assertSame(90, SurveyService::defaultIntervalDays());
        $this->assertStringContainsString('value="90"',
            (string) $this->get('admin/assignments')->response()->getBody());

        // The round already running keeps the interval it was given.
        $this->assertSame(
            SurveyService::DEFAULT_INTERVAL_DAYS,
            (int) $this->db->table('agent_dealer_assignments')->select('survey_interval_days')
                ->where('agent_user_id', $this->agentId)->get()->getRow()->survey_interval_days,
        );
    }

    /** An interval outside what the column allows is clamped, not stored wrong. */
    public function testAnAbsurdIntervalSettingIsBroughtBackIntoRange(): void
    {
        $this->db->table('system_settings')->where('key', 'survey.default_interval_days')->update(['value' => '99999']);
        \App\Libraries\Settings::forget();

        $this->assertSame(1095, SurveyService::defaultIntervalDays());
    }

    /** The complete history of one serial, in one place. */
    public function testTheMattressPageShowsTheWholeStoryOfOneSerial(): void
    {
        $survey = $this->surveyWithADiscrepancy();
        $this->signedInAs($this->adminId);

        $body = (string) $this->get('admin/mattresses/' . $survey['mattress_id'])->response()->getBody();

        $this->assertStringContainsString('Seen on a field visit', $body);
        $this->assertStringContainsString('Discrepancies', $body);
        $this->assertStringContainsString($survey['reference'], $body);
        $this->assertStringContainsString('Stock discrepancy', $body);
    }

    // =====================================================================
    // Settling what the agents raise
    // =====================================================================

    public function testTheOfficeCanSettleADiscrepancy(): void
    {
        $survey = $this->surveyWithADiscrepancy();
        $this->signedInAs($this->adminId);

        $discrepancy = $this->db->table('inventory_discrepancies')->get()->getRowArray();

        $this->post('admin/discrepancies/' . $discrepancy['id'] . '/resolve', $this->signed([
            'outcome' => 'RESOLVED',
            'note'    => 'Unit was in the stockroom; dealer found it the next day.',
        ]));

        $after = $this->db->table('inventory_discrepancies')->where('id', $discrepancy['id'])->get()->getRowArray();
        $this->assertSame('RESOLVED', $after['status']);
        $this->assertSame($this->adminId, $after['resolved_by']);
        $this->assertNotNull($after['resolved_at']);
        $this->assertStringContainsString('stockroom', $after['resolution_note']);
    }

    public function testADiscrepancyCannotBeClosedWithoutSayingWhy(): void
    {
        $this->surveyWithADiscrepancy();
        $this->signedInAs($this->adminId);

        $discrepancy = $this->db->table('inventory_discrepancies')->get()->getRowArray();

        $this->post('admin/discrepancies/' . $discrepancy['id'] . '/resolve', $this->signed([
            'outcome' => 'RESOLVED', 'note' => '   ',
        ]));

        $this->assertSame('OPEN', $this->db->table('inventory_discrepancies')
            ->select('status')->where('id', $discrepancy['id'])->get()->getRow()->status);
    }

    public function testAssigningADealerToAnAgentWorksFromTheConsole(): void
    {
        $this->signedInAs($this->adminId);
        $second = $this->makeDealer();

        $this->post('admin/assignments', $this->signed([
            'agent_user_id' => $this->agentId,
            'dealer_id'     => $second,
            'interval_days' => '90',
            'notes'         => 'Visits more often: new dealer.',
        ]));

        $row = $this->db->table('agent_dealer_assignments')
            ->where(['agent_user_id' => $this->agentId, 'dealer_id' => $second])->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame(90, (int) $row['survey_interval_days']);
    }

    /** A user who is not a field agent cannot be given a round. */
    public function testOnlyAFieldAgentCanBeAssignedADealer(): void
    {
        $this->signedInAs($this->adminId);
        $notAnAgent = $this->makeUser(['SALES_MANAGER']);

        $this->post('admin/assignments', $this->signed([
            'agent_user_id' => $notAnAgent,
            'dealer_id'     => $this->dealerId,
            'interval_days' => '182',
        ]));

        $this->assertSame(0, $this->db->table('agent_dealer_assignments')
            ->where('agent_user_id', $notAnAgent)->countAllResults());
    }

    /** Re-assigning updates the round rather than adding a second one. */
    public function testAssigningTheSamePairAgainUpdatesTheSchedule(): void
    {
        $this->signedInAs($this->adminId);

        $this->post('admin/assignments', $this->signed([
            'agent_user_id' => $this->agentId, 'dealer_id' => $this->dealerId, 'interval_days' => '30',
        ]));

        $this->assertSame(1, $this->db->table('agent_dealer_assignments')
            ->where(['agent_user_id' => $this->agentId, 'dealer_id' => $this->dealerId])->countAllResults());
        $this->assertSame(30, (int) $this->db->table('agent_dealer_assignments')
            ->select('survey_interval_days')->where(['agent_user_id' => $this->agentId, 'dealer_id' => $this->dealerId])
            ->get()->getRow()->survey_interval_days);
    }

    // =====================================================================
    // Deciding a warranty review
    // =====================================================================

    public function testTheOfficeCanRejectAWarrantyAndThenPutItBack(): void
    {
        $case = $this->aReviewCase();
        $this->signedInAs($this->adminId);

        $this->post('admin/warranty-reviews/' . $case['id'] . '/uphold', $this->signed([
            'reason' => 'No sale and no invoice after two requests.',
        ]));

        $this->assertSame('VOID', $this->warrantyStatus($case['warranty_id']));

        $this->post('admin/warranty-reviews/' . $case['id'] . '/restore', $this->signed([
            'reason' => 'The sale was recorded against a different serial. Our error.',
        ]));

        $this->assertSame('ACTIVE', $this->warrantyStatus($case['warranty_id']));
    }

    public function testDismissingLeavesTheWarrantyAlone(): void
    {
        $case = $this->aReviewCase();
        $this->signedInAs($this->adminId);

        $this->post('admin/warranty-reviews/' . $case['id'] . '/dismiss', $this->signed([
            'reason' => 'Invoice was on file against the sale.',
        ]));

        $this->assertSame('ACTIVE', $this->warrantyStatus($case['warranty_id']));
        $this->assertSame('DISMISSED', $this->caseStatus($case['id']));
    }

    /**
     * The limit that matters most. A field agent holds warranty_review:read
     * and reaches the case, but every decision route is closed to them.
     *
     * @dataProvider decisionRoutes
     */
    public function testAnAgentCannotDecideTheirOwnRecommendation(string $suffix, array $fields): void
    {
        $case = $this->aReviewCase();
        $this->signedInAs($this->agentId);

        $this->post('admin/warranty-reviews/' . $case['id'] . '/' . $suffix, $this->signed($fields));

        $this->assertSame('RECOMMENDED', $this->caseStatus($case['id']), 'nothing was decided');
        $this->assertSame('ACTIVE', $this->warrantyStatus($case['warranty_id']), 'the warranty is untouched');
    }

    public static function decisionRoutes(): array
    {
        return [
            'uphold'  => ['uphold', ['reason' => 'Because I said so.']],
            'dismiss' => ['dismiss', ['reason' => 'Never mind.']],
            'take'    => ['take', []],
        ];
    }

    /** A warranty manager decides; they do not need to be an administrator. */
    public function testAWarrantyManagerCanDecide(): void
    {
        $case = $this->aReviewCase();
        $this->signedInAs($this->makeUser(['WARRANTY_MANAGER']));

        $this->post('admin/warranty-reviews/' . $case['id'] . '/dismiss', $this->signed([
            'reason' => 'Paperwork is in order.',
        ]));

        $this->assertSame('DISMISSED', $this->caseStatus($case['id']));
    }

    // =====================================================================
    // The dealer's side
    // =====================================================================

    public function testTheDealerSeesTheDecisionAndTheReasonButNotTheInternalNotes(): void
    {
        $case = $this->aReviewCase();

        $this->actingAs($this->makeUser(['WARRANTY_MANAGER']));
        $review = new WarrantyReviewService($this->db, service('requestContext'));
        $review->comment($case['id'], 'Third case for this dealer this quarter.', false);
        $review->dismiss($case['id'], 'Invoice checked out.');

        $this->signedInAs($this->makeUser(['DEALER'], $this->dealerId));

        $body = (string) $this->get('dealer/reviews/' . $case['id'])->response()->getBody();

        $this->assertStringContainsString('Invoice checked out', $body);
        $this->assertStringNotContainsString('this quarter', $body, 'an internal note is not for the dealer');
        $this->assertStringContainsString('The warranty stands', $body);
    }

    public function testADealerCannotOpenAnotherDealersReview(): void
    {
        $case = $this->aReviewCase();

        $this->signedInAs($this->makeUser(['DEALER'], $this->makeDealer()));

        $this->expectException(\App\Exceptions\AppException::class);
        $this->get('dealer/reviews/' . $case['id']);
    }

    public function testTheDealerListOnlyShowsTheirOwnReviews(): void
    {
        $this->aReviewCase();

        $this->signedInAs($this->makeUser(['DEALER'], $this->makeDealer()));
        $body = (string) $this->get('dealer/reviews')->response()->getBody();

        $this->assertStringContainsString('Nothing is under review', $body);
    }

    // =====================================================================

    /** @return array{id:string, reference:string, mattress_id:string} */
    private function surveyWithADiscrepancy(): array
    {
        $unit = $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->dealerId);

        $this->actingAs($this->agentId);
        $service = new SurveyService($this->db, service('requestContext'));
        $survey  = $service->open($this->dealerId);
        $service->record($survey['id'], $unit['serial'], 'NOT_FOUND', 'Not on the floor or in the stockroom.');
        $service->submit($survey['id'], 'One unit unaccounted for.');

        return ['id' => $survey['id'], 'reference' => $survey['reference'], 'mattress_id' => $unit['id']];
    }

    /** @return array{id:string, reference:string, warranty_id:string} */
    private function aReviewCase(): array
    {
        $unit       = $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->dealerId);
        $dealerUser = $this->makeUser(['DEALER'], $this->dealerId);

        $this->actingAs($dealerUser);
        $sale = (new SalesService($this->db, service('requestContext'), $this->crypto()))->recordSale([
            'serial'         => $unit['serial'],
            'invoice_number' => 'INV-' . random_int(10_000, 99_999),
            'sold_on'        => utc_today(),
            'sale_price'     => 19_000,
            'payment_mode'   => 'CASH',
            'customer'       => [
                'full_name' => 'Test Customer', 'phone' => '98765' . random_int(10_000, 99_999),
                'city' => 'Jaipur', 'state' => 'Rajasthan',
            ],
        ]);

        $this->actingAs($this->agentId);
        $case = (new WarrantyReviewService($this->db, service('requestContext')))
            ->recommend($unit['id'], 'INVOICE_NOT_UPLOADED', 'Nothing on file.');

        return $case + ['warranty_id' => $sale['warranty_id']];
    }

    /**
     * Just the report itself, without the page around it.
     *
     * It ends at its own closing tag — the footer's — because everything
     * after that is the console's chrome or the panel's, which of course
     * differ.
     */
    private function reportBody(string $html): string
    {
        $start = strpos($html, '<div class="report">');
        if ($start === false) {
            return '';
        }

        $footer = strpos($html, '</footer>', $start);
        $end    = $footer === false ? false : strpos($html, '</div>', $footer);

        return $end === false ? '' : trim(substr($html, $start, $end - $start + 6));
    }

    private function warrantyStatus(string $warrantyId): string
    {
        return $this->db->table('warranties')->select('status')->where('id', $warrantyId)->get()->getRow()->status;
    }

    private function caseStatus(string $caseId): string
    {
        return $this->db->table('warranty_review_cases')->select('status')->where('id', $caseId)->get()->getRow()->status;
    }

    /**
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function signed(array $fields): array
    {
        return $fields + [csrf_token() => csrf_hash()];
    }
}

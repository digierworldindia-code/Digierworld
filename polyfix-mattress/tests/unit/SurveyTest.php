<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Services\SurveyService;
use Tests\Support\PolyfixTestCase;

/**
 * Dealer stock surveys.
 *
 * The rule the business stated, and the one worth defending in a test: an
 * agent reports, the office decides. A survey must never change a sale, a
 * warranty or a mattress's lifecycle — whatever the agent saw. Where the
 * shelf and the records disagree, a discrepancy is raised and left for
 * someone with the standing to settle it.
 *
 * @internal
 */
final class SurveyTest extends PolyfixTestCase
{
    private SurveyService $survey;
    private string $agentId;
    private string $dealerId;
    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealerId = $this->makeDealer();
        $this->agentId  = $this->makeUser(['FIELD_AGENT']);
        $this->variant  = $this->makeProduct()['variant'];

        $this->assign($this->agentId, $this->dealerId);
        $this->actingAs($this->agentId);
        $this->survey = new SurveyService($this->db, service('requestContext'));
    }

    // =====================================================================
    // The verdict
    // =====================================================================

    /**
     * The whole decision table, stated once. This is the piece of the
     * feature that people will argue about, so it is written down where an
     * argument can be settled by reading it.
     *
     * @dataProvider observations
     */
    public function testTheVerdictFollowsFromTheRecordsAndTheObservation(string $systemStatus, string $observation, string $expected): void
    {
        $this->assertSame($expected, SurveyService::outcomeFor($systemStatus, $observation));
    }

    public static function observations(): array
    {
        return [
            'on the shelf, and found'            => ['DEALER_RECEIVED', 'PRESENT', 'MATCHED'],
            'in transit, and found'              => ['DISPATCHED', 'PRESENT', 'MATCHED'],
            'sold, and found on the floor'       => ['SOLD', 'PRESENT', 'REVIEW_REQUIRED'],
            'on the shelf, dealer says sold'     => ['DEALER_RECEIVED', 'DEALER_SAYS_SOLD', 'POSSIBLE_UNRECORDED_SALE'],
            'sold, dealer says sold'             => ['SOLD', 'DEALER_SAYS_SOLD', 'MATCHED'],
            'on the shelf, nowhere to be seen'   => ['DEALER_RECEIVED', 'NOT_FOUND', 'STOCK_DISCREPANCY'],
            'sold, nowhere to be seen'           => ['SOLD', 'NOT_FOUND', 'MATCHED'],
            'claim open, nowhere to be seen'     => ['CLAIM_OPEN', 'NOT_FOUND', 'MATCHED'],
            'damaged, whatever the records say'  => ['DEALER_RECEIVED', 'DAMAGED', 'DAMAGED'],
            'damaged, even if recorded as sold'  => ['SOLD', 'DAMAGED', 'DAMAGED'],
            'something else entirely'            => ['DEALER_RECEIVED', 'OTHER', 'REVIEW_REQUIRED'],
        ];
    }

    // =====================================================================
    // Opening and resuming
    // =====================================================================

    public function testOpeningASurveyTwiceResumesTheSameOne(): void
    {
        $first  = $this->survey->open($this->dealerId);
        $second = $this->survey->open($this->dealerId);

        $this->assertFalse($first['resumed']);
        $this->assertTrue($second['resumed'], 'an agent who loses signal must carry on, not start a second survey');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, $this->db->table('dealer_surveys')->where('dealer_id', $this->dealerId)->countAllResults());
    }

    public function testAnAgentCannotSurveyADealerTheyAreNotAssignedTo(): void
    {
        $other = $this->makeDealer();

        $this->expectException(AppException::class);
        $this->survey->open($other);
    }

    public function testTheReferenceReadsLikeSomethingYouCouldSayAloud(): void
    {
        $opened = $this->survey->open($this->dealerId);

        $this->assertMatchesRegularExpression('/^SRV-\d{2}-\d{6}$/', $opened['reference']);
    }

    // =====================================================================
    // Recording what was found
    // =====================================================================

    public function testAUnitThatMatchesTheRecordsRaisesNothing(): void
    {
        $unit   = $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);

        $reply = $this->survey->record($survey['id'], $unit['serial'], 'PRESENT');

        $this->assertTrue($reply['ok']);
        $this->assertSame('MATCHED', $reply['outcome']);
        $this->assertSame(0, $this->db->table('inventory_discrepancies')->countAllResults());
    }

    /**
     * The case the business cares most about. The dealer says it sold; the
     * records say it is in stock. The sale is NOT created.
     */
    public function testADealerSayingItSoldDoesNotCreateASale(): void
    {
        $unit   = $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);

        $reply = $this->survey->record($survey['id'], $unit['serial'], 'DEALER_SAYS_SOLD', 'Dealer says it went last month.');

        $this->assertSame('POSSIBLE_UNRECORDED_SALE', $reply['outcome']);

        // Nothing about the mattress moved.
        $this->assertSame('DEALER_RECEIVED', $this->statusOf($unit['id']));
        $this->assertSame(0, $this->db->table('sales')->where('mattress_id', $unit['id'])->countAllResults());
        $this->assertSame(0, $this->db->table('warranties')->where('mattress_id', $unit['id'])->countAllResults());

        // What happened instead: somebody was asked to look.
        $discrepancy = $this->db->table('inventory_discrepancies')->where('mattress_id', $unit['id'])->get()->getRowArray();
        $this->assertNotNull($discrepancy);
        $this->assertSame('POSSIBLE_UNRECORDED_SALE', $discrepancy['kind']);
        $this->assertSame('OPEN', $discrepancy['status']);
        $this->assertSame($this->agentId, $discrepancy['raised_by']);
        $this->assertStringContainsString('last month', $discrepancy['agent_remark']);
    }

    public function testStockThatIsNotThereRaisesADiscrepancy(): void
    {
        $unit   = $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);

        $reply = $this->survey->record($survey['id'], $unit['serial'], 'NOT_FOUND');

        $this->assertSame('STOCK_DISCREPANCY', $reply['outcome']);
        $this->assertSame('DEALER_RECEIVED', $this->statusOf($unit['id']), 'the record is untouched');
        $this->assertSame(1, $this->db->table('inventory_discrepancies')
            ->where(['mattress_id' => $unit['id'], 'kind' => 'STOCK_DISCREPANCY'])->countAllResults());
    }

    public function testAUnitRecordedAsSoldButStandingOnTheFloorNeedsAPerson(): void
    {
        $unit   = $this->stockAtDealer('SOLD');
        $survey = $this->survey->open($this->dealerId);

        $reply = $this->survey->record($survey['id'], $unit['serial'], 'PRESENT');

        $this->assertSame('REVIEW_REQUIRED', $reply['outcome']);
        $this->assertSame('SOLD', $this->statusOf($unit['id']), 'the sale is not undone by an observation');
    }

    /** An agent who mis-taps and corrects themselves leaves one line, not two. */
    public function testCorrectingAnObservationReplacesTheLine(): void
    {
        $unit   = $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);

        $this->survey->record($survey['id'], $unit['serial'], 'NOT_FOUND');
        $this->survey->record($survey['id'], $unit['serial'], 'PRESENT');

        $this->assertSame(1, $this->db->table('dealer_survey_items')->where('survey_id', $survey['id'])->countAllResults());

        $item = $this->db->table('dealer_survey_items')->where('survey_id', $survey['id'])->get()->getRowArray();
        $this->assertSame('PRESENT', $item['observation']);
        $this->assertSame('MATCHED', $item['outcome']);

        // And the discrepancy the first tap raised is withdrawn with it.
        $this->assertSame(0, $this->db->table('inventory_discrepancies')->countAllResults());
    }

    /** Once the office has picked it up, the agent changing their mind does not erase it. */
    public function testADiscrepancySomebodyIsWorkingOnIsNotWithdrawnByACorrection(): void
    {
        $unit   = $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);
        $this->survey->record($survey['id'], $unit['serial'], 'NOT_FOUND');

        $this->db->table('inventory_discrepancies')->where('mattress_id', $unit['id'])
            ->update(['status' => 'UNDER_REVIEW']);

        $this->survey->record($survey['id'], $unit['serial'], 'PRESENT');

        $this->assertSame(1, $this->db->table('inventory_discrepancies')->countAllResults(),
            'a case in hand stays open; the correction is recorded on the line');
    }

    public function testAUnitBelongingToAnotherDealerIsRefused(): void
    {
        $mine   = $this->stockAtDealer();
        $theirs = $this->makeMattress($this->variant, 'DEALER_RECEIVED', $this->makeDealer());
        $survey = $this->survey->open($this->dealerId);

        $reply = $this->survey->record($survey['id'], $theirs['serial'], 'PRESENT');

        $this->assertSame('REJECTED', $reply['status']);
        $this->assertSame(0, $this->db->table('dealer_survey_items')->countAllResults());
        unset($mine);
    }

    public function testAnUnreadableLabelIsAnsweredPlainly(): void
    {
        $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);

        foreach (['', 'nonsense', 'PFZ-2026-999999'] as $code) {
            $this->assertSame('UNKNOWN', $this->survey->record($survey['id'], $code, 'PRESENT')['status']);
        }

        $this->assertSame(0, $this->db->table('dealer_survey_items')->countAllResults());
    }

    public function testTheCounterCountsAgainstWhatTheDealerActuallyHolds(): void
    {
        $units  = [$this->stockAtDealer(), $this->stockAtDealer(), $this->stockAtDealer()];
        $survey = $this->survey->open($this->dealerId);

        $reply = $this->survey->record($survey['id'], $units[0]['serial'], 'PRESENT');

        $this->assertSame(1, $reply['counter']['done']);
        $this->assertSame(3, $reply['counter']['total']);
        $this->assertSame('1 / 3 Mattresses Checked', $reply['counter']['label']);
    }

    // =====================================================================
    // Finishing
    // =====================================================================

    public function testSubmittingFreezesTheFiguresAndSchedulesTheNextVisit(): void
    {
        $units  = [$this->stockAtDealer(), $this->stockAtDealer(), $this->stockAtDealer()];
        $survey = $this->survey->open($this->dealerId);

        $this->survey->record($survey['id'], $units[0]['serial'], 'PRESENT');
        $this->survey->record($survey['id'], $units[1]['serial'], 'DEALER_SAYS_SOLD');
        $this->survey->record($survey['id'], $units[2]['serial'], 'NOT_FOUND');

        $result = $this->survey->submit($survey['id'], 'Shop was tidy; two units unaccounted for.');

        $this->assertSame(3, $result['examined']);
        $this->assertSame(2, $result['discrepancies']);

        $row = $this->db->table('dealer_surveys')->where('id', $survey['id'])->get()->getRowArray();
        $this->assertSame('SUBMITTED', $row['status']);
        $this->assertNotNull($row['submitted_at']);
        $this->assertSame(3, (int) $row['system_total']);
        $this->assertSame(1, (int) $row['physically_found']);
        $this->assertSame(1, (int) $row['reported_sold']);
        $this->assertSame(1, (int) $row['not_found']);
        $this->assertSame(2, (int) $row['discrepancy_count']);
        $this->assertStringContainsString('tidy', $row['agent_remarks']);

        // Six months from today, by default.
        $assignment = $this->db->table('agent_dealer_assignments')
            ->where(['agent_user_id' => $this->agentId, 'dealer_id' => $this->dealerId])->get()->getRowArray();
        $today = local_time(utc_now(), 'Y-m-d');
        $this->assertSame($today, $assignment['last_surveyed_on']);
        $this->assertSame(
            date('Y-m-d', strtotime($today . ' +' . SurveyService::DEFAULT_INTERVAL_DAYS . ' days')),
            $assignment['next_due_on'],
        );
    }

    /** The report must show the day of the visit, not a figure that drifts. */
    public function testTheFrozenFiguresDoNotMoveWhenStockMovesAfterwards(): void
    {
        $units  = [$this->stockAtDealer(), $this->stockAtDealer()];
        $survey = $this->survey->open($this->dealerId);
        $this->survey->record($survey['id'], $units[0]['serial'], 'PRESENT');
        $this->survey->submit($survey['id']);

        $before = $this->db->table('dealer_surveys')->select('system_total')->where('id', $survey['id'])->get()->getRow()->system_total;

        // Three more mattresses arrive the following week.
        $this->stockAtDealer();
        $this->stockAtDealer();
        $this->stockAtDealer();

        $after = $this->db->table('dealer_surveys')->select('system_total')->where('id', $survey['id'])->get()->getRow()->system_total;
        $this->assertSame($before, $after, 'a submitted survey is a record of a day, not a live query');
    }

    public function testASurveyWithNothingCheckedCannotBeSubmitted(): void
    {
        $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);

        $this->expectException(AppException::class);
        $this->survey->submit($survey['id']);
    }

    public function testASubmittedSurveyCannotBeChangedAfterwards(): void
    {
        $unit   = $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);
        $this->survey->record($survey['id'], $unit['serial'], 'PRESENT');
        $this->survey->submit($survey['id']);

        $this->expectException(AppException::class);
        $this->survey->record($survey['id'], $unit['serial'], 'NOT_FOUND');
    }

    /** Each visit is its own record. Nothing is overwritten. */
    public function testASecondVisitDoesNotOverwriteTheFirst(): void
    {
        $unit = $this->stockAtDealer();

        $first = $this->survey->open($this->dealerId);
        $this->survey->record($first['id'], $unit['serial'], 'PRESENT');
        $this->survey->submit($first['id']);

        $second = $this->survey->open($this->dealerId);
        $this->survey->record($second['id'], $unit['serial'], 'NOT_FOUND');
        $this->survey->submit($second['id']);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, $this->db->table('dealer_surveys')->where('dealer_id', $this->dealerId)->countAllResults());
        $this->assertCount(2, $this->survey->history($this->agentId));
    }

    public function testAbandoningASurveyWithdrawsTheDiscrepanciesNobodyHasPickedUp(): void
    {
        $unit   = $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);
        $this->survey->record($survey['id'], $unit['serial'], 'NOT_FOUND');

        $this->survey->abandon($survey['id'], 'Opened at the wrong dealer.');

        $this->assertSame('ABANDONED', $this->db->table('dealer_surveys')->select('status')
            ->where('id', $survey['id'])->get()->getRow()->status);
        $this->assertSame(0, $this->db->table('inventory_discrepancies')->countAllResults());
    }

    // =====================================================================
    // What the agent sees
    // =====================================================================

    public function testADealerNeverSurveyedIsDue(): void
    {
        $assignments = $this->survey->assignments($this->agentId);

        $this->assertCount(1, $assignments);
        $this->assertSame('DUE', $assignments[0]['due_state']);
    }

    public function testADealerPastItsDateIsOverdue(): void
    {
        $this->db->table('agent_dealer_assignments')->where('agent_user_id', $this->agentId)
            ->update(['next_due_on' => date('Y-m-d', strtotime('-10 days'))]);

        $this->assertSame('OVERDUE', $this->survey->assignments($this->agentId)[0]['due_state']);
    }

    public function testAVisitInsideTheNextMonthIsUpcoming(): void
    {
        $this->db->table('agent_dealer_assignments')->where('agent_user_id', $this->agentId)
            ->update(['next_due_on' => date('Y-m-d', strtotime('+10 days'))]);

        $this->assertSame('UPCOMING', $this->survey->assignments($this->agentId)[0]['due_state']);
    }

    public function testTheDashboardCountsWhatTheAgentActuallyHasToDo(): void
    {
        $unit   = $this->stockAtDealer();
        $survey = $this->survey->open($this->dealerId);
        $this->survey->record($survey['id'], $unit['serial'], 'NOT_FOUND');

        $figures = $this->survey->dashboard($this->agentId);

        $this->assertSame(1, $figures['assigned_dealers']);
        $this->assertSame(1, $figures['due']);
        $this->assertSame(1, $figures['surveys_open']);
        $this->assertSame(0, $figures['surveys_completed']);
        $this->assertSame(1, $figures['open_discrepancies']);
    }

    public function testTheDealersStockShowsEveryUnitWithItsLatestStatus(): void
    {
        $available = $this->stockAtDealer();
        $sold      = $this->stockAtDealer('SOLD');

        $rows = $this->survey->dealerStock($this->dealerId);
        $this->assertCount(2, $rows);

        $byId = array_column($rows, null, 'id');
        $this->assertSame('DEALER_RECEIVED', $byId[$available['id']]['current_status']);
        $this->assertSame('SOLD', $byId[$sold['id']]['current_status']);

        $totals = $this->survey->dealerTotals($this->dealerId);
        $this->assertSame(2, $totals['total']);
        $this->assertSame(1, $totals['available']);
        $this->assertSame(1, $totals['sold']);
    }

    public function testTheStockListCanBeNarrowedDown(): void
    {
        $this->stockAtDealer();
        $sold = $this->stockAtDealer('SOLD');

        $this->assertCount(1, $this->survey->dealerStock($this->dealerId, ['status' => 'SOLD']));
        $this->assertCount(1, $this->survey->dealerStock($this->dealerId, ['q' => $sold['serial']]));
        $this->assertCount(0, $this->survey->dealerStock($this->dealerId, ['q' => 'NOTHING-LIKE-THIS']));
    }

    // =====================================================================

    private function assign(string $agentId, string $dealerId): void
    {
        $this->insert('agent_dealer_assignments', [
            'id' => uuid4(), 'agent_user_id' => $agentId, 'dealer_id' => $dealerId,
            'survey_interval_days' => SurveyService::DEFAULT_INTERVAL_DAYS,
            'active' => 1, 'created_at' => utc_now(),
        ]);
    }

    /** @return array{id:string, serial:string, qr_token:string} */
    private function stockAtDealer(string $status = 'DEALER_RECEIVED'): array
    {
        return $this->makeMattress($this->variant, $status, $this->dealerId);
    }

    private function statusOf(string $mattressId): string
    {
        return $this->db->table('mattresses')->select('current_status')
            ->where('id', $mattressId)->get()->getRow()->current_status;
    }
}

<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Services\DispatchPicking;
use App\Services\LogisticsService;
use App\Services\SalesService;
use App\Services\SurveyService;
use App\Services\WarehouseService;
use App\Services\WarrantyReviewService;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\PolyfixTestCase;

/**
 * The acceptance scenarios, one test each.
 *
 * The other test files are organised by the code they cover. This one is
 * organised by what the business asked to see working, end to end, so that
 * the list can be read against the brief without tracing anything. Each
 * test name is the scenario.
 *
 * Two of the required checks are not here, and cannot honestly be: a
 * refused camera and a device with no camera happen in the browser, not in
 * PHP. They are handled in public/assets/js/qr-scanner.js — every failure
 * name getUserMedia can raise is matched to a plain explanation, and the
 * typed-serial box stays usable throughout — and were exercised in a real
 * browser rather than asserted here.
 *
 * @internal
 */
final class AcceptanceScenariosTest extends PolyfixTestCase
{
    use FeatureTestTrait;

    private string $warehouseUser;
    private string $agentUser;
    private string $managerUser;
    private string $dealerId;
    private string $dealerUser;
    private string $variant;
    private string $otherVariant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealerId      = $this->makeDealer();
        $this->warehouseUser = $this->makeUser(['WAREHOUSE']);
        $this->agentUser     = $this->makeUser(['FIELD_AGENT']);
        $this->managerUser   = $this->makeUser(['WARRANTY_MANAGER']);
        $this->dealerUser    = $this->makeUser(['DEALER'], $this->dealerId);
        $this->variant       = $this->makeProduct()['variant'];
        $this->otherVariant  = $this->makeProduct()['variant'];

        $this->insert('agent_dealer_assignments', [
            'id' => uuid4(), 'agent_user_id' => $this->agentUser, 'dealer_id' => $this->dealerId,
            'survey_interval_days' => SurveyService::DEFAULT_INTERVAL_DAYS, 'active' => 1, 'created_at' => utc_now(),
        ]);
    }

    // =====================================================================
    // 1–3  Manufacturing and production scanning
    // =====================================================================

    /** 1. Create a batch of a hundred and scan every one in. */
    public function testScenario01_ABatchOfAHundredIsOpenedAndScannedInWithoutTypingASerial(): void
    {
        $warehouse = $this->asWarehouse();
        $batch     = $warehouse->openBatch($this->plant(), [
            ['product_variant_id' => $this->variant, 'quantity' => 60],
            ['product_variant_id' => $this->otherVariant, 'quantity' => 40],
        ]);

        $this->assertSame(100, $batch['count']);

        $units = $warehouse->batchUnits($batch['id']);
        foreach ($units as $unit) {
            // The QR code off the printed label, not a typed serial.
            $scanned = \App\Libraries\Labels::verifyUrl($unit['qr_token']);
            $this->assertSame('ACCEPTED', $warehouse->productionScan($batch['id'], $scanned)['status']);
        }

        $progress = $warehouse->batchProgress($batch['id']);
        $this->assertSame(100, $progress['scanned']);
        $this->assertSame('100 / 100 Mattresses Scanned', $progress['counter']['label']);
    }

    /** 2. The same label scanned again adds nothing. */
    public function testScenario02_ScanningTheSameMattressTwiceDoesNotCountItTwice(): void
    {
        $warehouse = $this->asWarehouse();
        $batch     = $this->batchOf($warehouse, 5);
        $unit      = $warehouse->batchUnits($batch['id'])[0];

        $warehouse->productionScan($batch['id'], $unit['serial_number']);
        $again = $warehouse->productionScan($batch['id'], $unit['serial_number']);

        $this->assertSame('Already Scanned — No Duplicate Added', $again['title']);
        $this->assertSame(1, $warehouse->batchProgress($batch['id'])['scanned']);
    }

    /** 3. Something that is not one of our labels is refused cleanly. */
    public function testScenario03_AnInvalidQrCodeIsRefusedWithoutChangingAnything(): void
    {
        $warehouse = $this->asWarehouse();
        $batch     = $this->batchOf($warehouse, 3);

        foreach (['', 'hello', 'https://example.com/?q=rubbish', 'PFZ-2026-999999'] as $code) {
            $reply = $warehouse->productionScan($batch['id'], $code);
            $this->assertFalse($reply['ok'], "\"{$code}\" must not be accepted");
        }

        $this->assertSame(0, $warehouse->batchProgress($batch['id'])['scanned']);
    }

    // =====================================================================
    // 4–7  Dispatch
    // =====================================================================

    /** 4. Pick a consignment by ticking a whole batch. */
    public function testScenario04_AConsignmentIsPickedByTickingAWholeBatch(): void
    {
        $warehouse = $this->asWarehouse();
        $batch     = $this->batchOf($warehouse, 12);
        $picking   = new DispatchPicking($this->db, service('requestContext'));

        $dispatch = $picking->start($this->plant() + ['dealer_id' => $this->dealerId]);
        $result   = $picking->addUnits($dispatch['id'], array_column($picking->pickable($this->warehouseId(), $batch['id']), 'id'));

        $this->assertSame(12, $result['added']);
        $this->assertCount(12, $picking->items($dispatch['id']));
    }

    /** 5. Pick a consignment by scanning each unit onto it. */
    public function testScenario05_AConsignmentIsPickedByScanning(): void
    {
        $warehouse = $this->asWarehouse();
        $batch     = $this->batchOf($warehouse, 6);
        $picking   = new DispatchPicking($this->db, service('requestContext'));
        $dispatch  = $picking->start($this->plant() + ['dealer_id' => $this->dealerId]);

        foreach ($warehouse->batchUnits($batch['id']) as $unit) {
            $this->assertSame('ACCEPTED', $picking->scan($dispatch['id'], $unit['serial_number'])['status']);
        }

        $this->assertCount(6, $picking->items($dispatch['id']));
    }

    /** 6. Scan some, tick the rest, take one back off. */
    public function testScenario06_ScanningAndTickingAndRemovingAllWorkOnOneConsignment(): void
    {
        $warehouse = $this->asWarehouse();
        $batch     = $this->batchOf($warehouse, 8);
        $units     = $warehouse->batchUnits($batch['id']);
        $picking   = new DispatchPicking($this->db, service('requestContext'));
        $dispatch  = $picking->start($this->plant() + ['dealer_id' => $this->dealerId]);

        $picking->scan($dispatch['id'], $units[0]['serial_number']);
        $picking->scan($dispatch['id'], $units[1]['serial_number']);
        $picking->addUnits($dispatch['id'], [$units[2]['id'], $units[3]['id'], $units[4]['id']]);
        $picking->removeUnits($dispatch['id'], [$units[0]['id']]);

        $this->assertCount(4, $picking->items($dispatch['id']));
        $this->assertSame('MANUFACTURED', $this->statusOf($units[0]['id']), 'the one taken off is free stock again');
    }

    /** 7. Confirming the dispatch moves every unit, in one transaction. */
    public function testScenario07_ConfirmingTheDispatchHandsEveryUnitToTheDealer(): void
    {
        $sent = $this->aDispatchOf(5);

        foreach ($sent['units'] as $unit) {
            $this->assertSame('DISPATCHED', $this->statusOf($unit['id']));
        }

        $row = $this->db->table('dispatches')->where('id', $sent['dispatch']['id'])->get()->getRowArray();
        $this->assertSame('DISPATCHED', $row['status']);
        $this->assertNotNull($row['dispatched_at']);
        $this->assertSame($this->dealerId, $row['dealer_id'], 'the dealer can now see it coming');
    }

    // =====================================================================
    // 8–10  Dealer receipt and sale
    // =====================================================================

    /** 8. The dealer receives everything that arrived. */
    public function testScenario08_TheDealerReceivesTheWholeConsignment(): void
    {
        $sent = $this->aDispatchOf(4);

        $this->actingAs($this->dealerUser);
        $lines = array_map(static fn ($u) => ['serial' => $u['serial_number'], 'condition' => 'OK'], $sent['units']);
        (new LogisticsService($this->db, service('requestContext')))->receive($sent['dispatch']['id'], $lines);

        foreach ($sent['units'] as $unit) {
            $this->assertSame('DEALER_RECEIVED', $this->statusOf($unit['id']));
        }
        $this->assertSame('RECEIVED', $this->db->table('dispatches')->select('status')
            ->where('id', $sent['dispatch']['id'])->get()->getRow()->status);
    }

    /** 9. A partial receipt, with one missing and one damaged. */
    public function testScenario09_APartialReceiptRecordsWhatArrivedAndWhatDidNot(): void
    {
        $sent = $this->aDispatchOf(4);

        $this->actingAs($this->dealerUser);
        $logistics = new LogisticsService($this->db, service('requestContext'));
        $logistics->receive($sent['dispatch']['id'], [
            ['serial' => $sent['units'][0]['serial_number'], 'condition' => 'OK'],
            ['serial' => $sent['units'][1]['serial_number'], 'condition' => 'DAMAGED', 'remarks' => 'Torn in transit.'],
            ['serial' => $sent['units'][2]['serial_number'], 'condition' => 'MISSING'],
        ], 'One carton short.');

        $status = $this->db->table('dispatches')->select('status')
            ->where('id', $sent['dispatch']['id'])->get()->getRow()->status;

        $this->assertSame('PARTIALLY_RECEIVED', $status, 'the fourth unit is still outstanding');
        $this->assertSame('DEALER_RECEIVED', $this->statusOf($sent['units'][0]['id']));
        $this->assertSame('DISPATCHED', $this->statusOf($sent['units'][3]['id']), 'nothing was assumed about the one not scanned');
    }

    /** 10. The dealer sells one, and the warranty starts. */
    public function testScenario10_RecordingASaleActivatesTheWarranty(): void
    {
        $sold = $this->aSoldUnit();

        $this->assertSame('SOLD', $this->statusOf($sold['mattress_id']));
        $this->assertSame('ACTIVE', $this->db->table('warranties')->select('status')
            ->where('id', $sold['warranty_id'])->get()->getRow()->status);
    }

    // =====================================================================
    // 11–14  The field agent
    // =====================================================================

    /** 11. The agent opens a dealer and sees real quantities, not a guess. */
    public function testScenario11_TheAgentSeesTheDealersActualStock(): void
    {
        $sent = $this->aDispatchOf(3);
        $this->receiveAll($sent);

        $survey = $this->asAgent();
        $totals = $survey->dealerTotals($this->dealerId);

        $this->assertSame(3, $totals['total']);
        $this->assertSame(3, $totals['available']);
        $this->assertCount(3, $survey->dealerStock($this->dealerId));
    }

    /** 12. A unit that is where the records say it is. */
    public function testScenario12_AMatchingUnitRaisesNothing(): void
    {
        $sent = $this->aDispatchOf(2);
        $this->receiveAll($sent);

        $service = $this->asAgent();
        $visit   = $service->open($this->dealerId);
        $reply   = $service->record($visit['id'], $sent['units'][0]['serial_number'], 'PRESENT');

        $this->assertSame('MATCHED', $reply['outcome']);
        $this->assertSame(0, $this->db->table('inventory_discrepancies')->countAllResults());
    }

    /** 13. The dealer says it sold. No sale is written. */
    public function testScenario13_ADealerSayingItSoldRaisesAReviewAndCreatesNoSale(): void
    {
        $sent = $this->aDispatchOf(2);
        $this->receiveAll($sent);
        $unit = $sent['units'][0];

        $service = $this->asAgent();
        $visit   = $service->open($this->dealerId);
        $reply   = $service->record($visit['id'], $unit['serial_number'], 'DEALER_SAYS_SOLD', 'Dealer says it went in March.');

        $this->assertSame('POSSIBLE_UNRECORDED_SALE', $reply['outcome']);
        $this->assertSame('DEALER_RECEIVED', $this->statusOf($unit['id']), 'the mattress did not move');
        $this->assertSame(0, $this->db->table('sales')->where('mattress_id', $unit['id'])->countAllResults());
        $this->assertSame(1, $this->db->table('inventory_discrepancies')
            ->where(['mattress_id' => $unit['id'], 'status' => 'OPEN'])->countAllResults());
    }

    /** 14. Stock that is not on the shelf. */
    public function testScenario14_MissingStockIsRaisedAsADiscrepancy(): void
    {
        $sent = $this->aDispatchOf(2);
        $this->receiveAll($sent);

        $service = $this->asAgent();
        $visit   = $service->open($this->dealerId);
        $service->record($visit['id'], $sent['units'][0]['serial_number'], 'NOT_FOUND');
        $service->record($visit['id'], $sent['units'][1]['serial_number'], 'PRESENT');
        $result = $service->submit($visit['id'], 'One unaccounted for.');

        $this->assertSame(2, $result['examined']);
        $this->assertSame(1, $result['discrepancies']);
        $this->assertSame(1, $this->db->table('inventory_discrepancies')
            ->where('kind', 'STOCK_DISCREPANCY')->countAllResults());
    }

    // =====================================================================
    // 15–16  Warranty review
    // =====================================================================

    /** 15. The agent recommends rejection. The warranty is untouched. */
    public function testScenario15_AnAgentRecommendingRejectionDoesNotEndTheWarranty(): void
    {
        $sold = $this->aSoldUnit();

        $this->actingAs($this->agentUser);
        $case = (new WarrantyReviewService($this->db, service('requestContext')))
            ->recommend($sold['mattress_id'], 'INVOICE_NOT_UPLOADED', 'Nothing on file.');

        $this->assertSame('RECOMMENDED', $this->db->table('warranty_review_cases')
            ->select('status')->where('id', $case['id'])->get()->getRow()->status);
        $this->assertSame('ACTIVE', $this->db->table('warranties')
            ->select('status')->where('id', $sold['warranty_id'])->get()->getRow()->status);
    }

    /** 16. The office upholds it, then puts it back. */
    public function testScenario16_TheOfficeDecidesAndCanUndoTheDecision(): void
    {
        $sold = $this->aSoldUnit();

        $this->actingAs($this->agentUser);
        $case = (new WarrantyReviewService($this->db, service('requestContext')))
            ->recommend($sold['mattress_id'], 'SALE_NOT_REGISTERED');

        $this->actingAs($this->managerUser);
        $review = new WarrantyReviewService($this->db, service('requestContext'));

        $review->uphold($case['id'], 'No invoice after two requests.');
        $this->assertSame('VOID', $this->db->table('warranties')->select('status')
            ->where('id', $sold['warranty_id'])->get()->getRow()->status);

        $review->restore($case['id'], 'Invoice turned up. Our error.');
        $this->assertSame('ACTIVE', $this->db->table('warranties')->select('status')
            ->where('id', $sold['warranty_id'])->get()->getRow()->status);
    }

    // =====================================================================
    // 17–18  Scheduling and history
    // =====================================================================

    /** 17. Finishing a survey schedules the next one six months out. */
    public function testScenario17_SubmittingASurveySchedulesTheNextVisit(): void
    {
        $sent = $this->aDispatchOf(1);
        $this->receiveAll($sent);

        $service = $this->asAgent();
        $visit   = $service->open($this->dealerId);
        $service->record($visit['id'], $sent['units'][0]['serial_number'], 'PRESENT');
        $service->submit($visit['id']);

        $today = local_time(utc_now(), 'Y-m-d');
        $row   = $this->db->table('agent_dealer_assignments')
            ->where(['agent_user_id' => $this->agentUser, 'dealer_id' => $this->dealerId])->get()->getRowArray();

        $this->assertSame($today, $row['last_surveyed_on']);
        $this->assertSame(date('Y-m-d', strtotime($today . ' +182 days')), $row['next_due_on']);
        $this->assertSame('SCHEDULED', $service->assignments($this->agentUser)[0]['due_state']);
    }

    /** 18. One serial, from the line to the shelf, in one history. */
    public function testScenario18_OneSerialCarriesItsWholeHistory(): void
    {
        $warehouse = $this->asWarehouse();
        $batch     = $this->batchOf($warehouse, 1);
        $unit      = $warehouse->batchUnits($batch['id'])[0];
        $warehouse->productionScan($batch['id'], $unit['serial_number']);

        $picking  = new DispatchPicking($this->db, service('requestContext'));
        $dispatch = $picking->start($this->plant() + ['dealer_id' => $this->dealerId]);
        $picking->scan($dispatch['id'], $unit['serial_number']);
        (new LogisticsService($this->db, service('requestContext')))->sendDispatch($dispatch['id']);

        $this->actingAs($this->dealerUser);
        (new LogisticsService($this->db, service('requestContext')))->receive($dispatch['id'], [
            ['serial' => $unit['serial_number'], 'condition' => 'OK'],
        ]);

        $service = $this->asAgent();
        $visit   = $service->open($this->dealerId);
        $service->record($visit['id'], $unit['serial_number'], 'PRESENT');

        $events = array_column($this->db->table('mattress_events')->select('event_type')
            ->where('mattress_id', $unit['id'])->orderBy('occurred_at')->get()->getResultArray(), 'event_type');

        foreach (['MANUFACTURED', 'PRODUCTION_SCANNED', 'ADDED_TO_DISPATCH', 'DISPATCHED'] as $expected) {
            $this->assertContains($expected, $events, "the history is missing {$expected}");
        }

        $this->assertSame(2, $this->db->table('warehouse_scans')->where('mattress_id', $unit['id'])->countAllResults(),
            'counted into production, and picked onto a lorry');
        $this->assertSame(1, $this->db->table('dealer_survey_items')->where('mattress_id', $unit['id'])->countAllResults(),
            'and seen on a field visit');
    }

    // =====================================================================
    // Edge cases the brief names
    // =====================================================================

    /** Two operators scanning the same label at the same moment. */
    public function testEdge_TwoOperatorsCannotBothCountTheSameMattress(): void
    {
        $warehouse = $this->asWarehouse();
        $batch     = $this->batchOf($warehouse, 2);
        $unit      = $warehouse->batchUnits($batch['id'])[0];
        $second    = $this->makeUser(['WAREHOUSE']);

        $warehouse->productionScan($batch['id'], $unit['serial_number']);

        $this->actingAs($second);
        $theirs = new WarehouseService($this->db, service('requestContext'));
        $this->assertSame('DUPLICATE', $theirs->productionScan($batch['id'], $unit['serial_number'])['status']);
        $this->assertSame(1, $theirs->batchProgress($batch['id'])['scanned']);
    }

    /** Two consignments cannot hold one mattress. */
    public function testEdge_AMattressIsNeverOnTwoConsignments(): void
    {
        $warehouse = $this->asWarehouse();
        $batch     = $this->batchOf($warehouse, 2);
        $unit      = $warehouse->batchUnits($batch['id'])[0];

        $picking = new DispatchPicking($this->db, service('requestContext'));
        $mine    = $picking->start($this->plant() + ['dealer_id' => $this->dealerId]);
        $yours   = $picking->start($this->plant() + ['dealer_id' => $this->makeDealer()]);

        $picking->addUnits($mine['id'], [$unit['id']]);
        $this->assertSame('REJECTED', $picking->scan($yours['id'], $unit['serial_number'])['status']);
        $this->assertSame(1, $this->db->table('dispatch_items')->where('mattress_id', $unit['id'])->countAllResults());
    }

    /** An agent losing signal and reopening carries on with the same survey. */
    public function testEdge_ReopeningASurveyResumesItRatherThanStartingASecond(): void
    {
        $sent = $this->aDispatchOf(2);
        $this->receiveAll($sent);

        $service = $this->asAgent();
        $first   = $service->open($this->dealerId);
        $service->record($first['id'], $sent['units'][0]['serial_number'], 'PRESENT');

        $resumed = $service->open($this->dealerId);

        $this->assertSame($first['id'], $resumed['id']);
        $this->assertTrue($resumed['resumed']);
        $this->assertSame(1, $this->db->table('dealer_survey_items')->where('survey_id', $first['id'])->countAllResults());
    }

    /** A failure part way through leaves nothing half-done. */
    public function testEdge_AFailedOperationRollsBackCompletely(): void
    {
        $warehouse = $this->asWarehouse();
        $before    = $this->db->table('manufacturing_batches')->countAllResults();

        try {
            $warehouse->openBatch($this->plant(), [['product_variant_id' => uuid4(), 'quantity' => 25]]);
            $this->fail('a batch of an unknown size should be refused');
        } catch (AppException) {
            // expected
        }

        $this->assertSame($before, $this->db->table('manufacturing_batches')->countAllResults());
        $this->assertSame(0, $this->db->table('mattresses')->countAllResults());
    }

    /** A dispatch that cannot be honoured is refused outright. */
    public function testEdge_AnInvalidDispatchIsRefusedRatherThanPartlyMade(): void
    {
        $sent = $this->aDispatchOf(2);   // already sent, so the units are gone
        $this->asWarehouse();

        $picking = new DispatchPicking($this->db, service('requestContext'));
        $another = $picking->start($this->plant() + ['dealer_id' => $this->dealerId]);

        $this->expectException(AppException::class);
        $picking->addUnits($another['id'], array_column($sent['units'], 'id'));
    }

    /**
     * Who may do what, as the brief states it.
     *
     * @dataProvider boundaries
     */
    public function testEdge_RolesCannotReachWhatTheBriefPutsOutsideTheirReach(string $role, string $permission): void
    {
        $this->assertNotContains(
            $permission,
            \App\Libraries\Rbac::ROLE_PERMISSIONS[$role],
            "a {$role} must not hold {$permission}",
        );
    }

    public static function boundaries(): array
    {
        return [
            // Warehouse: no owner data, no other employees, no settings.
            'warehouse cannot read sales'        => ['WAREHOUSE', 'sale:read'],
            'warehouse cannot read customers'    => ['WAREHOUSE', 'customer:read'],
            'warehouse cannot read users'        => ['WAREHOUSE', 'user:read'],
            'warehouse cannot change settings'   => ['WAREHOUSE', 'system:settings:write'],
            'warehouse cannot read the audit'    => ['WAREHOUSE', 'audit:read'],
            // Agent: reports, never rewrites.
            'agent cannot cancel a dispatch'     => ['FIELD_AGENT', 'dispatch:cancel'],
            'agent cannot create a mattress'     => ['FIELD_AGENT', 'mattress:create'],
            'agent cannot delete a mattress'     => ['FIELD_AGENT', 'mattress:delete'],
            'agent cannot scan into the plant'   => ['FIELD_AGENT', 'warehouse:scan'],
            'agent cannot void a warranty'       => ['FIELD_AGENT', 'warranty:void'],
            'agent cannot decide a review'       => ['FIELD_AGENT', 'warranty_review:decide'],
            'agent cannot record a sale'         => ['FIELD_AGENT', 'sale:create'],
            // Dealer: their own records only, and no staff abilities.
            'dealer cannot dispatch'             => ['DEALER', 'dispatch:create'],
            'dealer cannot decide a claim'       => ['DEALER', 'claim:decide'],
            'dealer cannot conduct a survey'     => ['DEALER', 'survey:conduct'],
            'dealer cannot resolve discrepancies' => ['DEALER', 'discrepancy:resolve'],
        ];
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function asWarehouse(): WarehouseService
    {
        $this->actingAs($this->warehouseUser);

        return new WarehouseService($this->db, service('requestContext'));
    }

    private function asAgent(): SurveyService
    {
        $this->actingAs($this->agentUser);

        return new SurveyService($this->db, service('requestContext'));
    }

    /** @return array{warehouse_id:string, manufactured_on:string} */
    private function plant(): array
    {
        return ['warehouse_id' => $this->warehouseId(), 'manufactured_on' => local_time(utc_now(), 'Y-m-d')];
    }

    /** @return array<string,mixed> */
    private function batchOf(WarehouseService $warehouse, int $quantity): array
    {
        return $warehouse->openBatch($this->plant(), [
            ['product_variant_id' => $this->variant, 'quantity' => $quantity],
        ]);
    }

    /** A sent consignment and the units on it. @return array{dispatch:array, units:list<array>} */
    private function aDispatchOf(int $quantity): array
    {
        $warehouse = $this->asWarehouse();
        $batch     = $this->batchOf($warehouse, $quantity);
        $units     = $warehouse->batchUnits($batch['id']);

        $picking  = new DispatchPicking($this->db, service('requestContext'));
        $dispatch = $picking->start($this->plant() + ['dealer_id' => $this->dealerId]);
        $picking->addUnits($dispatch['id'], array_column($units, 'id'));
        (new LogisticsService($this->db, service('requestContext')))->sendDispatch($dispatch['id']);

        return ['dispatch' => $dispatch, 'units' => $units];
    }

    /** @param array{dispatch:array, units:list<array>} $sent */
    private function receiveAll(array $sent): void
    {
        $this->actingAs($this->dealerUser);
        (new LogisticsService($this->db, service('requestContext')))->receive(
            $sent['dispatch']['id'],
            array_map(static fn ($u) => ['serial' => $u['serial_number'], 'condition' => 'OK'], $sent['units']),
        );
    }

    /** @return array{mattress_id:string, warranty_id:string, serial:string} */
    private function aSoldUnit(): array
    {
        $sent = $this->aDispatchOf(1);
        $this->receiveAll($sent);
        $unit = $sent['units'][0];

        $this->actingAs($this->dealerUser);
        $sale = (new SalesService($this->db, service('requestContext'), $this->crypto()))->recordSale([
            'serial'         => $unit['serial_number'],
            'invoice_number' => 'INV-' . random_int(10_000, 99_999),
            'sold_on'        => utc_today(),
            'sale_price'     => 17_999,
            'payment_mode'   => 'CARD',
            'customer'       => [
                'full_name' => 'Acceptance Customer', 'phone' => '98765' . random_int(10_000, 99_999),
                'city' => 'Jaipur', 'state' => 'Rajasthan',
            ],
        ]);

        return ['mattress_id' => $unit['id'], 'warranty_id' => $sale['warranty_id'], 'serial' => $unit['serial_number']];
    }

    private function statusOf(string $mattressId): string
    {
        return $this->db->table('mattresses')->select('current_status')
            ->where('id', $mattressId)->get()->getRow()->current_status;
    }
}

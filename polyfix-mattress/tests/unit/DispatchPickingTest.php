<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Services\DispatchPicking;
use App\Services\LogisticsService;
use App\Services\WarehouseService;
use Tests\Support\PolyfixTestCase;

/**
 * Smart dispatch: building a consignment without typing a serial.
 *
 * The guarantee that matters is that a mattress is in exactly one place. It
 * must never be on two consignments, never free in the plant while listed on
 * a lorry, and never sold while sitting in stock. So the tests here are
 * mostly about what happens when two people pick at once, and what happens
 * when something fails halfway.
 *
 * @internal
 */
final class DispatchPickingTest extends PolyfixTestCase
{
    private DispatchPicking $picking;
    private WarehouseService $warehouse;
    private string $dealerId;
    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->makeUser(['WAREHOUSE']));
        $this->picking   = new DispatchPicking($this->db, service('requestContext'));
        $this->warehouse = new WarehouseService($this->db, service('requestContext'));
        $this->dealerId  = $this->makeDealer();
        $this->variant   = $this->makeProduct()['variant'];
    }

    // =====================================================================
    // Method A and C: picking by batch, in bulk
    // =====================================================================

    public function testTickingAWholeBatchPutsEveryUnitOnTheConsignment(): void
    {
        $units    = $this->stock(8);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        $result = $this->picking->addUnits($dispatch['id'], array_column($units, 'id'));

        $this->assertSame(8, $result['added']);
        $this->assertSame(8, $result['total']);
        $this->assertCount(8, $this->picking->items($dispatch['id']));

        // Each one is reserved the moment it is listed, so no second
        // consignment can take it.
        foreach ($units as $unit) {
            $this->assertSame('IN_DISPATCH', $this->statusOf($unit['id']));
        }
    }

    public function testPickingBySizeTakesOnlyThatSize(): void
    {
        $other = $this->makeProduct()['variant'];
        $batch = $this->warehouse->openBatch([
            'warehouse_id' => $this->warehouseId(), 'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [
            ['product_variant_id' => $this->variant, 'quantity' => 4],
            ['product_variant_id' => $other, 'quantity' => 6],
        ]);

        $wanted = $this->picking->pickable($this->warehouseId(), $batch['id'], $other);
        $this->assertCount(6, $wanted, 'filtering by size offers only that size');

        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);
        $this->picking->addUnits($dispatch['id'], array_column($wanted, 'id'));

        $this->assertCount(6, $this->picking->items($dispatch['id']));
        $this->assertSame(4, count($this->picking->pickable($this->warehouseId(), $batch['id'])),
            'the other size is still in free stock');
    }

    /** A form submitted twice should leave the unit listed once, quietly. */
    public function testAddingTheSameUnitTwiceLeavesItListedOnce(): void
    {
        $units    = $this->stock(3);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);
        $ids      = array_column($units, 'id');

        $this->picking->addUnits($dispatch['id'], $ids);
        $second = $this->picking->addUnits($dispatch['id'], $ids);

        $this->assertSame(0, $second['added']);
        $this->assertSame(3, $second['skipped']);
        $this->assertSame(3, $second['total']);
        $this->assertCount(3, $this->picking->items($dispatch['id']));
    }

    /**
     * Adding most of a stack and silently leaving two behind is how a lorry
     * goes out short, so one bad unit refuses the whole add.
     */
    public function testOneUnavailableUnitRefusesTheWholeAdd(): void
    {
        $units    = $this->stock(5);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        // One has already gone to a dealer.
        $this->db->table('mattresses')->where('id', $units[2]['id'])->update([
            'current_status' => 'DEALER_RECEIVED', 'current_dealer_id' => $this->dealerId,
            'current_warehouse_id' => null, 'received_at' => utc_now(),
        ]);

        try {
            $this->picking->addUnits($dispatch['id'], array_column($units, 'id'));
            $this->fail('the add should have been refused');
        } catch (AppException $e) {
            $this->assertStringContainsString('Nothing was added', $e->getMessage());
        }

        $this->assertCount(0, $this->picking->items($dispatch['id']), 'not one unit was listed');
        $this->assertSame('MANUFACTURED', $this->statusOf($units[0]['id']), 'and none were reserved');
    }

    public function testTakingAUnitOffReturnsItToFreeStock(): void
    {
        $units    = $this->stock(4);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);
        $this->picking->addUnits($dispatch['id'], array_column($units, 'id'));

        $result = $this->picking->removeUnits($dispatch['id'], [$units[0]['id'], $units[1]['id']]);

        $this->assertSame(2, $result['removed']);
        $this->assertSame(2, $result['total']);
        $this->assertSame('MANUFACTURED', $this->statusOf($units[0]['id']));
        $this->assertCount(2, $this->picking->pickable($this->warehouseId()), 'both are pickable again');
    }

    /** Taking a unit off must let it be scanned on again. */
    public function testAUnitTakenOffCanBeScannedBackOn(): void
    {
        $units    = $this->stock(2);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        $this->picking->scan($dispatch['id'], $units[0]['serial_number']);
        $this->picking->removeUnits($dispatch['id'], [$units[0]['id']]);

        $again = $this->picking->scan($dispatch['id'], $units[0]['serial_number']);
        $this->assertSame('ACCEPTED', $again['status'], 'the old scan record must not block it');
        $this->assertSame(1, $again['counter']['done']);
    }

    // =====================================================================
    // Method B: picking by scanning
    // =====================================================================

    public function testScanningALabelAddsItAndCountsTheLoad(): void
    {
        $units    = $this->stock(3);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        $reply = $this->picking->scan($dispatch['id'], $units[0]['serial_number']);

        $this->assertTrue($reply['ok']);
        $this->assertSame('ACCEPTED', $reply['status']);
        $this->assertSame('1 Mattress Loaded', $reply['counter']['label']);
        $this->assertSame($units[0]['id'], $reply['mattress_id'], 'the screen needs to know which box to tick');
        $this->assertSame('IN_DISPATCH', $this->statusOf($units[0]['id']));
    }

    public function testScanningTheSameLabelTwiceSaysSoAndAddsNothing(): void
    {
        $units    = $this->stock(3);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        $this->picking->scan($dispatch['id'], $units[0]['serial_number']);
        $second = $this->picking->scan($dispatch['id'], $units[0]['serial_number']);

        $this->assertSame('DUPLICATE', $second['status']);
        $this->assertSame('Already Scanned — No Duplicate Added', $second['title']);
        $this->assertSame(1, $second['counter']['done']);
        $this->assertCount(1, $this->picking->items($dispatch['id']));
    }

    /** Held in frame or waved past ten times: one mattress, one line. */
    public function testRepeatedScansNeverLoadTheSameMattressTwice(): void
    {
        $units    = $this->stock(2);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        for ($i = 0; $i < 10; $i++) {
            $this->picking->scan($dispatch['id'], $units[0]['serial_number']);
        }

        $this->assertCount(1, $this->picking->items($dispatch['id']));
        $this->assertSame(1, $this->db->table('warehouse_scans')
            ->where(['dispatch_id' => $dispatch['id'], 'purpose' => 'DISPATCH'])->countAllResults());
    }

    /**
     * The one case where "already scanned" would mislead: the unit is on
     * somebody else's lorry, and the operator has to be told whose.
     */
    public function testAUnitOnAnotherConsignmentNamesThatConsignment(): void
    {
        $units = $this->stock(3);
        $mine  = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);
        $yours = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->makeDealer()]);

        $this->picking->addUnits($yours['id'], [$units[0]['id']]);

        $reply = $this->picking->scan($mine['id'], $units[0]['serial_number']);

        $this->assertSame('REJECTED', $reply['status']);
        $this->assertStringContainsString($yours['dispatch_code'], $reply['message']);
        $this->assertCount(0, $this->picking->items($mine['id']));
    }

    public function testAnUnknownLabelIsAnsweredPlainlyAndLoadsNothing(): void
    {
        $this->stock(2);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        foreach (['', 'nonsense', 'PFZ-2026-999999'] as $code) {
            $reply = $this->picking->scan($dispatch['id'], $code);
            $this->assertSame('UNKNOWN', $reply['status'], "\"{$code}\" must be reported as unrecognised");
        }

        $this->assertCount(0, $this->picking->items($dispatch['id']));
    }

    public function testAUnitAlreadySoldCannotBeScannedOntoALorry(): void
    {
        $units    = $this->stock(2);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        $this->db->table('mattresses')->where('id', $units[0]['id'])->update([
            'current_status' => 'SOLD', 'current_dealer_id' => $this->dealerId, 'current_warehouse_id' => null,
            'received_at' => gmdate('Y-m-d H:i:s', strtotime('-40 days')) . '.000000',
            'sold_at' => gmdate('Y-m-d H:i:s', strtotime('-10 days')) . '.000000',
        ]);

        $reply = $this->picking->scan($dispatch['id'], $units[0]['serial_number']);

        $this->assertSame('REJECTED', $reply['status']);
        $this->assertStringContainsString('sold', strtolower($reply['message']));
        $this->assertCount(0, $this->picking->items($dispatch['id']));
    }

    public function testTheQrCodeOnTheLabelLoadsTheUnitJustAsTheSerialDoes(): void
    {
        $units    = $this->stock(2);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        $reply = $this->picking->scan($dispatch['id'], \App\Libraries\Labels::verifyUrl($units[0]['qr_token']));

        $this->assertSame('ACCEPTED', $reply['status']);
        $this->assertCount(1, $this->picking->items($dispatch['id']));
    }

    // =====================================================================
    // The guarantee
    // =====================================================================

    /**
     * The rule the business stated outright: a mattress must never be both
     * available in the warehouse and gone. Picked onto a lorry, it is no
     * longer free stock, and no second lorry can have it.
     */
    public function testAMattressIsNeverInTwoPlacesAtOnce(): void
    {
        $units = $this->stock(3);
        $mine  = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);
        $yours = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->makeDealer()]);

        $this->picking->addUnits($mine['id'], [$units[0]['id']]);

        // Free stock no longer offers it.
        $this->assertNotContains($units[0]['id'], array_column($this->picking->pickable($this->warehouseId()), 'id'));

        // And the other consignment cannot take it.
        try {
            $this->picking->addUnits($yours['id'], [$units[0]['id']]);
            $this->fail('a unit already on a consignment must not be addable to a second');
        } catch (AppException) {
            // expected
        }

        $this->assertSame(1, $this->db->table('dispatch_items')->where('mattress_id', $units[0]['id'])->countAllResults(),
            'exactly one consignment lists it');
    }

    /** A sent consignment is closed: nothing more goes on it. */
    public function testNothingCanBeAddedOnceTheConsignmentHasBeenSent(): void
    {
        $units    = $this->stock(3);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);
        $this->picking->addUnits($dispatch['id'], [$units[0]['id']]);

        (new LogisticsService($this->db, service('requestContext')))->sendDispatch($dispatch['id']);

        $this->expectException(AppException::class);
        $this->picking->scan($dispatch['id'], $units[1]['serial_number']);
    }

    public function testAnEmptyConsignmentCannotBeSent(): void
    {
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        $this->expectException(AppException::class);
        (new LogisticsService($this->db, service('requestContext')))->sendDispatch($dispatch['id']);
    }

    public function testStockCannotBeSentToASuspendedDealer(): void
    {
        $this->db->table('dealers')->where('id', $this->dealerId)->update(['status' => 'SUSPENDED']);

        $this->expectException(AppException::class);
        $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);
    }

    /** Sending it is what hands custody over, and it is one transaction. */
    public function testConfirmingTheDispatchMovesEveryUnitAndRecordsTheMoment(): void
    {
        $units    = $this->stock(5);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);
        $this->picking->addUnits($dispatch['id'], array_column($units, 'id'));

        $sent = (new LogisticsService($this->db, service('requestContext')))->sendDispatch($dispatch['id'], [
            'transporter' => 'Blue Dart Surface', 'lr_number' => 'LR-99821',
        ]);

        $this->assertSame(5, $sent['item_count']);

        $row = $this->db->table('dispatches')->select('status, dispatched_at, transporter')
            ->where('id', $dispatch['id'])->get()->getRowArray();
        $this->assertSame('DISPATCHED', $row['status']);
        $this->assertNotNull($row['dispatched_at']);
        $this->assertSame('Blue Dart Surface', $row['transporter']);

        foreach ($units as $unit) {
            $this->assertSame('DISPATCHED', $this->statusOf($unit['id']));
        }
    }

    public function testTheHistoryShowsSentConsignmentsAndTheDraftListShowsTheRest(): void
    {
        $units = $this->stock(4);
        $sent  = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);
        $open  = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        $this->picking->addUnits($sent['id'], [$units[0]['id'], $units[1]['id']]);
        $this->picking->addUnits($open['id'], [$units[2]['id']]);
        (new LogisticsService($this->db, service('requestContext')))->sendDispatch($sent['id']);

        $this->assertContains($sent['dispatch_code'], array_column($this->picking->history(), 'dispatch_code'));
        $this->assertNotContains($sent['dispatch_code'], array_column($this->picking->openDrafts(), 'dispatch_code'));
        $this->assertContains($open['dispatch_code'], array_column($this->picking->openDrafts(), 'dispatch_code'));
    }

    public function testBatchesWithStockReportWhatIsActuallyLeftInThem(): void
    {
        $batch    = $this->warehouse->openBatch([
            'warehouse_id' => $this->warehouseId(), 'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => 6]]);
        $units    = $this->warehouse->batchUnits($batch['id']);
        $dispatch = $this->picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId]);

        $this->picking->addUnits($dispatch['id'], [$units[0]['id'], $units[1]['id']]);

        $rows  = $this->picking->batchesWithStock($this->warehouseId());
        $mine  = array_values(array_filter($rows, static fn ($r) => $r['id'] === $batch['id']));
        $this->assertSame(4, (int) $mine[0]['available']);
    }

    // =====================================================================

    /**
     * Free stock at the plant, ready to pick.
     *
     * @return list<array<string,mixed>>
     */
    private function stock(int $quantity): array
    {
        $batch = $this->warehouse->openBatch([
            'warehouse_id'    => $this->warehouseId(),
            'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => $quantity]]);

        return $this->warehouse->batchUnits($batch['id']);
    }

    private function statusOf(string $mattressId): string
    {
        return $this->db->table('mattresses')->select('current_status')
            ->where('id', $mattressId)->get()->getRow()->current_status;
    }
}

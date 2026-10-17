<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Services\WarehouseService;
use Tests\Support\PolyfixTestCase;

/**
 * The warehouse panel: opening a batch, and counting units into it.
 *
 * The count is the thing. A floor that cannot trust "87 of 100" will go back
 * to a clipboard, so every way the number could be wrong is tested here:
 * scanned twice, scanned by two people at once, scanned from another batch,
 * scanned after the unit has left, and a label that reads as nonsense.
 *
 * @internal
 */
final class WarehouseTest extends PolyfixTestCase
{
    private WarehouseService $warehouse;
    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->makeUser(['WAREHOUSE']));
        $this->warehouse = new WarehouseService($this->db, service('requestContext'));
        $this->variant   = $this->makeProduct()['variant'];
    }

    // =====================================================================
    // Opening a batch
    // =====================================================================

    /** One form, no typing: a hundred mattresses get a serial and a QR code. */
    public function testOpeningABatchIssuesASerialAndAQrCodeForEveryUnit(): void
    {
        $batch = $this->warehouse->openBatch([
            'warehouse_id'    => $this->warehouseId(),
            'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => 100]]);

        $this->assertSame(100, $batch['count']);
        $this->assertMatchesRegularExpression(
            '/^' . preg_quote(brand('serialPrefix'), '/') . '-\d{8}-\d{3}$/',
            $batch['batch_code'],
            'the batch code reads like a date and a run number',
        );

        $units = $this->warehouse->batchUnits($batch['id']);
        $this->assertCount(100, $units);
        $this->assertCount(100, array_unique(array_column($units, 'serial_number')), 'every serial is distinct');
        $this->assertCount(100, array_unique(array_column($units, 'qr_token')), 'every QR token is distinct');

        foreach ($units as $unit) {
            $this->assertSame('MANUFACTURED', $unit['current_status']);
            $this->assertNull($unit['scanned_at'], 'issuing a serial is not the same as confirming the mattress exists');
        }
    }

    public function testABatchCanHoldSeveralModelsAndSizes(): void
    {
        $second = $this->makeProduct()['variant'];

        $batch = $this->warehouse->openBatch([
            'warehouse_id'    => $this->warehouseId(),
            'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [
            ['product_variant_id' => $this->variant, 'quantity' => 30],
            ['product_variant_id' => $second, 'quantity' => 45],
        ]);

        $this->assertSame(75, $batch['count']);

        $byVariant = $this->db->table('mattresses')->select('product_variant_id, COUNT(*) n')
            ->where('batch_id', $batch['id'])->groupBy('product_variant_id')->get()->getResultArray();
        $counts = array_column($byVariant, 'n', 'product_variant_id');

        $this->assertSame(30, (int) $counts[$this->variant]);
        $this->assertSame(45, (int) $counts[$second]);
    }

    public function testAnEmptyOrFutureBatchIsRefused(): void
    {
        $this->expectException(AppException::class);
        $this->warehouse->openBatch([
            'warehouse_id'    => $this->warehouseId(),
            'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => 0]]);
    }

    public function testABatchDatedInTheFutureIsRefused(): void
    {
        $this->expectException(AppException::class);
        $this->warehouse->openBatch([
            'warehouse_id'    => $this->warehouseId(),
            'manufactured_on' => gmdate('Y-m-d', strtotime('+3 days')),
        ], [['product_variant_id' => $this->variant, 'quantity' => 5]]);
    }

    /** A failure must not leave a batch that exists but holds nothing. */
    public function testAFailedRunLeavesNoHalfBuiltBatch(): void
    {
        $before = $this->db->table('manufacturing_batches')->countAllResults();

        try {
            $this->warehouse->openBatch([
                'warehouse_id'    => $this->warehouseId(),
                'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
            ], [['product_variant_id' => uuid4(), 'quantity' => 10]]);   // a size that does not exist
            $this->fail('a batch of an unknown size should be refused');
        } catch (AppException) {
            // expected
        }

        $this->assertSame($before, $this->db->table('manufacturing_batches')->countAllResults(),
            'the batch was rolled back with the production run');
    }

    // =====================================================================
    // Counting units in
    // =====================================================================

    public function testAScanCountsTheUnitAndReportsWhatItIs(): void
    {
        $batch = $this->openBatchOf(3);
        $unit  = $this->warehouse->batchUnits($batch['id'])[0];

        $reply = $this->warehouse->productionScan($batch['id'], $unit['serial_number']);

        $this->assertTrue($reply['ok']);
        $this->assertSame('ACCEPTED', $reply['status']);
        $this->assertSame(1, $reply['counter']['done']);
        $this->assertSame(3, $reply['counter']['total']);
        $this->assertSame('1 / 3 Mattresses Scanned', $reply['counter']['label']);

        // The operator is told what they just scanned, off the record rather
        // than off the label.
        $this->assertArrayHasKey('detail', $reply);
        $this->assertSame($unit['size_label'], $reply['detail']['size']);
        $this->assertSame($batch['batch_code'], $reply['detail']['batch']);
    }

    /** The exact behaviour the floor asked for: a second scan adds nothing. */
    public function testScanningTheSameLabelTwiceCountsItOnce(): void
    {
        $batch = $this->openBatchOf(5);
        $unit  = $this->warehouse->batchUnits($batch['id'])[0];

        $first  = $this->warehouse->productionScan($batch['id'], $unit['serial_number']);
        $second = $this->warehouse->productionScan($batch['id'], $unit['serial_number']);

        $this->assertSame('ACCEPTED', $first['status']);
        $this->assertSame('DUPLICATE', $second['status']);
        $this->assertSame('Already Scanned — No Duplicate Added', $second['title']);
        $this->assertFalse($second['ok']);
        $this->assertSame(1, $second['counter']['done'], 'the count did not move');
    }

    /** Held in frame, scanned ten times: still one. */
    public function testRepeatedScansOfOneLabelNeverInflateTheCount(): void
    {
        $batch = $this->openBatchOf(4);
        $unit  = $this->warehouse->batchUnits($batch['id'])[0];

        for ($i = 0; $i < 10; $i++) {
            $this->warehouse->productionScan($batch['id'], $unit['serial_number']);
        }

        $this->assertSame(1, $this->warehouse->batchProgress($batch['id'])['scanned']);
        $this->assertSame(1, $this->db->table('warehouse_scans')
            ->where(['mattress_id' => $unit['id'], 'purpose' => 'PRODUCTION'])->countAllResults());
    }

    /**
     * The database, not the application, is what stops a double count. This
     * writes the row the way a second operator's request would, bypassing
     * every check in the service, and the unique key has to refuse it.
     *
     * Before the key was fixed this passed silently: MySQL treats NULLs in a
     * unique index as distinct, and a production scan always has a NULL
     * dispatch_id, so every row was unique to the index.
     */
    public function testTheDatabaseItselfRefusesASecondScanOfTheSameLabel(): void
    {
        $batch = $this->openBatchOf(2);
        $unit  = $this->warehouse->batchUnits($batch['id'])[0];
        $actor = service('requestContext')->userId();

        $this->warehouse->productionScan($batch['id'], $unit['serial_number']);

        $this->db->query(
            'INSERT INTO warehouse_scans (id, mattress_id, batch_id, dispatch_id, purpose, scanned_by, scanned_at, source)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [uuid4(), $unit['id'], $batch['id'], 'PRODUCTION', $actor, utc_now(), 'CAMERA'],
        );

        $this->assertSame(0, $this->db->affectedRows(), 'the unique key refused the duplicate row');
        $this->assertSame(1, $this->warehouse->batchProgress($batch['id'])['scanned']);
    }

    public function testEveryUnitScannedCompletesTheBatch(): void
    {
        $batch = $this->openBatchOf(6);

        foreach ($this->warehouse->batchUnits($batch['id']) as $unit) {
            $this->assertSame('ACCEPTED', $this->warehouse->productionScan($batch['id'], $unit['serial_number'])['status']);
        }

        $progress = $this->warehouse->batchProgress($batch['id']);
        $this->assertSame(6, $progress['scanned']);
        $this->assertSame(0, $progress['remaining']);
        $this->assertSame('6 / 6 Mattresses Scanned', $progress['counter']['label']);
    }

    public function testALabelFromAnotherBatchIsRefusedAndNamesItsOwnBatch(): void
    {
        $mine     = $this->openBatchOf(2);
        $theirs   = $this->openBatchOf(2);
        $stranger = $this->warehouse->batchUnits($theirs['id'])[0];

        $reply = $this->warehouse->productionScan($mine['id'], $stranger['serial_number']);

        $this->assertSame('REJECTED', $reply['status']);
        $this->assertStringContainsString($theirs['batch_code'], $reply['message']);
        $this->assertSame(0, $reply['counter']['done']);
        $this->assertSame(0, $this->warehouse->batchProgress($theirs['id'])['scanned'],
            'and it was not counted into its own batch either');
    }

    public function testAUnitThatHasLeftThePlantIsNotCountedBackIntoProduction(): void
    {
        $batch = $this->openBatchOf(2);
        $unit  = $this->warehouse->batchUnits($batch['id'])[0];

        $this->db->table('mattresses')->where('id', $unit['id'])
            ->update(['current_status' => 'IN_DISPATCH']);

        $reply = $this->warehouse->productionScan($batch['id'], $unit['serial_number']);

        $this->assertSame('REJECTED', $reply['status']);
        $this->assertStringContainsString('in dispatch', strtolower($reply['message']));
        $this->assertSame(0, $reply['counter']['done']);
    }

    public function testAnUnreadableLabelIsAnsweredPlainlyAndChangesNothing(): void
    {
        $batch = $this->openBatchOf(3);

        foreach (['', 'nonsense', 'PFZ-2026', 'https://example.com/'] as $rubbish) {
            $reply = $this->warehouse->productionScan($batch['id'], $rubbish);
            $this->assertSame('UNKNOWN', $reply['status'], "\"{$rubbish}\" must be reported as unrecognised");
            $this->assertFalse($reply['ok']);
        }

        // A well-formed serial that was never issued is the same answer.
        $this->assertSame('UNKNOWN', $this->warehouse->productionScan($batch['id'], 'PFZ-2026-999999')['status']);
        $this->assertSame(0, $this->warehouse->batchProgress($batch['id'])['scanned']);
    }

    /** The QR code on the label, not just the typed serial. */
    public function testScanningTheQrCodeOffTheLabelWorksTheSameWay(): void
    {
        $batch = $this->openBatchOf(2);
        $unit  = $this->warehouse->batchUnits($batch['id'])[0];

        $reply = $this->warehouse->productionScan($batch['id'], \App\Libraries\Labels::verifyUrl($unit['qr_token']));

        $this->assertSame('ACCEPTED', $reply['status']);
        $this->assertSame(1, $reply['counter']['done']);
    }

    /** Typing the serial records that it was typed, so the office can see it. */
    public function testTheScanRecordsWhetherItCameFromTheCameraOrTheKeyboard(): void
    {
        $batch = $this->openBatchOf(2);
        $units = $this->warehouse->batchUnits($batch['id']);

        $this->warehouse->productionScan($batch['id'], $units[0]['serial_number'], 'CAMERA');
        $this->warehouse->productionScan($batch['id'], $units[1]['serial_number'], 'MANUAL');

        $sources = array_column($this->db->table('warehouse_scans')->select('mattress_id, source')
            ->where('batch_id', $batch['id'])->get()->getResultArray(), 'source', 'mattress_id');

        $this->assertSame('CAMERA', $sources[$units[0]['id']]);
        $this->assertSame('MANUAL', $sources[$units[1]['id']]);
    }

    /** A confirmed unit carries the confirmation in its own history. */
    public function testAScanAppearsInTheUnitsHistory(): void
    {
        $batch = $this->openBatchOf(2);
        $unit  = $this->warehouse->batchUnits($batch['id'])[0];

        $this->warehouse->productionScan($batch['id'], $unit['serial_number']);

        $events = array_column($this->db->table('mattress_events')->select('event_type')
            ->where('mattress_id', $unit['id'])->get()->getResultArray(), 'event_type');

        $this->assertContains('MANUFACTURED', $events);
        $this->assertContains('PRODUCTION_SCANNED', $events);
    }

    // =====================================================================
    // Reading stock back
    // =====================================================================

    public function testFreeStockCountsOnlyWhatCanActuallyBeDispatched(): void
    {
        $batch = $this->openBatchOf(5);
        $units = $this->warehouse->batchUnits($batch['id']);

        // Two have gone: one staged on a dispatch, one sold at a dealer.
        $this->db->table('mattresses')->where('id', $units[0]['id'])->update(['current_status' => 'IN_DISPATCH']);
        $this->db->table('mattresses')->where('id', $units[1]['id'])->update([
            'current_status' => 'DEALER_RECEIVED', 'current_warehouse_id' => null,
            'current_dealer_id' => $this->makeDealer(), 'received_at' => utc_now(),
        ]);

        $rows = $this->warehouse->freeStock($this->warehouseId());
        $this->assertSame(3, array_sum(array_map(static fn ($r) => (int) $r['available'], $rows)));
    }

    public function testTheDashboardFiguresAreCountedFromTheRecords(): void
    {
        $batch = $this->openBatchOf(4);
        $units = $this->warehouse->batchUnits($batch['id']);
        $this->db->table('mattresses')->where('id', $units[0]['id'])->update(['current_status' => 'IN_DISPATCH']);

        $figures = $this->warehouse->dashboard();

        $this->assertSame(3, $figures['free_stock']);
        $this->assertSame(1, $figures['staged']);
        $this->assertGreaterThanOrEqual(1, $figures['open_batches'], 'a batch with unscanned units is still open');
    }

    /** Once every unit is counted, the batch is no longer work in hand. */
    public function testABatchStopsBeingOpenWhenEveryUnitHasBeenScanned(): void
    {
        $batch = $this->openBatchOf(2);
        $this->assertGreaterThanOrEqual(1, $this->warehouse->dashboard()['open_batches']);

        foreach ($this->warehouse->batchUnits($batch['id']) as $unit) {
            $this->warehouse->productionScan($batch['id'], $unit['serial_number']);
        }

        $open = array_filter($this->warehouse->batches(), static fn ($b) => $b['id'] === $batch['id']);
        $row  = array_values($open)[0];
        $this->assertSame((int) $row['produced'], (int) $row['scanned']);
    }

    public function testClosingABatchShortRecordsWhoAcceptedItAndWhy(): void
    {
        $batch = $this->openBatchOf(10);
        $unit  = $this->warehouse->batchUnits($batch['id'])[0];
        $this->warehouse->productionScan($batch['id'], $unit['serial_number']);

        $this->warehouse->closeBatch($batch['id'], 'Nine units failed inspection and were scrapped on the line.');

        $notes = $this->db->table('manufacturing_batches')->select('notes')->where('id', $batch['id'])->get()->getRow()->notes;
        $this->assertStringContainsString('Closed at 1 of 10 scanned', $notes);
        $this->assertStringContainsString('failed inspection', $notes);

        $audit = $this->db->table('audit_logs')->select('action')->where('action', 'BATCH_CLOSED_SHORT')->countAllResults();
        $this->assertSame(1, $audit, 'closing short is an auditable decision, not a note');
    }

    public function testClosingABatchWithoutAReasonIsRefused(): void
    {
        $batch = $this->openBatchOf(2);

        $this->expectException(AppException::class);
        $this->warehouse->closeBatch($batch['id'], '   ');
    }

    /** @return array{id:string, batch_code:string, count:int, first_serial:string, last_serial:string} */
    private function openBatchOf(int $quantity): array
    {
        return $this->warehouse->openBatch([
            'warehouse_id'    => $this->warehouseId(),
            'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => $quantity]]);
    }
}

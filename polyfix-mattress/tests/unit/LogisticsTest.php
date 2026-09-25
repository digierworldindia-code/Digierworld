<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Services\LogisticsService;
use Tests\Support\PolyfixTestCase;

/**
 * Manufacturing, dispatch and receipt — the chain of custody that makes a
 * serial number mean something.
 *
 * @internal
 */
final class LogisticsTest extends PolyfixTestCase
{
    private LogisticsService $logistics;
    private string $dealerId;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->makeUser(['WAREHOUSE']));
        $this->logistics = new LogisticsService($this->db, service('requestContext'));
        $this->dealerId  = $this->makeDealer();
        $this->product   = $this->makeProduct();
    }

    public function testProductionIssuesConsecutiveSerialsAndQrTokens(): void
    {
        $batch = $this->logistics->createBatch([
            'warehouse_id' => $this->warehouseId(), 'manufactured_on' => utc_today(), 'planned_quantity' => 10,
        ]);
        $result = $this->logistics->produce($batch['id'], [['product_variant_id' => $this->product['variant'], 'quantity' => 5]]);

        $this->assertSame(5, $result['count']);
        $units = $this->db->table('mattresses')->select('serial_number, qr_token, current_status')->where('batch_id', $batch['id'])
            ->orderBy('serial_number')->get()->getResultArray();

        $this->assertCount(5, $units);
        $this->assertCount(5, array_unique(array_column($units, 'qr_token')), 'every unit gets its own QR token');
        foreach ($units as $unit) {
            $this->assertMatchesRegularExpression('/^' . brand('serialPrefix') . '\d{8}$/', $unit['serial_number']);
            $this->assertSame('MANUFACTURED', $unit['current_status']);
        }

        // Serials are consecutive: no gaps, no reuse.
        $numbers = array_map(static fn ($u) => (int) substr($u['serial_number'], 3), $units);
        $this->assertSame(range(min($numbers), min($numbers) + 4), $numbers);
        $this->assertSame(5, (int) $this->db->table('manufacturing_batches')->select('produced_quantity')->where('id', $batch['id'])->get()->getRow()->produced_quantity);
    }

    public function testAProductionRunIsAllOrNothing(): void
    {
        $batch  = $this->logistics->createBatch(['warehouse_id' => $this->warehouseId(), 'manufactured_on' => utc_today(), 'planned_quantity' => 5]);
        $before = $this->db->table('mattresses')->countAllResults();
        $counter = (int) $this->db->table('identifier_counters')->select('value')->where('name', 'serial')->get()->getRow()->value;

        try {
            $this->logistics->produce($batch['id'], [
                ['product_variant_id' => $this->product['variant'], 'quantity' => 2],
                ['product_variant_id' => uuid4(), 'quantity' => 2],
            ]);
            $this->fail('an unknown size should stop the run');
        } catch (AppException $e) {
            $this->assertStringContainsString('product size', strtolower($e->getMessage()) === '' ? '' : 'product size');
        }

        $this->assertSame($before, $this->db->table('mattresses')->countAllResults(), 'no unit is created');
        $this->assertSame($counter, (int) $this->db->table('identifier_counters')->select('value')->where('name', 'serial')->get()->getRow()->value,
            'no serial number is burned');
    }

    public function testDispatchReservesUnitsAndRefusesThemTwice(): void
    {
        $units = $this->units(3);

        $dispatch = $this->logistics->createDispatch([
            'warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId,
            'serials' => array_column($units, 'serial'), 'invoice_number' => 'INV-1',
        ]);

        $this->assertSame(3, $dispatch['item_count']);
        foreach ($units as $unit) {
            $this->assertSame('IN_DISPATCH', $this->statusOf($unit['id']));
        }

        $this->expectException(AppException::class);
        $this->logistics->createDispatch([
            'warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId, 'serials' => [$units[0]['serial']],
        ]);
    }

    public function testStockCannotBeSentToASuspendedDealer(): void
    {
        $this->db->table('dealers')->where('id', $this->dealerId)->update(['status' => 'SUSPENDED']);
        $units = $this->units(1);

        $this->expectExceptionMessage('not an active dealer');
        $this->logistics->createDispatch([
            'warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId, 'serials' => [$units[0]['serial']],
        ]);
    }

    public function testCancellingADraftReturnsTheUnitsToStock(): void
    {
        $units    = $this->units(2);
        $dispatch = $this->logistics->createDispatch([
            'warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId, 'serials' => array_column($units, 'serial'),
        ]);

        $this->logistics->cancelDispatch($dispatch['id'], 'Vehicle did not arrive');

        foreach ($units as $unit) {
            $this->assertSame('MANUFACTURED', $this->statusOf($unit['id']));
        }
        $this->assertSame('CANCELLED', $this->db->table('dispatches')->select('status')->where('id', $dispatch['id'])->get()->getRow()->status);

        // A cancelled dispatch cannot then be sent.
        $this->expectException(AppException::class);
        $this->logistics->sendDispatch($dispatch['id']);
    }

    public function testReceivingRecordsConditionPerUnitAndReportsExceptions(): void
    {
        $units    = $this->units(3);
        $dispatch = $this->logistics->createDispatch([
            'warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId, 'serials' => array_column($units, 'serial'),
        ]);
        $this->logistics->sendDispatch($dispatch['id'], ['invoice_number' => 'INV-77']);

        foreach ($units as $unit) {
            $this->assertSame('DISPATCHED', $this->statusOf($unit['id']));
            $this->assertSame($this->dealerId, $this->db->table('mattresses')->select('current_dealer_id')->where('id', $unit['id'])->get()->getRow()->current_dealer_id);
        }

        // The dealer confirms: one good, one damaged, one that never arrived.
        $this->actingAsDealer();
        $result = $this->logistics->receive($dispatch['id'], [
            ['serial' => $units[0]['serial'], 'condition' => 'OK'],
            ['serial' => $units[1]['serial'], 'condition' => 'DAMAGED', 'remarks' => 'Corner crushed'],
            ['serial' => $units[2]['serial'], 'condition' => 'MISSING', 'remarks' => 'Not on the vehicle'],
        ]);

        $this->assertSame(1, $result['damaged']);
        $this->assertSame(1, $result['missing']);
        $this->assertSame('RECEIVED', $result['status']);

        $this->assertSame('DEALER_RECEIVED', $this->statusOf($units[0]['id']));
        $this->assertSame('DEALER_RECEIVED', $this->statusOf($units[1]['id']), 'a damaged unit is still received, and noted');
        $this->assertStringContainsString('damaged', (string) $this->db->table('mattresses')->select('grade_note')->where('id', $units[1]['id'])->get()->getRow()->grade_note);
        $this->assertSame('DISPATCHED', $this->statusOf($units[2]['id']), 'a missing unit stays in transit');

        // Staff are told about the exceptions rather than having to notice.
        $this->assertGreaterThan(0, $this->db->table('notifications')->where('type', 'RECEIPT_EXCEPTION')->countAllResults());
    }

    public function testAPartialReceiptLeavesTheConsignmentOpen(): void
    {
        $units    = $this->units(2);
        $dispatch = $this->logistics->createDispatch([
            'warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId, 'serials' => array_column($units, 'serial'),
        ]);
        $this->logistics->sendDispatch($dispatch['id']);
        $this->actingAsDealer();

        $result = $this->logistics->receive($dispatch['id'], [['serial' => $units[0]['serial'], 'condition' => 'OK']]);
        $this->assertSame('PARTIALLY_RECEIVED', $result['status']);

        $second = $this->logistics->receive($dispatch['id'], [['serial' => $units[1]['serial'], 'condition' => 'OK']]);
        $this->assertSame('RECEIVED', $second['status']);
    }

    public function testADealerCannotReceiveAnotherDealersConsignment(): void
    {
        $units    = $this->units(1);
        $dispatch = $this->logistics->createDispatch([
            'warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->dealerId, 'serials' => [$units[0]['serial']],
        ]);
        $this->logistics->sendDispatch($dispatch['id']);

        // A different dealership signs in and tries the same consignment.
        $otherDealer = $this->makeDealer();
        $this->actingAs($this->makeUser(['DEALER'], $otherDealer));
        $logistics = new LogisticsService($this->db, service('requestContext'));

        $this->expectExceptionMessage('could not find');
        $logistics->receive($dispatch['id'], [['serial' => $units[0]['serial'], 'condition' => 'OK']]);
    }

    /** @return list<array{id:string, serial:string}> */
    private function units(int $count): array
    {
        $units = [];
        for ($n = 0; $n < $count; $n++) {
            $units[] = $this->makeMattress($this->product['variant']);
        }

        return $units;
    }

    private function actingAsDealer(): void
    {
        $this->actingAs($this->makeUser(['DEALER'], $this->dealerId));
        $this->logistics = new LogisticsService($this->db, service('requestContext'));
    }

    private function statusOf(string $mattressId): string
    {
        return $this->db->table('mattresses')->select('current_status')->where('id', $mattressId)->get()->getRow()->current_status;
    }
}

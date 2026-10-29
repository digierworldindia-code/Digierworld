<?php

namespace Tests\Unit;

use App\Services\LogisticsService;
use App\Services\WarehouseService;
use Tests\Support\PolyfixTestCase;

/**
 * India time and UTC, and the seam between them.
 *
 * Everything is stored in UTC. The plant, the dealers and the agents all work
 * in India time, which is 5h30m ahead — so for the five and a half hours after
 * midnight local, the local calendar date is a day ahead of the UTC one. That
 * seam is where a real fault lived: a batch recorded the day it was made as a
 * local date, the mattresses on it took that date with ' 00:00:00' glued on
 * and stored it as UTC, and a batch opened during a night shift was therefore
 * stamped as manufactured tomorrow. The timeline CHECK then refused to let it
 * be dispatched at all.
 *
 * It passed every test for weeks, because the suite happened to run during the
 * Indian working day. These tests do not depend on the clock.
 *
 * @internal
 */
final class TimeZoneTest extends PolyfixTestCase
{
    /** The instant a named India-time day began, in UTC. */
    public function testALocalDayStartsFiveAndAHalfHoursBeforeTheSameUtcDate(): void
    {
        $this->assertSame('2026-10-29 18:30:00.000000', local_day_start_utc('2026-10-30'));
        $this->assertSame('2026-01-01 18:30:00.000000', local_day_start_utc('2026-01-02'));
        // India keeps one offset all year, so there is no summer-time case.
        $this->assertSame('2026-06-14 18:30:00.000000', local_day_start_utc('2026-06-15'));
    }

    /** It is the inverse of how a timestamp is shown. */
    public function testItRoundTripsWithTheWayTimesAreDisplayed(): void
    {
        foreach (['2026-01-01', '2026-06-15', '2026-10-30', '2026-12-31'] as $localDate) {
            $this->assertSame(
                $localDate,
                local_time(local_day_start_utc($localDate), 'Y-m-d'),
                'the start of a local day must display as that same local day',
            );
        }
    }

    /** And it is never in the future, which is what the CHECK cares about. */
    public function testTheStartOfTodayIsAlwaysInThePast(): void
    {
        $today = local_time(utc_now(), 'Y-m-d');

        $this->assertLessThan(
            utc_now(),
            local_day_start_utc($today),
            'the start of the local day cannot be later than now, at any hour',
        );
    }

    /**
     * The fault itself, with the clock pinned to the small hours in India.
     *
     * A batch is opened with today's local date — which is what the warehouse
     * form submits — and every unit on it has to be dispatchable immediately.
     */
    public function testABatchOpenedAfterLocalMidnightCanStillBeDispatched(): void
    {
        $variant   = $this->makeProduct()['variant'];
        $warehouse = new WarehouseService($this->db, service('requestContext'));

        // 00:30 in India is 19:00 UTC the day before: the hour at which the
        // local date and the UTC date disagree.
        $localToday = local_time('2026-10-29 19:00:00.000000', 'Y-m-d');
        $this->assertSame('2026-10-30', $localToday, 'the fixture must sit inside the seam');

        $batch = $warehouse->openBatch(
            ['warehouse_id' => $this->warehouseId(), 'manufactured_on' => $localToday],
            [['product_variant_id' => $variant, 'quantity' => 2]],
        );

        $units = $warehouse->batchUnits($batch['id']);
        $this->assertCount(2, $units);

        foreach ($units as $unit) {
            $made = $this->db->table('mattresses')->select('manufactured_at')
                ->where('id', $unit['id'])->get()->getRow()->manufactured_at;

            $this->assertSame(
                '2026-10-29',
                substr($made, 0, 10),
                'the unit must be stamped with the UTC instant the local day began, not with the local date read as UTC',
            );
            $this->assertSame($localToday, local_time($made, 'Y-m-d'), 'and it still reads as the day the plant worked');
        }
    }

    /**
     * The whole way through, which is what actually broke: made and sent out
     * in the same shift.
     */
    public function testAUnitCanBeDispatchedTheMomentItIsMade(): void
    {
        $variant   = $this->makeProduct()['variant'];
        $dealerId  = $this->makeDealer();
        $warehouse = new WarehouseService($this->db, service('requestContext'));

        $batch = $warehouse->openBatch(
            ['warehouse_id' => $this->warehouseId(), 'manufactured_on' => local_time(utc_now(), 'Y-m-d')],
            [['product_variant_id' => $variant, 'quantity' => 3]],
        );
        $units = $warehouse->batchUnits($batch['id']);

        $picking  = new \App\Services\DispatchPicking($this->db, service('requestContext'));
        $dispatch = $picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $dealerId]);
        $picking->addUnits($dispatch['id'], array_column($units, 'id'));

        // This is the step that raised "Check constraint
        // 'mattresses_timeline_chk' is violated" for any batch opened after
        // midnight India time.
        (new LogisticsService($this->db, service('requestContext')))->sendDispatch($dispatch['id']);

        foreach ($units as $unit) {
            $row = $this->db->table('mattresses')->select('current_status, manufactured_at, dispatched_at')
                ->where('id', $unit['id'])->get()->getRowArray();

            $this->assertSame('DISPATCHED', $row['current_status']);
            $this->assertNotNull($row['dispatched_at']);
            $this->assertGreaterThanOrEqual(
                substr($row['manufactured_at'], 0, 10),
                substr($row['dispatched_at'], 0, 10),
                'a unit cannot be dispatched before it was made',
            );
        }
    }
}

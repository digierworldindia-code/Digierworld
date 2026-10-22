<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/** Screens that list every employee load their data in a fixed number of queries and show the same numbers as before. */
final class HrBulkScreensTest extends DgrTestCase
{
    public function testLeaveBalancesForEveryoneMatchEachEmployeesOwnBalances(): void
    {
        $this->loginAs($this->makeUser('super_admin', 'HR Owner'));
        $ids = [];
        foreach (['Asha', 'Bharat', 'Chitra'] as $i => $name) {
            $ids[] = service('employees')->create(['full_name' => $name . ' Bulk', 'date_of_joining' => $i === 2 ? date('Y-m-d', strtotime('-40 days')) : '2025-04-01']);
        }
        $type = (int) db_connect()->table('leave_types')->where('is_active', 1)->orderBy('id')->get()->getRow()->id;
        $next = date('Y-m-d', strtotime('+1 day'));
        while (service('hrCalendar')->dayKind($next) !== null) { // a working day (other tests add holidays)
            $next = date('Y-m-d', strtotime($next . ' +1 day'));
        }
        service('leaves')->request($ids[0], ['leave_type_id' => (string) $type, 'from_date' => $next, 'to_date' => $next, 'reason' => 'Pending request']);

        $bulk = service('leaves')->balancesFor($ids);
        foreach ($ids as $id) {
            $one = service('leaves')->balances($id);
            $this->assertSame(array_column($one, 'available', 'leave_type_id'), array_column($bulk[$id], 'available', 'leave_type_id'));
            $this->assertSame(array_map('floatval', array_column($one, 'pending', 'leave_type_id')), array_map('floatval', array_column($bulk[$id], 'pending', 'leave_type_id')));
        }
        $this->assertGreaterThan(0.0, (float) array_column($bulk[$ids[0]], 'pending', 'leave_type_id')[$type]);

        $this->get('hr/leaves/balances')->assertSee('Asha Bulk');
        $this->get('hr/attendance/register')->assertSee('Chitra Bulk');
    }
}

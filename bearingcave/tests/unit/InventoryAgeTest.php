<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InventoryService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Inventory-age bucket boundaries (documented in InventoryService):
 *   < 6 months, 6–12 months inclusive, > 12 months.
 */
final class InventoryAgeTest extends CIUnitTestCase
{
    public function testBoundaries(): void
    {
        $today = '2026-10-09';
        $this->assertSame('lt_6m', InventoryService::ageBucket('2026-10-09', $today));
        $this->assertSame('lt_6m', InventoryService::ageBucket('2026-04-10', $today), 'one day under 6 months');
        $this->assertSame('6_12m', InventoryService::ageBucket('2026-04-09', $today), 'exactly 6 months is in 6–12');
        $this->assertSame('6_12m', InventoryService::ageBucket('2025-10-09', $today), 'exactly 12 months is in 6–12');
        $this->assertSame('gt_12m', InventoryService::ageBucket('2025-10-08', $today), 'one day over 12 months');
        $this->assertNull(InventoryService::ageBucket(null, $today));
    }
}

<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Libraries\DealerScope;
use Tests\Support\PolyfixTestCase;

/**
 * Dealer isolation.
 *
 * The previous platform relied on PostgreSQL row-level security. MySQL has no
 * equivalent, so the rule now lives in DealerScope and is enforced on every
 * query the portal makes — which is exactly why it is tested table by table.
 *
 * @internal
 */
final class DealerIsolationTest extends PolyfixTestCase
{
    private string $mine;
    private string $theirs;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mine    = $this->makeDealer();
        $this->theirs  = $this->makeDealer();
        $this->product = $this->makeProduct();
    }

    public function testEveryScopedTableIsCoveredByARule(): void
    {
        // If a table holding dealer data is added without a rule, apply() throws
        // rather than quietly returning everyone's rows.
        foreach (array_keys(DealerScope::COLUMN) as $table) {
            $builder = DealerScope::for($this->mine)->apply($this->db->table($table), $table);
            $this->assertStringContainsString($this->mine, $builder->getCompiledSelect(false), "{$table} is not filtered by dealer");
        }
    }

    public function testAnUnknownTableIsRefusedRatherThanLeftUnfiltered(): void
    {
        $this->expectException(\LogicException::class);
        DealerScope::for($this->mine)->apply($this->db->table('users'), 'users');
    }

    public function testAQueryOnlyEverReturnsTheDealersOwnRows(): void
    {
        $mineUnit   = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->mine);
        $theirsUnit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->theirs);

        $rows = DealerScope::for($this->mine)
            ->apply($this->db->table('mattresses m')->select('m.serial_number'), 'mattresses', 'm')
            ->where('m.deleted_at', null)->get()->getResultArray();

        $serials = array_column($rows, 'serial_number');
        $this->assertContains($mineUnit['serial'], $serials);
        $this->assertNotContains($theirsUnit['serial'], $serials);
    }

    public function testAnotherDealersRecordReadsAsMissing(): void
    {
        $theirsUnit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->theirs);
        $row        = $this->db->table('mattresses')->where('id', $theirsUnit['id'])->get()->getRowArray();

        try {
            DealerScope::for($this->mine)->assertOwns('mattresses', $row, 'mattress');
            $this->fail('another dealer\'s unit must not be accepted');
        } catch (AppException $e) {
            $this->assertSame(404, $e->status(), 'the answer is the same as for a record that does not exist');
            $this->assertStringContainsString('could not find', $e->getMessage());
            $this->assertStringNotContainsString($this->theirs, $e->getMessage(), 'nothing about the other dealer leaks');
        }
    }

    public function testAMissingRowAndAnotherDealersRowAreIndistinguishable(): void
    {
        $scope   = DealerScope::for($this->mine);
        $missing = null;
        $theirs  = null;

        try {
            $scope->assertOwns('sales', null, 'sale');
        } catch (AppException $e) {
            $missing = $e->getMessage();
        }

        try {
            $scope->assertOwns('sales', ['id' => uuid4(), 'dealer_id' => $this->theirs], 'sale');
        } catch (AppException $e) {
            $theirs = $e->getMessage();
        }

        $this->assertNotNull($missing);
        $this->assertSame($missing, $theirs);
    }

    public function testInternalClaimNotesAreScopedOutForDealers(): void
    {
        $claimId = $this->claimFor($this->mine);
        foreach ([[1, 'internal only'], [0, 'shared with the dealer']] as [$internal, $note]) {
            // claim_events uses an auto-increment id, like the audit tables.
            $this->insert('claim_events', [
                'claim_id' => $claimId, 'event_type' => 'REVIEW_NOTE', 'note' => $note,
                'is_internal' => $internal, 'created_at' => utc_now(),
            ]);
        }

        $visible = DealerScope::for($this->mine)->apply($this->db->table('claim_events e'), 'claim_events', 'e')
            ->where('e.claim_id', $claimId)->get()->getResultArray();

        $this->assertSame(['shared with the dealer'], array_column($visible, 'note'));
    }

    public function testDispatchItemsAreReachableOnlyThroughTheDealersOwnConsignments(): void
    {
        $theirDispatch = uuid4();
        $this->insert('dispatches', [
            'id' => $theirDispatch, 'dispatch_code' => 'DSP' . random_int(10_000_000, 99_999_999), 'warehouse_id' => $this->warehouseId(),
            'dealer_id' => $this->theirs, 'status' => 'DISPATCHED', 'dispatched_at' => utc_now(),
        ]);
        $unit = $this->makeMattress($this->product['variant'], 'DISPATCHED', $this->theirs);
        $this->insert('dispatch_items', ['id' => uuid4(), 'dispatch_id' => $theirDispatch, 'mattress_id' => $unit['id']]);

        $rows = DealerScope::for($this->mine)->apply($this->db->table('dispatch_items i'), 'dispatch_items', 'i')->get()->getResultArray();
        $this->assertSame([], $rows);

        $theirs = DealerScope::for($this->theirs)->apply($this->db->table('dispatch_items i'), 'dispatch_items', 'i')->get()->getResultArray();
        $this->assertCount(1, $theirs);
    }

    public function testScopeCannotBeAskedForOutsideADealerSession(): void
    {
        $this->actingAs($this->makeUser(['ADMIN']));

        $this->expectException(\LogicException::class);
        DealerScope::current();
    }

    private function claimFor(string $dealerId): string
    {
        $unit       = $this->makeMattress($this->product['variant'], 'CLAIM_OPEN', $dealerId, ['sold_at' => gmdate('Y-m-d H:i:s', strtotime('-60 days')) . '.000000']);
        $customerId = uuid4();
        $this->insert('customers', [
            'id' => $customerId, 'dealer_id' => $dealerId, 'full_name' => 'Test Customer',
            'phone_encrypted' => 'x', 'phone_hash' => hash('sha256', $customerId), 'city' => 'Jaipur',
        ]);
        $saleId = uuid4();
        $this->insert('sales', [
            'id' => $saleId, 'dealer_id' => $dealerId, 'mattress_id' => $unit['id'], 'customer_id' => $customerId,
            'invoice_number' => 'INV-' . substr($saleId, 0, 8), 'sold_at' => gmdate('Y-m-d H:i:s', strtotime('-60 days')) . '.000000',
            'sale_price' => 21500, 'payment_mode' => 'CASH', 'sold_by_user_id' => $this->makeUser(['DEALER'], $dealerId),
        ]);
        $warrantyId = uuid4();
        $this->insert('warranties', [
            'id' => $warrantyId, 'sale_id' => $saleId, 'mattress_id' => $unit['id'], 'dealer_id' => $dealerId, 'customer_id' => $customerId,
            'start_date' => gmdate('Y-m-d', strtotime('-60 days')), 'end_date' => gmdate('Y-m-d', strtotime('+10 years')),
            'years' => 10, 'status' => 'ACTIVE', 'terms_version' => 'v1.0',
        ]);
        $claimId = uuid4();
        $this->insert('warranty_claims', [
            'id' => $claimId, 'claim_number' => 'CLM' . random_int(10_000_000, 99_999_999), 'mattress_id' => $unit['id'],
            'warranty_id' => $warrantyId, 'dealer_id' => $dealerId, 'customer_id' => $customerId,
            'issue_category' => 'SAGGING', 'reported_issue' => 'Dip', 'description' => 'A description long enough for the fixture.',
            'status' => 'SUBMITTED', 'submitted_by_user_id' => $this->makeUser(['DEALER'], $dealerId), 'submitted_at' => utc_now(),
        ]);

        return $claimId;
    }
}

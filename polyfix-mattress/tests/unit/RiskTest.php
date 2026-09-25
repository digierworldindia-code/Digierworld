<?php

namespace Tests\Unit;

use App\Libraries\Crypto;
use App\Services\RiskService;
use Tests\Support\PolyfixTestCase;

/**
 * The claim risk indicators.
 *
 * Each signal is checked on its own so the evidence behind a score is
 * verifiable — a score nobody can explain is worse than no score.
 *
 * @internal
 */
final class RiskTest extends PolyfixTestCase
{
    private RiskService $risk;
    private string $dealerId;
    private string $dealerUserId;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->risk     = new RiskService($this->db);
        $this->dealerId     = $this->makeDealer();
        $this->product      = $this->makeProduct(10);
        $this->dealerUserId = $this->makeUser(['DEALER'], $this->dealerId);
        $this->actingAs($this->makeUser(['WARRANTY_MANAGER']));
    }

    public function testNothingUnusualScoresLow(): void
    {
        $claim      = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 0);
        $assessment = $this->risk->assess($claim);

        $this->assertSame([], $assessment['signals']);
        $this->assertSame(0, $assessment['score']);
        $this->assertSame('LOW', $assessment['level']);
    }

    public function testAClaimRaisedDaysAfterTheSaleIsFlagged(): void
    {
        $assessment = $this->risk->assess($this->claim(soldDaysAgo: 5, submittedDaysAgo: 0));

        $this->assertSame(['EARLY_CLAIM'], array_column($assessment['signals'], 'code'));
        $this->assertSame(25, $assessment['score']);
        $this->assertSame('MEDIUM', $assessment['level']);
        $this->assertSame(5, $assessment['signals'][0]['evidence']['daysSinceSale']);
    }

    public function testAClaimAfterTheWarrantyEndedIsFlagged(): void
    {
        $claimId = $this->claim(soldDaysAgo: 4000, submittedDaysAgo: 0, warrantyYears: 10);
        $codes   = array_column($this->risk->assess($claimId)['signals'], 'code');

        $this->assertContains('WARRANTY_EXPIRED', $codes);
    }

    public function testAClaimCloseToExpiryIsFlaggedMoreGently(): void
    {
        $claimId    = $this->claim(soldDaysAgo: 3600, submittedDaysAgo: 0, warrantyYears: 10);
        $assessment = $this->risk->assess($claimId);
        $codes      = array_column($assessment['signals'], 'code');

        $this->assertContains('WARRANTY_NEAR_EXPIRY', $codes);
        $this->assertNotContains('WARRANTY_EXPIRED', $codes);
    }

    public function testTheSameCustomerNumberOnSeveralClaimsIsFlagged(): void
    {
        $phoneHash = Crypto::instance()->blindIndex(Crypto::normalisePhone('9876500099'));
        $customer  = $this->customer(['phone_hash' => $phoneHash]);

        // Two earlier claims, then the one under assessment.
        $this->claim(soldDaysAgo: 400, submittedDaysAgo: 100, customerId: $customer);
        $this->claim(soldDaysAgo: 400, submittedDaysAgo: 50, customerId: $customer);
        $claimId = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 0, customerId: $customer);

        $signals = array_column($this->risk->assess($claimId)['signals'], 'weight', 'code');
        $this->assertArrayHasKey('REPEAT_CUSTOMER_CLAIMS', $signals);
        $this->assertSame(20, $signals['REPEAT_CUSTOMER_CLAIMS']);
    }

    public function testSeveralCustomersAtOneAddressAreFlagged(): void
    {
        $addressHash = hash('sha256', 'shared-address-for-the-test');
        foreach ([120, 60] as $daysAgo) {
            $this->claim(soldDaysAgo: 400, submittedDaysAgo: $daysAgo, customerId: $this->customer(['address_hash' => $addressHash]));
        }
        $claimId = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 0, customerId: $this->customer(['address_hash' => $addressHash]));

        $this->assertContains('REPEAT_ADDRESS', array_column($this->risk->assess($claimId)['signals'], 'code'));
    }

    public function testAPhotographReusedFromAnotherClaimIsFlagged(): void
    {
        $sha    = hash('sha256', 'the-same-photograph-bytes');
        $first  = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 30);
        $second = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 0);
        foreach ([$first, $second] as $claimId) {
            $this->insert('claim_media', [
                'id' => uuid4(), 'claim_id' => $claimId, 'dealer_id' => $this->dealerId, 'kind' => 'CLAIM_PHOTO',
                'storage_key' => 'claims/' . $claimId . '/' . uuid4() . '.png', 'mime_type' => 'image/png',
                'byte_size' => 1024, 'sha256' => $sha, 'uploaded_by_user_id' => $this->dealerUserId,
            ]);
        }

        $signals = array_column($this->risk->assess($second)['signals'], 'weight', 'code');
        $this->assertArrayHasKey('DUPLICATE_PHOTO', $signals);
        $this->assertSame(30, $signals['DUPLICATE_PHOTO']);
    }

    public function testDatesThatCannotHaveHappenedAreFlagged(): void
    {
        $claimId = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 0);
        $unit    = $this->db->table('warranty_claims')->select('mattress_id')->where('id', $claimId)->get()->getRow()->mattress_id;

        // Sold before the dealer received it: impossible, and the claim was
        // raised on a record that says so. (The table's own check rejects this
        // order, so the fixture writes the claim's view of it directly.)
        // The claim was raised before the unit was even sold, which cannot have
        // happened: the submission date is what the check does not police.
        $this->db->query('UPDATE warranty_claims SET submitted_at = ? WHERE id = ?', [
            gmdate('Y-m-d H:i:s', strtotime('-500 days')) . '.000000', $claimId,
        ]);

        $signals = array_column($this->risk->assess($claimId)['signals'], null, 'code');
        $this->assertArrayHasKey('TIMELINE_INCONSISTENT', $signals);
        $this->assertNotEmpty($signals['TIMELINE_INCONSISTENT']['evidence']['timelineProblems']);
    }

    public function testASameDaySaleDoesNotTripTheTimelineCheck(): void
    {
        $claimId = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 0);
        $unit    = $this->db->table('warranty_claims')->select('mattress_id')->where('id', $claimId)->get()->getRow()->mattress_id;
        $sold    = $this->db->table('mattresses')->select('sold_at')->where('id', $unit)->get()->getRow()->sold_at;

        // Received at 11am, invoiced the same day at midnight — normal.
        $this->db->table('mattresses')->where('id', $unit)->update(['received_at' => substr($sold, 0, 10) . ' 11:00:00.000000']);

        $this->assertNotContains('TIMELINE_INCONSISTENT', array_column($this->risk->assess($claimId)['signals'], 'code'));
    }

    public function testASecondClaimOnTheSameMattressIsFlagged(): void
    {
        $first  = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 60);
        $unitId = $this->db->table('warranty_claims')->select('mattress_id')->where('id', $first)->get()->getRow()->mattress_id;
        $second = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 0, mattressId: $unitId);

        $this->assertContains('REPEAT_CLAIM_SAME_UNIT', array_column($this->risk->assess($second)['signals'], 'code'));
    }

    public function testManyClaimsFromOneDealerInAMonthAreFlagged(): void
    {
        for ($n = 0; $n < 10; $n++) {
            $this->claim(soldDaysAgo: 400, submittedDaysAgo: 10);
        }
        $claimId = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 0);

        $codes = array_column($this->risk->assess($claimId)['signals'], 'code');
        $this->assertContains('DEALER_CLAIM_BURST', $codes);
    }

    public function testADealerClaimingOnManyOfTheirSalesIsFlagged(): void
    {
        // 25 sales, 10 claims: a ratio well past the threshold.
        for ($n = 0; $n < 25; $n++) {
            $this->sale();
        }
        for ($n = 0; $n < 9; $n++) {
            $this->claim(soldDaysAgo: 400, submittedDaysAgo: 200);
        }
        $claimId = $this->claim(soldDaysAgo: 400, submittedDaysAgo: 0);

        $signals = array_column($this->risk->assess($claimId)['signals'], 'weight', 'code');
        $this->assertArrayHasKey('DEALER_CLAIM_RATIO', $signals);
        $this->assertSame(20, $signals['DEALER_CLAIM_RATIO']);
    }

    public function testTheScoreIsCappedAndStoredWithItsEvidence(): void
    {
        $claimId    = $this->claim(soldDaysAgo: 5, submittedDaysAgo: 0, warrantyYears: 10);
        $assessment = $this->risk->refresh($claimId);

        $stored = $this->db->table('warranty_claims')->select('risk_score, risk_level, risk_signals, risk_computed_at')->where('id', $claimId)->get()->getRowArray();
        $this->assertSame($assessment['score'], (int) $stored['risk_score']);
        $this->assertSame($assessment['level'], $stored['risk_level']);
        $this->assertNotNull($stored['risk_computed_at']);
        $this->assertLessThanOrEqual(100, (int) $stored['risk_score']);
        $this->assertNotEmpty(json_decode((string) $stored['risk_signals'], true));
    }

    // =====================================================================
    // Fixtures written straight to the tables: the risk engine reads rows,
    // and building them directly is what lets each signal be isolated.
    // =====================================================================

    private function customer(array $overrides = []): string
    {
        $id = uuid4();
        // One customer per dealer per number, so each fixture gets its own
        // unless the test deliberately reuses one.
        $phone = '98' . random_int(10_000_000, 99_999_999);
        $this->insert('customers', $overrides + [
            'id' => $id, 'dealer_id' => $this->dealerId, 'full_name' => 'Test Customer',
            'phone_encrypted' => Crypto::instance()->encrypt($phone),
            'phone_hash' => Crypto::instance()->blindIndex($phone),
            'city' => 'Jaipur', 'state' => 'Rajasthan',
        ]);

        return $id;
    }

    private function sale(): string
    {
        $unit = $this->makeMattress($this->product['variant'], 'SOLD', $this->dealerId, [
            'sold_at' => gmdate('Y-m-d H:i:s', strtotime('-400 days')) . '.000000',
        ]);
        $saleId = uuid4();
        $this->insert('sales', [
            'id' => $saleId, 'dealer_id' => $this->dealerId, 'mattress_id' => $unit['id'], 'customer_id' => $this->customer(),
            'invoice_number' => 'INV-' . substr($saleId, 0, 8), 'sold_at' => gmdate('Y-m-d H:i:s', strtotime('-400 days')) . '.000000',
            'sale_price' => 21500, 'payment_mode' => 'CASH', 'sold_by_user_id' => $this->dealerUserId,
        ]);

        return $saleId;
    }

    private function claim(int $soldDaysAgo, int $submittedDaysAgo, int $warrantyYears = 10, ?string $customerId = null, ?string $mattressId = null): string
    {
        $customerId ??= $this->customer();
        $soldAt     = gmdate('Y-m-d H:i:s', strtotime("-{$soldDaysAgo} days")) . '.000000';

        $reusedUnit = $mattressId !== null;
        if (! $reusedUnit) {
            $unit       = $this->makeMattress($this->product['variant'], 'CLAIM_OPEN', $this->dealerId, ['sold_at' => $soldAt]);
            $mattressId = $unit['id'];
        }

        // A warranty always belongs to a sale, so the fixture records one —
        // except when a second claim is raised on a unit that already has one.
        $saleId = $reusedUnit
            ? $this->db->table('sales')->select('id')->where('mattress_id', $mattressId)->get()->getRow()->id
            : uuid4();
        if (! $reusedUnit) {
            $this->insert('sales', [
                'id' => $saleId, 'dealer_id' => $this->dealerId, 'mattress_id' => $mattressId, 'customer_id' => $customerId,
                'invoice_number' => 'INV-' . substr($saleId, 0, 8), 'sold_at' => $soldAt, 'sale_price' => 21500,
                'payment_mode' => 'CASH', 'sold_by_user_id' => $this->dealerUserId,
            ]);
        }

        // One warranty per mattress, so a second claim on the same unit uses it.
        $existing   = $this->db->table('warranties')->select('id')->where('mattress_id', $mattressId)->get()->getRow();
        $warrantyId = $existing->id ?? uuid4();
        if ($existing === null) {
            $this->insert('warranties', [
                'id' => $warrantyId, 'sale_id' => $saleId, 'mattress_id' => $mattressId, 'dealer_id' => $this->dealerId, 'customer_id' => $customerId,
                'start_date' => substr($soldAt, 0, 10), 'end_date' => gmdate('Y-m-d', strtotime("-{$soldDaysAgo} days +{$warrantyYears} years")),
                'years' => $warrantyYears, 'status' => 'ACTIVE', 'terms_version' => 'v1.0',
            ]);
        }

        $claimId = uuid4();
        $this->insert('warranty_claims', [
            'id' => $claimId, 'claim_number' => 'CLM' . random_int(10_000_000, 99_999_999), 'mattress_id' => $mattressId,
            'warranty_id' => $warrantyId, 'dealer_id' => $this->dealerId, 'customer_id' => $customerId,
            'issue_category' => 'SAGGING', 'reported_issue' => 'Dip on one side',
            'description' => 'A description long enough to be realistic for the test suite.',
            'status' => 'SUBMITTED', 'submitted_by_user_id' => $this->dealerUserId, 'submitted_at' => gmdate('Y-m-d H:i:s', strtotime("-{$submittedDaysAgo} days")) . '.000000',
        ]);

        return $claimId;
    }
}

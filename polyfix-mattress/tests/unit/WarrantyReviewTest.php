<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Services\SalesService;
use App\Services\SurveyService;
use App\Services\WarrantyReviewService;
use Tests\Support\PolyfixTestCase;

/**
 * Warranty rejection: a recommendation, and the decision on it.
 *
 * The thing being protected here is a customer's cover. An agent in a
 * showroom can see that the paperwork is wrong; they must not be able to end
 * ten years of warranty because of it. So the tests below are mostly about
 * what an agent *cannot* do, and about the warranty surviving until somebody
 * authorised says otherwise — with a way back if they were wrong.
 *
 * @internal
 */
final class WarrantyReviewTest extends PolyfixTestCase
{
    private WarrantyReviewService $review;
    private string $agentId;
    private string $managerId;
    private string $dealerId;
    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealerId  = $this->makeDealer();
        $this->agentId   = $this->makeUser(['FIELD_AGENT']);
        $this->managerId = $this->makeUser(['WARRANTY_MANAGER']);
        $this->variant   = $this->makeProduct()['variant'];

        $this->insert('agent_dealer_assignments', [
            'id' => uuid4(), 'agent_user_id' => $this->agentId, 'dealer_id' => $this->dealerId,
            'survey_interval_days' => SurveyService::DEFAULT_INTERVAL_DAYS, 'active' => 1, 'created_at' => utc_now(),
        ]);

        $this->actingAs($this->agentId);
        $this->review = new WarrantyReviewService($this->db, service('requestContext'));
    }

    // =====================================================================
    // Raising it
    // =====================================================================

    public function testAnAgentOpensACaseAndTheWarrantyIsUntouched(): void
    {
        $sale = $this->soldUnit();

        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED', 'No invoice on file for this unit.');

        $this->assertMatchesRegularExpression('/^WRV-\d{2}-\d{6}$/', $case['reference']);

        $row = $this->db->table('warranty_review_cases')->where('id', $case['id'])->get()->getRowArray();
        $this->assertSame('RECOMMENDED', $row['status'], 'an agent recommends; nobody has decided');
        $this->assertNull($row['decided_by']);
        $this->assertSame($this->agentId, $row['raised_by']);

        // The whole point: the customer still has their cover.
        $this->assertSame('ACTIVE', $this->warrantyStatus($sale['warranty_id']));
    }

    public function testTheRecommendationIsTheFirstLineOfTheHistory(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_MISMATCH', 'Invoice is for a different size.');

        $events = $this->review->events($case['id']);

        $this->assertCount(1, $events);
        $this->assertSame('RECOMMENDED', $events[0]['event']);
        $this->assertStringContainsString('different size', $events[0]['note']);
        $this->assertSame(1, (int) $events[0]['dealer_visible'], 'the dealer can see what was said about them');
    }

    public function testOtherNeedsAnExplanation(): void
    {
        $sale = $this->soldUnit();

        $this->expectException(AppException::class);
        $this->review->recommend($sale['mattress_id'], 'OTHER', '   ');
    }

    public function testAnUnknownReasonIsRefused(): void
    {
        $sale = $this->soldUnit();

        $this->expectException(AppException::class);
        $this->review->recommend($sale['mattress_id'], 'BECAUSE_I_SAY_SO');
    }

    public function testAnAgentCannotOpenACaseAtADealerTheyDoNotCover(): void
    {
        $elsewhere = $this->makeDealer();
        $sale      = $this->soldUnit($elsewhere);

        $this->expectException(AppException::class);
        $this->review->recommend($sale['mattress_id'], 'SALE_NOT_REGISTERED');
    }

    /** Two agents seeing the same problem add to one case, not two. */
    public function testASecondCaseOnTheSameMattressIsRefused(): void
    {
        $sale = $this->soldUnit();
        $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        try {
            $this->review->recommend($sale['mattress_id'], 'INVOICE_MISMATCH');
            $this->fail('a second open case should be refused');
        } catch (AppException $e) {
            $this->assertStringContainsString('already an open review', $e->getMessage());
        }

        $this->assertSame(1, $this->db->table('warranty_review_cases')->countAllResults());
    }

    /** Once one is settled, a new problem can be raised. */
    public function testANewCaseCanBeOpenedAfterTheLastOneWasSettled(): void
    {
        $sale  = $this->soldUnit();
        $first = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        $this->actingAs($this->managerId);
        $this->review->dismiss($first['id'], 'Invoice was on file after all.');

        $this->actingAs($this->agentId);
        $second = $this->review->recommend($sale['mattress_id'], 'INVOICE_MISMATCH', 'Now the details do not match.');

        $this->assertNotSame($first['id'], $second['id']);
    }

    public function testTheAgentWhoRaisedItCanWithdrawIt(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'SALE_NOT_REGISTERED');

        $this->review->withdraw($case['id'], 'Dealer produced the paperwork while I was there.');

        $this->assertSame('WITHDRAWN', $this->caseStatus($case['id']));
        $this->assertSame('ACTIVE', $this->warrantyStatus($sale['warranty_id']));
    }

    public function testAnotherAgentCannotWithdrawSomebodyElsesRecommendation(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'SALE_NOT_REGISTERED');

        $this->actingAs($this->makeUser(['FIELD_AGENT']));

        $this->expectException(AppException::class);
        $this->review->withdraw($case['id'], 'Not mine to withdraw.');
    }

    // =====================================================================
    // Deciding it
    // =====================================================================

    public function testUpholdingItVoidsTheWarrantyWithAReason(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'SALE_NOT_REGISTERED', 'No sale recorded for this unit.');

        $this->actingAs($this->managerId);
        $result = $this->review->uphold($case['id'], 'No sale and no invoice after two requests to the dealer.');

        $this->assertTrue($result['warranty_voided']);
        $this->assertSame('VOID', $this->warrantyStatus($sale['warranty_id']));

        $warranty = $this->db->table('warranties')->where('id', $sale['warranty_id'])->get()->getRowArray();
        $this->assertStringContainsString($case['reference'], $warranty['void_reason'],
            'the void points back at the case that caused it');

        $row = $this->db->table('warranty_review_cases')->where('id', $case['id'])->get()->getRowArray();
        $this->assertSame('UPHELD', $row['status']);
        $this->assertSame($this->managerId, $row['decided_by']);
        $this->assertNotNull($row['decided_at']);
        $this->assertNotNull($row['decision_reason']);
    }

    /** The database itself will not store a decision with no reason. */
    public function testAWarrantyIsNeverRejectedWithoutAReason(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        $this->actingAs($this->managerId);

        try {
            $this->review->uphold($case['id'], '  ');
            $this->fail('a rejection with no reason should be refused');
        } catch (AppException) {
            // expected
        }

        $this->assertSame('ACTIVE', $this->warrantyStatus($sale['warranty_id']));
    }

    public function testDismissingItLeavesTheWarrantyExactlyAsItWas(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        $this->actingAs($this->managerId);
        $this->review->dismiss($case['id'], 'Invoice was uploaded against the sale, not the claim.');

        $this->assertSame('DISMISSED', $this->caseStatus($case['id']));
        $this->assertSame('ACTIVE', $this->warrantyStatus($sale['warranty_id']));
    }

    public function testAskingTheDealerForTheInvoiceLeavesTheCaseWaiting(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        $this->actingAs($this->managerId);
        $this->review->requestInformation($case['id'], 'Please upload the customer invoice for this serial.');

        $row = $this->db->table('warranty_review_cases')->where('id', $case['id'])->get()->getRowArray();
        $this->assertSame('INFORMATION_REQUESTED', $row['status']);
        $this->assertStringContainsString('upload the customer invoice', $row['dealer_visible_note']);
        $this->assertSame('ACTIVE', $this->warrantyStatus($sale['warranty_id']), 'still nothing decided');
    }

    /**
     * And it tells the dealer so.
     *
     * The first run of the whole workflow on a clean install caught this: the
     * office asked for an invoice, the case sat in INFORMATION_REQUESTED, and
     * the dealer's portal raised no notice at all. They could find the case if
     * they went looking for it, which is not the same as being asked.
     */
    public function testAskingTheDealerForTheInvoiceTellsThem(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        $this->actingAs($this->managerId);
        $this->review->requestInformation($case['id'], 'Please upload the customer invoice for this serial.');

        $notice = $this->db->table('notifications')
            ->where(['dealer_id' => $this->dealerId, 'entity' => 'warranty_review_case', 'entity_id' => $case['id']])
            ->get()->getRowArray();

        $this->assertNotNull($notice, 'the dealer was never told they had been asked for anything');
        $this->assertSame('WARRANTY_REVIEW_INFORMATION_REQUESTED', $notice['type']);
        $this->assertStringContainsString($case['reference'], $notice['title']);
        $this->assertStringContainsString('upload the customer invoice', $notice['body']);
        $this->assertNull($notice['read_at'], 'it starts unread, so the dashboard counts it');
    }

    /** A decision is news the dealer needs, whichever way it goes. */
    public function testTheDealerIsToldWhenTheCaseIsDecided(): void
    {
        foreach (['uphold' => 'Invoice never produced.', 'dismiss' => 'Paperwork is in order.'] as $decision => $reason) {
            $sale = $this->soldUnit();
            $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

            $this->actingAs($this->managerId);
            $this->review->{$decision}($case['id'], $reason);

            $notice = $this->db->table('notifications')
                ->where(['dealer_id' => $this->dealerId, 'entity_id' => $case['id']])
                ->get()->getRowArray();

            $this->assertNotNull($notice, "the dealer was not told the case was {$decision}ed");
            $this->assertSame('WARRANTY_REVIEW_DECIDED', $notice['type']);
            $this->assertStringContainsString($reason, $notice['body']);

            // The next turn of the loop raises its own case; acting as the
            // agent again is what lets it.
            $this->actingAs($this->agentId);
            $this->review = new WarrantyReviewService($this->db, service('requestContext'));
        }
    }

    public function testADecidedCaseCannotBeDecidedAgain(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        $this->actingAs($this->managerId);
        $this->review->dismiss($case['id'], 'Paperwork is in order.');

        $this->expectException(AppException::class);
        $this->review->uphold($case['id'], 'Changed my mind.');
    }

    /**
     * Somebody has to be able to put it back. A customer whose cover was
     * removed in error must not need a database edit to get it returned.
     */
    public function testAWarrantyRejectedInErrorCanBeRestored(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'SALE_NOT_REGISTERED');

        $this->actingAs($this->managerId);
        $this->review->uphold($case['id'], 'No sale recorded.');
        $this->assertSame('VOID', $this->warrantyStatus($sale['warranty_id']));

        $result = $this->review->restore($case['id'], 'The sale was recorded under a different serial. Our error.');

        $this->assertTrue($result['warranty_restored']);
        $this->assertSame('ACTIVE', $this->warrantyStatus($sale['warranty_id']));
        $this->assertNull($this->db->table('warranties')->select('void_reason')
            ->where('id', $sale['warranty_id'])->get()->getRow()->void_reason);
        $this->assertSame('DISMISSED', $this->caseStatus($case['id']));
    }

    /**
     * Restoring cover puts it back as it was, not as new. A warranty whose
     * term has run out while the case was open comes back expired, because
     * an administrative error should not hand anyone extra years.
     */
    public function testRestoringAWarrantyWhoseTermHasRunOutBringsItBackExpired(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'SALE_NOT_REGISTERED');

        $this->actingAs($this->managerId);
        $this->review->uphold($case['id'], 'No sale recorded.');

        // Its term ended while the case was being argued about. The start
        // date moves with it: the database will not store a warranty that
        // ended before it began.
        $this->db->table('warranties')->where('id', $sale['warranty_id'])->update([
            'start_date' => date('Y-m-d', strtotime('-10 years')),
            'end_date'   => date('Y-m-d', strtotime('-1 day')),
        ]);

        $this->review->restore($case['id'], 'Our error, but the cover has run out since.');

        $this->assertSame('EXPIRED', $this->warrantyStatus($sale['warranty_id']));
    }

    public function testOnlyAnUpheldCaseCanBeRestored(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        $this->actingAs($this->managerId);

        $this->expectException(AppException::class);
        $this->review->restore($case['id'], 'Nothing to restore.');
    }

    // =====================================================================
    // What each side sees
    // =====================================================================

    /** A reviewer's internal note is not addressed to the dealer. */
    public function testTheDealerSeesTheDecisionsButNotTheInternalNotes(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_MISMATCH', 'Invoice is for another unit.');

        $this->actingAs($this->managerId);
        $this->review->comment($case['id'], 'Third case for this dealer this quarter — watch it.', false);
        $this->review->dismiss($case['id'], 'Dealer sent the right invoice.');

        $all          = $this->review->events($case['id']);
        $dealerCanSee = $this->review->events($case['id'], true);

        $this->assertCount(3, $all);
        $this->assertCount(2, $dealerCanSee);

        $notes = implode(' ', array_column($dealerCanSee, 'note'));
        $this->assertStringNotContainsString('watch it', $notes);
        $this->assertStringContainsString('right invoice', $notes);
    }

    public function testTheHistoryIsAppendOnly(): void
    {
        $sale = $this->soldUnit();
        $case = $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        $this->actingAs($this->managerId);
        $this->review->take($case['id']);
        $this->review->requestInformation($case['id'], 'Invoice please.');
        $this->review->uphold($case['id'], 'Nothing supplied after a fortnight.');

        $events = array_column($this->review->events($case['id']), 'event');

        $this->assertSame(['RECOMMENDED', 'TAKEN_UP', 'INFORMATION_REQUESTED', 'UPHELD'], $events,
            'every step is kept, in order');
    }

    public function testAnAgentSeesTheCasesTheyRaised(): void
    {
        $sale = $this->soldUnit();
        $this->review->recommend($sale['mattress_id'], 'INVOICE_NOT_UPLOADED');

        $mine = $this->review->cases(['raised_by' => $this->agentId]);

        $this->assertCount(1, $mine);
        $this->assertSame($sale['serial'], $mine[0]['serial_number']);
        $this->assertSame('RECOMMENDED', $mine[0]['status']);
    }

    public function testTheOfficeCanListEverythingStillOpen(): void
    {
        $first  = $this->soldUnit();
        $second = $this->soldUnit();

        $a = $this->review->recommend($first['mattress_id'], 'INVOICE_NOT_UPLOADED');
        $this->review->recommend($second['mattress_id'], 'SALE_NOT_REGISTERED');

        $this->actingAs($this->managerId);
        $this->review->dismiss($a['id'], 'Fine.');

        $this->assertCount(1, $this->review->cases(['open' => true]));
        $this->assertCount(2, $this->review->cases());
    }

    // =====================================================================

    /** @return array{mattress_id:string, warranty_id:string, serial:string} */
    private function soldUnit(?string $dealerId = null): array
    {
        $dealerId ??= $this->dealerId;

        $unit = $this->makeMattress($this->variant, 'DEALER_RECEIVED', $dealerId);

        // Sold through the real service, so the warranty is a real warranty.
        $dealerUser = $this->makeUser(['DEALER'], $dealerId);
        $this->actingAs($dealerUser);

        $sale = (new SalesService($this->db, service('requestContext'), $this->crypto()))->recordSale([
            'serial'         => $unit['serial'],
            'invoice_number' => 'INV-' . random_int(10_000, 99_999),
            'sold_on'        => utc_today(),
            'sale_price'     => 18_500,
            'payment_mode'   => 'UPI',
            'customer'       => [
                'full_name' => 'Test Customer', 'phone' => '98765' . random_int(10_000, 99_999),
                'city' => 'Jaipur', 'state' => 'Rajasthan',
            ],
        ]);

        $this->actingAs($this->agentId);
        $this->review = new WarrantyReviewService($this->db, service('requestContext'));

        return ['mattress_id' => $unit['id'], 'warranty_id' => $sale['warranty_id'], 'serial' => $unit['serial']];
    }

    private function warrantyStatus(string $warrantyId): string
    {
        return $this->db->table('warranties')->select('status')->where('id', $warrantyId)->get()->getRow()->status;
    }

    private function caseStatus(string $caseId): string
    {
        return $this->db->table('warranty_review_cases')->select('status')->where('id', $caseId)->get()->getRow()->status;
    }
}

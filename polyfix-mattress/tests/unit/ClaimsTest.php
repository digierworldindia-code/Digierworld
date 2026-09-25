<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Libraries\Crypto;
use App\Services\ClaimService;
use App\Services\SalesService;
use Tests\Support\PolyfixTestCase;

/**
 * The warranty claim workflow, from a dealer raising one to a replacement
 * being issued against it.
 *
 * @internal
 */
final class ClaimsTest extends PolyfixTestCase
{
    private ClaimService $claims;
    private string $dealerId;
    private string $dealerUserId;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dealerId     = $this->makeDealer();
        $this->product      = $this->makeProduct(10);
        $this->dealerUserId = $this->makeUser(['DEALER'], $this->dealerId);
        $this->asDealer();
    }

    private function asDealer(): void
    {
        $this->actingAs($this->dealerUserId);
        $this->claims = new ClaimService($this->db, service('requestContext'));
    }

    private function asStaff(string $role = 'WARRANTY_MANAGER'): void
    {
        $this->actingAs($this->makeUser([$role]));
        $this->claims = new ClaimService($this->db, service('requestContext'));
    }

    /** A sold unit, ready to be claimed on. */
    private function soldUnit(string $invoice = 'INV-CLAIM'): array
    {
        $unit  = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);
        $sales = new SalesService($this->db, service('requestContext'), Crypto::instance());
        $sales->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => $invoice, 'sold_on' => gmdate('Y-m-d', strtotime('-90 days')),
            'sale_price' => 21500, 'payment_mode' => 'CASH',
            'customer' => ['full_name' => 'Ritu Sharma', 'phone' => '9876500011', 'city' => 'Jaipur'],
        ]);

        return $unit;
    }

    private function submit(array $unit, array $overrides = []): array
    {
        return $this->claims->submit($overrides + [
            'serial' => $unit['serial'], 'issue_category' => 'SAGGING',
            'reported_issue' => 'Dip on the left side about 4 cm',
            'description' => 'The customer reports a visible dip after ten weeks of normal use on a slatted base.',
        ]);
    }

    public function testADealerRaisesAClaimAndTheUnitIsMarkedAsUnderClaim(): void
    {
        $unit  = $this->soldUnit();
        $claim = $this->submit($unit);

        $this->assertMatchesRegularExpression('/^CLM\d{8}$/', $claim['claim_number']);
        $row = $this->db->table('warranty_claims')->where('id', $claim['id'])->get()->getRowArray();
        $this->assertSame('SUBMITTED', $row['status']);
        $this->assertSame($this->dealerId, $row['dealer_id']);
        $this->assertSame('CLAIM_OPEN', $this->db->table('mattresses')->select('current_status')->where('id', $unit['id'])->get()->getRow()->current_status);
        $this->assertNotNull($row['risk_level'], 'risk indicators are computed on submission');
    }

    public function testAClaimCannotBeRaisedOnAnotherDealersUnit(): void
    {
        $otherDealer = $this->makeDealer();
        $unit        = $this->makeMattress($this->product['variant'], 'SOLD', $otherDealer);

        $this->expectExceptionMessage('could not find');
        $this->submit($unit);
    }

    public function testAClaimCannotBeRaisedOnAnUnsoldUnit(): void
    {
        $unit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);

        $this->expectException(AppException::class);
        $this->submit($unit);
    }

    public function testInternalNotesAreNeverVisibleToTheDealer(): void
    {
        $claim = $this->submit($this->soldUnit());

        $this->asStaff();
        $this->claims->addNote($claim['id'], 'Dealer has three claims this month — check before deciding.', true);
        $this->claims->addNote($claim['id'], 'We have asked the dealer for photographs of the label.', false);

        $dealerVisible = \App\Libraries\DealerScope::for($this->dealerId)
            ->apply($this->db->table('claim_events e'), 'claim_events', 'e')
            ->where('e.claim_id', $claim['id'])->get()->getResultArray();

        $notes = array_column($dealerVisible, 'note');
        $this->assertNotContains('Dealer has three claims this month — check before deciding.', $notes);
        $this->assertContains('We have asked the dealer for photographs of the label.', $notes);
    }

    public function testInformationRequestedAndAnsweredMovesTheClaimBack(): void
    {
        $claim = $this->submit($this->soldUnit());

        $this->asStaff();
        $this->claims->requestInformation($claim['id'], 'Please send a photograph of the law label.');
        $this->assertSame('INFO_REQUESTED', $this->statusOf($claim['id']));
        $this->assertGreaterThan(0, $this->db->table('notifications')->where(['dealer_id' => $this->dealerId, 'type' => 'CLAIM_INFO_REQUESTED'])->countAllResults());

        $this->asDealer();
        $this->claims->dealerReply($claim['id'], 'Photograph of the label attached.');
        $this->assertSame('UNDER_REVIEW', $this->statusOf($claim['id']));
    }

    public function testApprovalNeedsAReasonAndAResolution(): void
    {
        $claim = $this->submit($this->soldUnit());
        $this->asStaff();

        try {
            $this->claims->decide($claim['id'], 'APPROVED', 'ok');
            $this->fail('a two-character reason should not be accepted');
        } catch (AppException $e) {
            $this->assertStringContainsString('reason', strtolower($e->getMessage()));
        }

        try {
            $this->claims->decide($claim['id'], 'APPROVED', 'Sagging confirmed from the photographs supplied.');
            $this->fail('an approval needs to say how it will be resolved');
        } catch (AppException $e) {
            $this->assertStringContainsString('resolved', strtolower($e->getMessage()));
        }

        $this->claims->decide($claim['id'], 'APPROVED', 'Sagging confirmed from the photographs supplied.', 'REPLACEMENT');
        $row = $this->db->table('warranty_claims')->where('id', $claim['id'])->get()->getRowArray();
        $this->assertSame('APPROVED', $row['status']);
        $this->assertSame('REPLACEMENT', $row['resolution']);
        $this->assertNotNull($row['decision_at']);
    }

    public function testRejectionReturnsTheMattressToSold(): void
    {
        $unit  = $this->soldUnit();
        $claim = $this->submit($unit);

        $this->asStaff();
        $this->claims->decide($claim['id'], 'REJECTED', 'The damage is a burn, which the warranty does not cover.');

        $this->assertSame('REJECTED', $this->statusOf($claim['id']));
        $this->assertSame('SOLD', $this->db->table('mattresses')->select('current_status')->where('id', $unit['id'])->get()->getRow()->current_status);
    }

    public function testAReplacementCarriesTheRemainderOfTheOriginalTerm(): void
    {
        $unit  = $this->soldUnit();
        $claim = $this->submit($unit);
        $this->asStaff();
        $this->claims->decide($claim['id'], 'APPROVED', 'Sagging beyond tolerance confirmed.', 'REPLACEMENT');

        $replacementUnit = $this->makeMattress($this->product['variant'], 'MANUFACTURED');
        $originalEnd     = $this->db->table('warranties w')->select('w.end_date')->where('w.mattress_id', $unit['id'])->get()->getRow()->end_date;

        $result = $this->claims->issueReplacement($claim['id'], $replacementUnit['serial'], 'Same model, same size.');

        $this->assertSame($originalEnd, $result['warranty_end'], 'the replacement inherits the original end date');
        $this->assertSame('REPLACED', $this->statusOf($claim['id']));
        $this->assertSame('SUPERSEDED', $this->db->table('warranties')->select('status')->where('mattress_id', $unit['id'])->get()->getRow()->status);
        $this->assertSame('REPLACED', $this->db->table('mattresses')->select('current_status')->where('id', $unit['id'])->get()->getRow()->current_status);

        $replacement = $this->db->table('mattresses')->where('id', $replacementUnit['id'])->get()->getRowArray();
        $this->assertSame('SOLD', $replacement['current_status']);
        $this->assertSame(1, (int) $replacement['is_replacement']);
        $this->assertSame($this->dealerId, $replacement['current_dealer_id']);

        // The replacement sale is recorded at zero value, not as revenue.
        $sale = $this->db->table('sales')->where('mattress_id', $replacementUnit['id'])->get()->getRowArray();
        $this->assertSame('0.00', $sale['sale_price']);
        $this->assertStringStartsWith('REP-', $sale['invoice_number']);

        // The claim is resolved now, so a second replacement has nothing to act on.
        $this->expectExceptionMessage('only be issued against an approved claim');
        $this->claims->issueReplacement($claim['id'], $this->makeMattress($this->product['variant'])['serial']);
    }

    public function testAClosedClaimAcceptsNoFurtherChanges(): void
    {
        $claim = $this->submit($this->soldUnit());
        $this->asStaff();
        $this->claims->decide($claim['id'], 'REJECTED', 'Outside what the warranty covers.');
        $this->claims->close($claim['id'], 'Explained to the dealer by phone.');

        $this->assertSame('CLOSED', $this->statusOf($claim['id']));

        $this->asDealer();
        $this->expectException(AppException::class);
        $this->claims->dealerReply($claim['id'], 'Can we look at this again?');
    }

    public function testAnInvalidTransitionIsRefused(): void
    {
        $claim = $this->submit($this->soldUnit());
        $this->asStaff();

        // Straight from SUBMITTED to closed is not a path the workflow allows.
        $this->expectException(AppException::class);
        $this->claims->close($claim['id'], 'Trying to skip the decision.');
    }

    private function statusOf(string $claimId): string
    {
        return $this->db->table('warranty_claims')->select('status')->where('id', $claimId)->get()->getRow()->status;
    }
}

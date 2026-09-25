<?php

namespace Tests\Unit;

use App\Libraries\Crypto;
use App\Libraries\Labels;
use App\Services\SalesService;
use App\Services\VerificationService;
use Tests\Support\PolyfixTestCase;

/**
 * The public warranty check — the one path anonymous internet traffic has into
 * mattress data. What it must answer, and what it must never reveal.
 *
 * @internal
 */
final class VerificationTest extends PolyfixTestCase
{
    private VerificationService $verify;
    private string $dealerId;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verify   = new VerificationService($this->db);
        $this->dealerId = $this->makeDealer();
        $this->product  = $this->makeProduct(10);
    }

    public function testAnUnknownSerialIsNotFoundAndSaysNothingElse(): void
    {
        $result = $this->verify->verify(brand('serialPrefix') . '99999999', null);

        $this->assertFalse($result['found']);
        $this->assertFalse($result['genuine']);
        $this->assertArrayNotHasKey('product', $result);
        $this->assertArrayNotHasKey('serial', $result);
        $this->assertStringContainsString(brand('serialPrefix'), $result['note']);
    }

    public function testGarbageIsRejectedBeforeAnyQuery(): void
    {
        foreach (['', '   ', 'SELECT * FROM mattresses', '../../etc/passwd', 'CLF1', str_repeat('A', 300)] as $input) {
            $this->assertFalse($this->verify->verify($input, null)['found'], "rejected: {$input}");
            $this->assertFalse($this->verify->verify(null, $input)['found'], "rejected as a token: {$input}");
        }
    }

    public function testAManufacturedUnitIsGenuineButNotYetUnderWarranty(): void
    {
        $unit   = $this->makeMattress($this->product['variant']);
        $result = $this->verify->verify($unit['serial'], null);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['genuine']);
        $this->assertSame('NOT_ACTIVATED', $result['warranty']['status']);
        $this->assertStringContainsString('has not been activated', $result['note']);
    }

    public function testASoldUnitShowsItsWarrantyWindowAndNothingPrivate(): void
    {
        $unit = $this->sell();

        $result = $this->verify->verify($unit['serial'], null);

        $this->assertSame('ACTIVE', $result['warranty']['status']);
        $this->assertSame(10, $result['warranty']['years']);
        $this->assertGreaterThan(3000, $result['warranty']['days_remaining']);

        // Everything the answer is allowed to contain, and nothing else.
        $this->assertSame(['found', 'genuine', 'serial', 'product', 'manufactured_on', 'warranty', 'note'], array_keys($result));
        $this->assertSame(['name', 'slug', 'category', 'size', 'comfort'], array_keys($result['product']));

        $flat = json_encode($result);
        foreach (['Ritu', '9876500011', 'INV-', $this->dealerId, '21500'] as $private) {
            $this->assertStringNotContainsString($private, $flat, "the public answer must not carry {$private}");
        }
    }

    public function testAQrTokenFindsTheSameUnitAsItsSerial(): void
    {
        $unit = $this->makeMattress($this->product['variant']);

        $byToken = $this->verify->verify(null, $unit['qr_token']);
        $this->assertTrue($byToken['found']);
        $this->assertSame($unit['serial'], $byToken['serial']);

        // The printed label encodes exactly this address.
        $this->assertStringContainsString('/warranty/verify?q=' . $unit['qr_token'], Labels::verifyUrl($unit['qr_token']));
    }

    public function testAVoidedWarrantySaysSoWithoutExplainingWhy(): void
    {
        $unit = $this->sell();
        $this->db->table('warranties')->where('mattress_id', $unit['id'])->update(['status' => 'VOID', 'void_reason' => 'Sale reversed after a dispute']);

        $result = $this->verify->verify($unit['serial'], null);
        $this->assertSame('VOID', $result['warranty']['status']);
        $this->assertStringContainsString('no longer valid', $result['note']);
        $this->assertStringNotContainsString('dispute', $result['note'], 'the reason stays internal');
    }

    public function testAReplacedUnitPointsAtTheReplacement(): void
    {
        $unit = $this->sell();
        $this->db->table('warranties')->where('mattress_id', $unit['id'])->update(['status' => 'SUPERSEDED']);

        $result = $this->verify->verify($unit['serial'], null);
        $this->assertSame('SUPERSEDED', $result['warranty']['status']);
        $this->assertStringContainsString('replaced under warranty', $result['note']);
    }

    public function testAnExpiredWarrantyIsReportedAsExpiredEvenBeforeAnyNightlyJob(): void
    {
        $unit = $this->sell();
        // Still ACTIVE in the row, but the end date has passed.
        $this->db->table('warranties')->where('mattress_id', $unit['id'])->update([
            'start_date' => gmdate('Y-m-d', strtotime('-11 years')), 'end_date' => gmdate('Y-m-d', strtotime('-1 year')),
        ]);

        $result = $this->verify->verify($unit['serial'], null);
        $this->assertSame('EXPIRED', $result['warranty']['status']);
        $this->assertSame(0, $result['warranty']['days_remaining']);
    }

    public function testARemovedUnitIsNotFound(): void
    {
        $unit = $this->makeMattress($this->product['variant']);
        $this->db->table('mattresses')->where('id', $unit['id'])->update([
            'deleted_at' => utc_now(), 'delete_reason' => 'Serialised in error',
        ]);

        $this->assertFalse($this->verify->verify($unit['serial'], null)['found']);
    }

    public function testTheSerialIsMatchedWhateverCaseItIsTypedIn(): void
    {
        $unit = $this->makeMattress($this->product['variant']);

        $this->assertTrue($this->verify->verify(strtolower($unit['serial']), null)['found']);
        $this->assertTrue($this->verify->verify('  ' . $unit['serial'] . '  ', null)['found']);
    }

    private function sell(): array
    {
        $unit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);
        $this->actingAs($this->makeUser(['DEALER'], $this->dealerId));
        (new SalesService($this->db, service('requestContext'), Crypto::instance()))->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => 'INV-VERIFY', 'sold_on' => utc_today(),
            'sale_price' => 21500, 'payment_mode' => 'CASH',
            'customer' => ['full_name' => 'Ritu Sharma', 'phone' => '9876500011', 'city' => 'Jaipur'],
        ]);

        return $unit;
    }
}

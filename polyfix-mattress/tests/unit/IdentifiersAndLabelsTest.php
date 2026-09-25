<?php

namespace Tests\Unit;

use App\Libraries\Identifiers;
use App\Libraries\Labels;
use Tests\Support\PolyfixTestCase;

/**
 * Serial numbers, claim numbers and the QR label they end up on.
 *
 * @internal
 */
final class IdentifiersAndLabelsTest extends PolyfixTestCase
{
    private Identifiers $identifiers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identifiers = new Identifiers($this->db);
    }

    public function testSerialsFollowTheBrandFormatAndNeverRepeat(): void
    {
        $batch = $this->identifiers->serials(50);

        $this->assertCount(50, $batch);
        $this->assertCount(50, array_unique($batch), 'a serial is never issued twice');
        foreach ($batch as $serial) {
            $this->assertMatchesRegularExpression('/^' . brand('serialPrefix') . '\d{2}\d{6}$/', $serial);
        }

        $numbers = array_map(static fn ($s) => (int) substr($s, 5), $batch);
        $this->assertSame(range($numbers[0], $numbers[0] + 49), $numbers, 'they run consecutively');
    }

    public function testSerialsCarryTheCurrentYear(): void
    {
        $serial = $this->identifiers->serials(1)[0];
        $this->assertSame(gmdate('y'), substr($serial, 3, 2));
    }

    public function testTheOtherIdentifiersHaveTheirOwnSequences(): void
    {
        $this->assertMatchesRegularExpression('/^BAT\d{6}$/', $this->identifiers->batchCode());
        $this->assertMatchesRegularExpression('/^CLM\d{8}$/', $this->identifiers->claimNumber());
        $this->assertMatchesRegularExpression('/^DSP\d{8}$/', $this->identifiers->dispatchCode());
        $this->assertMatchesRegularExpression('/^DLR\d{4}$/', $this->identifiers->dealerCode());

        // Taking a serial does not move the claim sequence.
        $before = $this->identifiers->claimNumber();
        $this->identifiers->serials(3);
        $after = $this->identifiers->claimNumber();
        $this->assertSame((int) substr($before, 5) + 1, (int) substr($after, 5));
    }

    public function testAQrTokenIsLongAndUnguessable(): void
    {
        $tokens = [];
        for ($n = 0; $n < 200; $n++) {
            $token = Identifiers::qrToken();
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{30,}$/', $token);
            $tokens[] = $token;
        }

        $this->assertCount(200, array_unique($tokens));
    }

    public function testAQrCodeEncodesTheVerificationAddressAndNotTheSerial(): void
    {
        $product = $this->makeProduct();
        $unit    = $this->makeMattress($product['variant']);

        $url = Labels::verifyUrl($unit['qr_token']);
        $this->assertStringContainsString('/warranty/verify?q=' . $unit['qr_token'], $url);
        $this->assertStringNotContainsString($unit['serial'], $url, 'a printed label must not reveal the serial sequence');

        $svg = Labels::qrSvg($unit['qr_token']);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString($unit['serial'], $svg);
    }

    public function testTheBarcodeCarriesTheSerialForAWarehouseScanner(): void
    {
        $svg = Labels::barcodeSvg(brand('serialPrefix') . '26000001');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
    }
}

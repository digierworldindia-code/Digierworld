<?php

namespace Tests\Unit;

use App\Libraries\Identifiers;
use App\Libraries\Labels;
use App\Libraries\ScanCode;
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
            $this->assertMatchesRegularExpression('/^' . brand('serialPrefix') . '-\d{4}-\d{6}$/', $serial);
        }

        // PFZ-2026-000001 -> the sequence is everything after the last hyphen.
        $numbers = array_map(static fn ($s) => (int) substr($s, strrpos($s, '-') + 1), $batch);
        $this->assertSame(range($numbers[0], $numbers[0] + 49), $numbers, 'they run consecutively');
    }

    public function testSerialsCarryTheCurrentYear(): void
    {
        $serial = $this->identifiers->serials(1)[0];
        $this->assertSame(local_time(utc_now(), 'Y'), explode('-', $serial)[1]);
    }

    public function testTheOtherIdentifiersHaveTheirOwnSequences(): void
    {
        $this->assertMatchesRegularExpression(
            '/^' . preg_quote(brand('serialPrefix'), '/') . '-' . local_time(utc_now(), 'Ymd') . '-\d{3}$/',
            $this->identifiers->batchCode(),
        );
        $this->assertMatchesRegularExpression('/^CLM\d{8}$/', $this->identifiers->claimNumber());
        $this->assertMatchesRegularExpression('/^DSP\d{8}$/', $this->identifiers->dispatchCode());
        $this->assertMatchesRegularExpression('/^DLR\d{4}$/', $this->identifiers->dealerCode());

        // Taking a serial does not move the claim sequence.
        $before = $this->identifiers->claimNumber();
        $this->identifiers->serials(3);
        $after = $this->identifiers->claimNumber();
        // CLM26000001: three-letter prefix, two-digit year, then the sequence.
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
        $svg = Labels::barcodeSvg(brand('serialPrefix') . '-2026-000001');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
    }

    /**
     * Every form that takes a typed serial number has to accept the serials
     * this release issues.
     *
     * The validation rule kept its own pattern, and the pattern was the one
     * from before the numbering changed: `PFZ-2026-000001` failed it. The
     * forms for recording a sale and for raising a warranty claim both use
     * that rule, so a dealer could not sell or claim on any mattress the
     * plant had made since the rename — while the scanner, which asks
     * ScanCode, accepted the same serial perfectly well.
     */
    public function testTheFormRuleAcceptsASerialThisReleaseIssues(): void
    {
        $rules = new \App\Validation\PolyfixRules();
        $error = null;

        $issued = (new Identifiers($this->db))->serial();

        $this->assertTrue(
            $rules->serial_number($issued, $error),
            "the serial just issued ({$issued}) is refused by the form rule: {$error}",
        );
    }

    /** And the serials already printed on labels in the field. */
    public function testTheFormRuleStillAcceptsALegacySerial(): void
    {
        $rules = new \App\Validation\PolyfixRules();

        foreach (['CLF26000001', 'clf26000001', ' CLF26000001 '] as $legacy) {
            $this->assertTrue($rules->serial_number($legacy), "{$legacy} must still be accepted");
        }
    }

    /** It is the same judgement the scanner makes, not a second opinion. */
    public function testTheFormRuleAndTheScannerAgreeOnWhatASerialIs(): void
    {
        $rules = new \App\Validation\PolyfixRules();

        foreach ([
            'PFZ-2026-000001', 'PFZ-2026-123456', 'CLF26000001', 'CLF000000012345',
            '', '   ', 'PFZ-2026', 'PFZ-26-1', 'not-a-serial', 'PFZ2026000001X',
            '<script>alert(1)</script>',
        ] as $candidate) {
            $this->assertSame(
                ScanCode::isSerial($candidate),
                $rules->serial_number($candidate),
                "the form rule and the scanner disagree about \"{$candidate}\"",
            );
        }
    }
}

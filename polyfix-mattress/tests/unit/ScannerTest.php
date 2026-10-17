<?php

namespace Tests\Unit;

use App\Libraries\Labels;
use App\Libraries\ScanCode;
use Tests\Support\PolyfixTestCase;

/**
 * Reading a label.
 *
 * Everything the camera and the typed-serial box can produce has to land on
 * the right mattress, and anything else has to be refused cleanly rather
 * than matched to the nearest unit.
 *
 * @internal
 */
final class ScannerTest extends PolyfixTestCase
{
    public function testTheQrCodeOnAPrintedLabelResolvesToItsMattress(): void
    {
        $variant  = $this->makeProduct()['variant'];
        $mattress = $this->makeMattress($variant);

        // Exactly what the QR code on the label encodes.
        $scanned = Labels::verifyUrl($mattress['qr_token']);

        $found = ScanCode::find($this->db, $scanned);
        $this->assertNotNull($found, 'the label URL must resolve');
        $this->assertSame($mattress['id'], $found['id']);
        $this->assertSame($mattress['serial'], $found['serial_number']);
    }

    public function testABareTokenAndTheSerialBothResolveToTheSameUnit(): void
    {
        $variant  = $this->makeProduct()['variant'];
        $mattress = $this->makeMattress($variant);

        foreach ([$mattress['qr_token'], $mattress['serial']] as $scanned) {
            $found = ScanCode::find($this->db, $scanned);
            $this->assertNotNull($found, "{$scanned} must resolve");
            $this->assertSame($mattress['id'], $found['id']);
        }
    }

    /** Typing is the fallback, and people type the way people type. */
    public function testATypedSerialIsForgivenItsCaseAndSpacing(): void
    {
        $variant  = $this->makeProduct()['variant'];
        $mattress = $this->makeMattress($variant);
        $serial   = $mattress['serial'];

        foreach ([strtolower($serial), ' ' . $serial . ' ', str_replace('-', '- ', $serial)] as $typed) {
            $found = ScanCode::find($this->db, $typed);
            $this->assertNotNull($found, "\"{$typed}\" must resolve to the same unit");
            $this->assertSame($mattress['id'], $found['id']);
        }
    }

    /**
     * Labels printed under the previous numbering are on mattresses in
     * customers' homes. An agent checking one must not be told it is not ours.
     */
    public function testALegacySerialStillParses(): void
    {
        $parsed = ScanCode::parse('clf00012345');
        $this->assertSame(['kind' => 'serial', 'value' => 'CLF00012345'], $parsed);
    }

    /**
     * The label's address is whatever was printed on it. A unit labelled
     * before the site moved carries the old host, and the token on it is
     * still this unit's token.
     */
    public function testTheHostInAScannedUrlIsNotWhatIdentifiesTheUnit(): void
    {
        $variant  = $this->makeProduct()['variant'];
        $mattress = $this->makeMattress($variant);

        $found = ScanCode::find($this->db, 'https://old-address.example/warranty/verify?q=' . $mattress['qr_token']);
        $this->assertNotNull($found);
        $this->assertSame($mattress['id'], $found['id']);
    }

    /** Anything that is not one of ours is refused, not approximated. */
    public function testRubbishIsRefusedRatherThanGuessedAt(): void
    {
        foreach ([
            '',
            '   ',
            'hello world',
            'PFZ-2026',                            // a serial, truncated
            'https://example.com/',                // a URL with no q
            'https://example.com/?q=',             // a URL with an empty q
            'https://example.com/?q=not a token',  // a q that cannot be a token
            '<script>alert(1)</script>',
        ] as $rubbish) {
            $this->assertNull(ScanCode::parse($rubbish), "\"{$rubbish}\" must not parse as a label");
            $this->assertNull(ScanCode::find($this->db, $rubbish), "\"{$rubbish}\" must not find a mattress");
        }
    }

    /** A well-formed serial that was never issued finds nothing. */
    public function testAWellFormedButUnknownSerialFindsNothing(): void
    {
        $this->assertNotNull(ScanCode::parse('PFZ-2026-999999'));
        $this->assertNull(ScanCode::find($this->db, 'PFZ-2026-999999'));
    }

    /** A deleted record is not stock, so a scan must not count it. */
    public function testADeletedUnitDoesNotResolve(): void
    {
        $variant  = $this->makeProduct()['variant'];
        $mattress = $this->makeMattress($variant);

        $this->db->table('mattresses')->where('id', $mattress['id'])
            ->update(['deleted_at' => utc_now(), 'delete_reason' => 'test']);

        $this->assertNull(ScanCode::find($this->db, $mattress['serial']));
    }

    /**
     * The browser scanner is a file, not an inline block, because the
     * Content-Security-Policy allows scripts from this origin only. If it
     * ever moves inline the camera stops working in production and nowhere
     * else, which is the worst way to find out.
     */
    public function testTheScannerIsServedAsAFileAndCarriesNoInlineScript(): void
    {
        $this->assertFileExists(FCPATH . 'assets/js/qr-scanner.js');
        $this->assertFileExists(FCPATH . 'assets/css/floor.css');
        $this->assertFileExists(FCPATH . 'assets/vendor/jsqr/jsQR.js', 'the fallback decoder is vendored, not fetched from a CDN');
        $this->assertFileExists(FCPATH . 'assets/vendor/jsqr/LICENSE');

        $partial = file_get_contents(APPPATH . 'Views/partials/scanner.php');
        $this->assertStringNotContainsString('<script', $partial, 'no inline script in the scanner markup');
        $this->assertStringNotContainsString('onclick', $partial, 'no inline handlers either');

        $layout = file_get_contents(APPPATH . 'Views/layouts/floor.php');
        $this->assertStringContainsString('assets/js/qr-scanner.js', $layout);
    }

    /** Nothing the scanner loads may come from somewhere else. */
    public function testTheScannerLoadsNothingFromAThirdParty(): void
    {
        $script = file_get_contents(FCPATH . 'assets/js/qr-scanner.js');

        $this->assertSame(
            0,
            preg_match('~https?://(?!www\.w3\.org)~', $script),
            'the scanner must not reference an external origin',
        );
    }
}

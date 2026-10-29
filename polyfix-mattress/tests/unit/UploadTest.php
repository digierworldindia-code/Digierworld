<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Services\MediaService;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Polyfix;
use Tests\Support\FakeUpload;
use Tests\Support\PolyfixTestCase;

/**
 * Uploads.
 *
 * A file is judged by its own bytes, not by what the browser said it was: the
 * name, the declared type and the extension are all attacker-controlled.
 *
 * @internal
 */
final class UploadTest extends PolyfixTestCase
{
    private MediaService $media;
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->media   = new MediaService(config(Polyfix::class));
        $this->scratch = WRITEPATH . 'cache/upload-tests-' . uniqid();
        mkdir($this->scratch, 0770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->scratch)) {
            rmdir($this->scratch);
        }
        parent::tearDown();
    }

    /** A real one-pixel PNG. */
    private function png(): string
    {
        $image = imagecreatetruecolor(120, 120);
        $path  = $this->scratch . '/photo.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function file(string $path, string $name, string $declaredType): UploadedFile
    {
        return new FakeUpload($path, $name, $declaredType, filesize($path), UPLOAD_ERR_OK);
    }

    public function testAGenuinePhotographIsAccepted(): void
    {
        $inspection = $this->media->inspect($this->file($this->png(), 'damage.png', 'image/png'));

        $this->assertSame('image/png', $inspection['mime']);
        $this->assertSame('png', $inspection['extension']);
        $this->assertSame('CLAIM_PHOTO', $inspection['kind']);
        $this->assertSame(hash_file('sha256', $this->png()), $inspection['sha256']);
    }

    /** A short MP4: a box length, then 'ftyp', then a brand that is not QuickTime. */
    private function mp4(): string
    {
        $path = $this->scratch . '/damage.mp4';
        file_put_contents($path, "\x00\x00\x00\x18ftypisom" . str_repeat("\x00", 2048));

        return $path;
    }

    private function pdf(): string
    {
        $path = $this->scratch . '/invoice.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return $path;
    }

    /**
     * A dealer photographs a sagging mattress with their phone, which records
     * video. The claim forms accept MP4 and MOV, videos have their own size
     * limit, and the mime CHECK on the table allows both — so this has to work.
     */
    public function testAVideoOfTheProblemIsAccepted(): void
    {
        $inspection = $this->media->inspect($this->file($this->mp4(), 'damage.mp4', 'video/mp4'));

        $this->assertSame('video/mp4', $inspection['mime']);
        $this->assertSame('mp4', $inspection['extension']);
        $this->assertSame('CLAIM_VIDEO', $inspection['kind']);
    }

    /**
     * And the column has to accept the value the service produces for it.
     * These drifted apart once: a migration rewrote claim_media.kind to add
     * REVIEW_INVOICE and dropped CLAIM_VIDEO out of the list while the service
     * went on writing it, so every video upload failed at the database with
     * the file already on disk. Nothing caught it, because no test had ever
     * uploaded a video.
     */
    public function testEveryKindTheServiceProducesIsAValueTheColumnAccepts(): void
    {
        $allowed = $this->db->query(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'claim_media' AND COLUMN_NAME = 'kind'",
        )->getRow()->t;

        foreach ([
            [$this->png(), 'damage.png', 'image/png'],
            [$this->mp4(), 'damage.mp4', 'video/mp4'],
            [$this->pdf(), 'invoice.pdf', 'application/pdf'],
        ] as [$path, $name, $type]) {
            $kind = $this->media->inspect($this->file($path, $name, $type))['kind'];
            $this->assertStringContainsString(
                "'{$kind}'",
                $allowed,
                "the service files a {$type} as {$kind}, which claim_media.kind does not allow: {$allowed}",
            );
        }
    }

    /** Both video formats the forms offer are recognised. */
    public function testAQuickTimeVideoIsRecognisedAsOne(): void
    {
        $path = $this->scratch . '/damage.mov';
        file_put_contents($path, "\x00\x00\x00\x14ftypqt  " . str_repeat("\x00", 2048));

        $inspection = $this->media->inspect($this->file($path, 'damage.mov', 'video/quicktime'));

        $this->assertSame('video/quicktime', $inspection['mime']);
        $this->assertSame('CLAIM_VIDEO', $inspection['kind']);
    }

    public function testAScriptRenamedAsAPhotographIsRefused(): void
    {
        $path = $this->scratch . '/evil.png';
        file_put_contents($path, "<?php system(\$_GET['c']); ?>");

        $this->expectException(AppException::class);
        $this->media->inspect($this->file($path, 'evil.png', 'image/png'));
    }

    public function testAPhotographWithALyingExtensionIsRefused(): void
    {
        // Real PNG bytes, but the browser calls it a PDF.
        $this->expectException(AppException::class);
        $this->media->inspect($this->file($this->png(), 'invoice.pdf', 'application/pdf'));
    }

    public function testAnOversizeFileIsRefused(): void
    {
        // A real PNG, padded past the limit: the size check reads the file on
        // disk rather than the size the browser claimed.
        $path  = $this->scratch . '/huge.png';
        copy($this->png(), $path);
        file_put_contents($path, str_repeat("\0", config(Polyfix::class)->maxUploadBytes + 1024), FILE_APPEND);

        $this->expectExceptionMessageMatches('/larger than \d+ MB/i');
        $this->media->inspect($this->file($path, 'huge.png', 'image/png'));
    }

    public function testATinyImageIsRefusedAsUnreadable(): void
    {
        $image = imagecreatetruecolor(10, 10);
        $path  = $this->scratch . '/tiny.png';
        imagepng($image, $path);
        imagedestroy($image);

        $this->expectException(AppException::class);
        $this->media->inspect($this->file($path, 'tiny.png', 'image/png'));
    }

    public function testStorageKeysStayInsideTheUploadDirectory(): void
    {
        $claimId = uuid4();
        $key     = 'claims/' . $claimId . '/' . uuid4() . '.png';
        $path    = $this->media->absolute($key);

        $this->assertStringStartsWith(config(Polyfix::class)->uploadDirectory(), $path);

        foreach (['../../../etc/passwd', 'claims/../../secret.png', '/etc/passwd', 'claims/x/y.php'] as $attempt) {
            try {
                $this->media->absolute($attempt);
                $this->fail("a key that escapes the upload directory must be refused: {$attempt}");
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testUploadsAreStoredOutsideTheWebRoot(): void
    {
        $directory = config(Polyfix::class)->uploadDirectory();

        $this->assertStringStartsWith(WRITEPATH, $directory);
        $this->assertStringNotContainsString(FCPATH, $directory, 'nothing uploaded may be reachable by URL');
    }
}

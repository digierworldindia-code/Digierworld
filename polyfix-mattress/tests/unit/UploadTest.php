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

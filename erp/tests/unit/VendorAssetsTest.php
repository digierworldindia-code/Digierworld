<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Third-party frontend files are self-hosted, unmodified copies of pinned
 * official npm releases. Their SHA-384 hashes were recorded from the
 * registry tarballs (whose sha512 integrity matched the registry) in
 * public/assets/vendor/MANIFEST.json.
 */
final class VendorAssetsTest extends CIUnitTestCase
{
    public function testEveryVendorFileIsPinnedListedAndUnchanged(): void
    {
        $dir = FCPATH . 'assets/vendor/';
        $man = json_decode((string) file_get_contents($dir . 'MANIFEST.json'), true, 512, JSON_THROW_ON_ERROR);
        $listed = [];
        foreach ($man['packages'] as $name => $pkg) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $pkg['version'], "{$name} version must be exact");
            $this->assertStringStartsWith('https://registry.npmjs.org/' . $name . '/-/', $pkg['tarball'], "{$name} comes from the official npm registry");
            $this->assertStringStartsWith('sha512-', $pkg['tarballIntegrity']);
            foreach ($pkg['files'] as $file => $meta) {
                $this->assertFileExists($dir . $file);
                $hash = 'sha384-' . base64_encode(hash_file('sha384', $dir . $file, true));
                $this->assertSame($meta['sha384'], $hash, "{$file} differs from the official {$name}@{$pkg['version']} file");
                $listed[] = $file;
            }
        }
        $onDisk = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir)));
            if ($rel !== 'MANIFEST.json' && ! preg_match('#(^|/)LICENSE(\.md)?$#', $rel)) {
                $onDisk[] = $rel;
            }
        }
        sort($listed);
        sort($onDisk);
        $this->assertSame($listed, $onDisk, 'Only manifest-listed vendor files may be shipped');
    }
}

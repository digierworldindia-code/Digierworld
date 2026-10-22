<?php

namespace Tests\Feature;

use App\Services\Admin\BackupService;
use Tests\Support\DgrTestCase;

/** A backup is only trusted after it has been read back completely, table by table. */
final class BackupVerifyTest extends DgrTestCase
{
    public function testBackupIsVerifiedAndADamagedFileIsRejected(): void
    {
        $this->setupCompany('08');
        $this->makeContact(['name' => "O'Brien & Sons \\ Ltd", 'notes' => "line one\nline two"]); // quotes, backslash and newline survive
        $service = new BackupService(db_connect());
        [$name]  = $service->create(false, 50);
        try {
            $check = $service->verify($name);
            $this->assertGreaterThan(100, $check['tables']);
            $this->assertSame((int) db_connect()->table('contacts')->countAllResults() > 0, $check['rows'] > 0);

            // cut the file in half: verification must fail
            $path = BackupService::dir() . $name;
            $raw  = gzdecode((string) file_get_contents($path));
            file_put_contents($path, gzencode(substr($raw, 0, (int) (strlen($raw) / 2))));
            $this->expectException(\RuntimeException::class);
            $service->verify($name);
        } finally {
            if (is_file(BackupService::dir() . $name)) {
                unlink(BackupService::dir() . $name);
            }
        }
    }
}

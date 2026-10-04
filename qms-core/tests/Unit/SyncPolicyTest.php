<?php

namespace Tests\Unit;

use App\Services\Sync\SheetSyncWorker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
final class SyncPolicyTest extends TestCase
{
    public function testBackoffDoublesUpToSixHours(): void
    {
        $expected = [1 => 60, 2 => 120, 3 => 240, 4 => 480, 9 => 15360, 10 => 21600, 12 => 21600];
        foreach ($expected as $attempt => $seconds) {
            $this->assertSame($seconds, SheetSyncWorker::backoffSeconds($attempt, 0.0), "attempt {$attempt}");
        }
        // ±20 % jitter
        $this->assertSame(48, SheetSyncWorker::backoffSeconds(1, -0.2));
        $this->assertSame(72, SheetSyncWorker::backoffSeconds(1, 0.2));
    }

    public function testErrorsNeverContainCredentials(): void
    {
        // Fake credentials, assembled at runtime so no secret-like literal sits in the repository.
        $token = 'ya' . '29.a0AfH6SMBx-secret_token.part';
        $pem   = '-----BEGIN ' . 'PRIVATE KEY-----' . "\nMIIE\n" . '-----END ' . 'PRIVATE KEY-----';
        $e     = new RuntimeException("Request failed: Authorization: Bearer {$token} {\"access_token\":\"{$token}\",\"private_key\":\"{$pem}\"}", 401);
        $clean = SheetSyncWorker::sanitize($e);

        $this->assertStringNotContainsString('ya29', $clean);
        $this->assertStringNotContainsString('MIIE', $clean);
        $this->assertStringNotContainsString('secret_token', $clean);
        $this->assertStringStartsWith('HTTP 401', $clean);
    }

    public function testGoogleJsonErrorsAreSummarised(): void
    {
        $e = new RuntimeException(json_encode(['error' => ['code' => 403, 'message' => 'The caller does not have permission', 'status' => 'PERMISSION_DENIED']]), 403);
        $this->assertSame('403 PERMISSION_DENIED: The caller does not have permission', SheetSyncWorker::sanitize($e));
    }
}

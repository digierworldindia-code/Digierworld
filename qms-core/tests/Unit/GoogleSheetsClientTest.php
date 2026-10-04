<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\Google;
use App\Libraries\GoogleSheets\GoogleSheetsClient;
use App\Services\Sync\SheetSyncWorker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The cURL Sheets client against a simulated Google: signed service-account
 * login (RS256 JWT), request URLs / bodies, token reuse and error handling.
 *
 * @internal
 */
final class GoogleSheetsClientTest extends TestCase
{
    private string $keyFile;

    private string $publicKey;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->keyFile   = (string) tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($this->keyFile, json_encode([
            'type' => 'service_account', 'client_email' => 'writer@test-project.iam.gserviceaccount.com',
            'private_key_id' => 'kid-1', 'private_key' => $privatePem, 'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
    }

    /**
     * @param list<array{0: int, 1: string}> $responses
     */
    private function client(array $responses): object
    {
        $config                  = new Google();
        $config->credentialsFile = $this->keyFile;

        return new class ($config, $responses) extends GoogleSheetsClient {
            /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
            public array $requests = [];

            /** @param list<array{0: int, 1: string}> $responses */
            public function __construct(Google $config, private array $responses)
            {
                parent::__construct($config);
            }

            protected function http(string $method, string $url, array $headers, ?string $body): array
            {
                $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

                return array_shift($this->responses) ?? [500, ''];
            }
        };
    }

    private static function b64(string $part): string
    {
        return (string) base64_decode(strtr($part, '-_', '+/'), true);
    }

    public function testSignedLoginAndReadRequests(): void
    {
        $client = $this->client([
            [200, json_encode(['access_token' => 'ya29.test-token', 'expires_in' => 3599])],
            [200, json_encode(['properties' => ['title' => 'QMS Reports']])],
            [200, json_encode(['values' => [['Key', 'SCA-1|R0', 'SCA-2|R0']]])],
        ]);

        $this->assertSame('QMS Reports', $client->spreadsheetTitle('1AbcDEF_ghijklmnopqrstuvwxyz'));
        $this->assertSame(['Key', 'SCA-1|R0', 'SCA-2|R0'], $client->readKeyColumn('1AbcDEF_ghijklmnopqrstuvwxyz', "Plant's Reports"));

        [$token, $title, $column] = $client->requests;
        $this->assertSame('https://oauth2.googleapis.com/token', $token['url']);
        parse_str((string) $token['body'], $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);

        [$header, $claims, $signature] = explode('.', $form['assertion']);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-1'], json_decode(self::b64($header), true));
        $payload = json_decode(self::b64($claims), true);
        $this->assertSame('writer@test-project.iam.gserviceaccount.com', $payload['iss']);
        $this->assertSame('https://www.googleapis.com/auth/spreadsheets', $payload['scope']);
        $this->assertSame(3600, $payload['exp'] - $payload['iat']);
        $this->assertSame(1, openssl_verify($header . '.' . $claims, self::b64($signature), $this->publicKey, OPENSSL_ALGO_SHA256), 'JWT signature');

        $this->assertSame('GET', $title['method']);
        $this->assertSame('https://sheets.googleapis.com/v4/spreadsheets/1AbcDEF_ghijklmnopqrstuvwxyz?fields=properties.title', $title['url']);
        $this->assertContains('Authorization: Bearer ya29.test-token', $title['headers']);
        $this->assertSame("https://sheets.googleapis.com/v4/spreadsheets/1AbcDEF_ghijklmnopqrstuvwxyz/values/'Plant''s%20Reports'%21A%3AA?majorDimension=COLUMNS",
            str_replace(rawurlencode("'Plant''s Reports'!A:A"), "'Plant''s%20Reports'%21A%3AA", $column['url']));
        $this->assertCount(3, $client->requests, 'the access token is reused');
    }

    public function testWritesUseRawValuesAndReturnRanges(): void
    {
        $client = $this->client([
            [200, json_encode(['access_token' => 'ya29.t', 'expires_in' => 3599])],
            [200, json_encode(['updatedRange' => "'Reports'!A5:AD5"])],
            [200, json_encode(['updates' => ['updatedRange' => "'Reports'!A6:AD7"]])],
        ]);
        $id = '1AbcDEF_ghijklmnopqrstuvwxyz';

        $this->assertSame("'Reports'!A5:AD5", $client->updateRow($id, 'Reports', 5, ['SCA-1|R0', null, '=HYPERLINK("x")', 12.5]));
        $this->assertSame("'Reports'!A6:AD7", $client->appendRows($id, 'Reports', [['a', 1], ['b', null]]));

        [, $update, $append] = $client->requests;
        $this->assertSame('PUT', $update['method']);
        $this->assertStringEndsWith('?valueInputOption=RAW', $update['url']);
        $this->assertSame(['values' => [['SCA-1|R0', '', '=HYPERLINK("x")', 12.5]]], json_decode((string) $update['body'], true));
        $this->assertSame('POST', $append['method']);
        $this->assertStringEndsWith(':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', $append['url']);
        $this->assertSame(['values' => [['a', 1], ['b', '']]], json_decode((string) $append['body'], true));
    }

    public function testGoogleErrorsAreReportedWithoutSecrets(): void
    {
        $client = $this->client([
            [200, json_encode(['access_token' => 'ya29.secret-token', 'expires_in' => 3599])],
            [403, json_encode(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission']])],
        ]);

        try {
            $client->sheets('1AbcDEF_ghijklmnopqrstuvwxyz');
            $this->fail('An HTTP 403 must throw.');
        } catch (RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('403 PERMISSION_DENIED: The caller does not have permission', SheetSyncWorker::sanitize($e));
        }

        $rejected = $this->client([[400, json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'])]]);
        try {
            $rejected->spreadsheetTitle('1AbcDEF_ghijklmnopqrstuvwxyz');
            $this->fail('A refused login must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('invalid_grant Invalid JWT Signature.', $e->getMessage());
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }
    }

    public function testInvalidSpreadsheetIdIsRefusedBeforeAnyRequest(): void
    {
        $client = $this->client([[200, json_encode(['access_token' => 'x', 'expires_in' => 3599])]]);
        $this->expectException(RuntimeException::class);
        $client->sheets('../../evil?x=1');
    }
}

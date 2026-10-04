<?php

declare(strict_types=1);

namespace App\Libraries\GoogleSheets;

use App\Config\Google as GoogleConfig;
use RuntimeException;

/**
 * Google Sheets API v4 through a service account, using only PHP's cURL and
 * OpenSSL extensions (no SDK).
 *
 * - Credentials: only the PATH comes from configuration (GOOGLE_CREDENTIALS_FILE);
 *   the key file lives outside the web root, readable by the PHP user only.
 * - Authentication: a JWT signed with the service account's private key (RS256)
 *   is exchanged for a one-hour access token, kept in memory only.
 * - Scope: spreadsheets only. The account can open only sheets shared with it.
 * - Every write uses valueInputOption=RAW: text such as "=HYPERLINK(...)" is stored
 *   as text, never evaluated as a formula.
 * - TLS certificates are always verified.
 */
class GoogleSheetsClient implements SheetsClientInterface
{
    private const API   = 'https://sheets.googleapis.com/v4/spreadsheets/';
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    private ?string $token = null;

    private int $tokenExpires = 0;

    public function __construct(private readonly GoogleConfig $config)
    {
    }

    public function describeCredentials(): array
    {
        $file = $this->config->credentialsFile;
        if ($file === '') {
            return ['configured' => false, 'email' => null,
                'hint' => 'Set GOOGLE_CREDENTIALS_FILE in the server .env to the path of the service-account key (outside the web root).'];
        }
        if (! is_file($file) || ! is_readable($file)) {
            return ['configured' => false, 'email' => null,
                'hint' => 'The key file is missing or not readable by the web server user. Check the path and permissions (0440, group of the PHP user).'];
        }
        $json = $this->key();
        if ($json === null) {
            return ['configured' => false, 'email' => null, 'hint' => 'The key file is not a Google service-account JSON key.'];
        }
        if (! function_exists('curl_init') || ! function_exists('openssl_sign')) {
            return ['configured' => false, 'email' => (string) $json['client_email'], 'hint' => 'The PHP extensions curl and openssl are required for Google Sheets.'];
        }

        return ['configured' => true, 'email' => (string) $json['client_email'],
            'hint' => 'Share the spreadsheet (and the detail spreadsheet, if used) with this e-mail as Editor. Do not share it with anyone else as Editor.'];
    }

    public function spreadsheetTitle(string $spreadsheetId): string
    {
        $data = $this->call('GET', $this->id($spreadsheetId) . '?fields=' . rawurlencode('properties.title'));

        return (string) ($data['properties']['title'] ?? '');
    }

    public function sheets(string $spreadsheetId): array
    {
        $data = $this->call('GET', $this->id($spreadsheetId) . '?fields=' . rawurlencode('sheets.properties'));
        $out  = [];
        foreach ($data['sheets'] ?? [] as $sheet) {
            $props = $sheet['properties'] ?? [];
            $grid  = $props['gridProperties'] ?? [];
            $out[(string) ($props['title'] ?? '')] = [
                'sheetId' => (int) ($props['sheetId'] ?? 0),
                'rows'    => (int) ($grid['rowCount'] ?? 0),
                'cols'    => (int) ($grid['columnCount'] ?? 0),
            ];
        }

        return $out;
    }

    public function addSheet(string $spreadsheetId, string $title, array $headers): void
    {
        $response = $this->call('POST', $this->id($spreadsheetId) . ':batchUpdate', ['requests' => [[
            'addSheet' => ['properties' => [
                'title'          => $title,
                'gridProperties' => ['frozenRowCount' => 1, 'columnCount' => max(26, count($headers)), 'rowCount' => 1000],
            ]],
        ]]]);
        $sheetId = (int) ($response['replies'][0]['addSheet']['properties']['sheetId'] ?? 0);

        $this->call('PUT', $this->id($spreadsheetId) . '/values/' . rawurlencode($this->a1($title, 'A1')) . '?valueInputOption=RAW', [
            'values' => [array_values($headers)],
        ]);

        $email = $this->describeCredentials()['email'];
        if ($email !== null) {
            $this->call('POST', $this->id($spreadsheetId) . ':batchUpdate', ['requests' => [[
                'addProtectedRange' => ['protectedRange' => [
                    'range'       => ['sheetId' => $sheetId],
                    'description' => 'Managed by QMS - edit in a separate tab',
                    'warningOnly' => false,
                    'editors'     => ['users' => [$email]],
                ]],
            ]]]);
        }
    }

    public function readKeyColumn(string $spreadsheetId, string $sheet): array
    {
        $data = $this->call('GET', $this->id($spreadsheetId) . '/values/' . rawurlencode($this->a1($sheet, 'A:A')) . '?majorDimension=COLUMNS');

        return array_map('strval', $data['values'][0] ?? []);
    }

    public function updateRow(string $spreadsheetId, string $sheet, int $row, array $values): string
    {
        $data = $this->call('PUT', $this->id($spreadsheetId) . '/values/' . rawurlencode($this->a1($sheet, 'A' . $row)) . '?valueInputOption=RAW', [
            'values' => [$this->clean($values)],
        ]);

        return (string) ($data['updatedRange'] ?? '');
    }

    public function appendRows(string $spreadsheetId, string $sheet, array $rows): string
    {
        $data = $this->call('POST', $this->id($spreadsheetId) . '/values/' . rawurlencode($this->a1($sheet, 'A1')) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', [
            'values' => array_map(fn (array $r): array => $this->clean($r), $rows),
        ]);

        return (string) ($data['updates']['updatedRange'] ?? '');
    }

    // ------------------------------------------------------------------ HTTP

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->accessToken(), 'Accept: application/json'];
        $payload = null;
        if ($body !== null) {
            $payload   = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        [$status, $response] = $this->http($method, self::API . $path, $headers, $payload);
        if ($status >= 400) {
            // The message is Google's JSON error body; SheetSyncWorker::sanitize() shortens it.
            throw new RuntimeException($this->errorText($status, $response), $status);
        }
        $data = json_decode($response, true);

        return is_array($data) ? $data : [];
    }

    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpires - 60) {
            return $this->token;
        }
        $state = $this->describeCredentials();
        if (! $state['configured']) {
            throw new RuntimeException($state['hint']);
        }

        $key = $this->key();
        $now = time();
        $aud = (string) ($key['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        if (! str_starts_with($aud, 'https://')) {
            throw new RuntimeException('The token_uri in the key file must be an https:// address.');
        }
        $header = ['alg' => 'RS256', 'typ' => 'JWT'] + (isset($key['private_key_id']) ? ['kid' => (string) $key['private_key_id']] : []);
        $claims = ['iss' => $key['client_email'], 'scope' => self::SCOPE, 'aud' => $aud, 'iat' => $now, 'exp' => $now + 3600];
        $input  = self::base64url((string) json_encode($header)) . '.' . self::base64url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        $private = openssl_pkey_get_private((string) $key['private_key']);
        if ($private === false || ! openssl_sign($input, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The private key in the service-account file could not be used.');
        }
        $assertion = $input . '.' . self::base64url($signature);

        [$status, $response] = $this->http('POST', $aud, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]));
        $data = json_decode($response, true);
        if ($status !== 200 || ! is_array($data) || ! isset($data['access_token'])) {
            $reason = is_array($data) ? trim(($data['error'] ?? '') . ' ' . ($data['error_description'] ?? '')) : '';

            throw new RuntimeException('Google rejected the service-account login' . ($reason !== '' ? ": {$reason}" : '') . '. Check the key file and the server clock.', $status);
        }

        $this->token        = (string) $data['access_token'];
        $this->tokenExpires = $now + (int) ($data['expires_in'] ?? 3600);

        return $this->token;
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string}
     */
    protected function http(string $method, string $url, array $headers, ?string $body): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('cURL could not be initialised.');
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['User-Agent: ' . $this->config->applicationName]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->config->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);
        $status   = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($curl);
        unset($curl);

        if ($response === false) {
            throw new RuntimeException('Could not reach Google: ' . $error);
        }

        return [$status, (string) $response];
    }

    /**
     * @return array<string, mixed>|null the service-account key, or null when invalid
     */
    private function key(): ?array
    {
        $file = $this->config->credentialsFile;
        if ($file === '' || ! is_readable($file)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($file), true);

        return is_array($json) && ($json['type'] ?? '') === 'service_account' && isset($json['client_email'], $json['private_key']) ? $json : null;
    }

    private function errorText(int $status, string $response): string
    {
        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['error'])) {
            return $response;
        }

        return "HTTP {$status}: " . mb_substr(trim(strip_tags($response)), 0, 300);
    }

    private function id(string $spreadsheetId): string
    {
        if (! preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $spreadsheetId)) {
            throw new RuntimeException('The spreadsheet ID is not valid. Copy it from the sheet URL (between /d/ and /edit).');
        }

        return $spreadsheetId;
    }

    private function a1(string $sheet, string $cells): string
    {
        return "'" . str_replace("'", "''", $sheet) . "'!" . $cells;
    }

    /**
     * @param list<scalar|null> $values
     *
     * @return list<string|int|float|bool>
     */
    private function clean(array $values): array
    {
        return array_map(static fn ($v) => $v ?? '', array_values($values));
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

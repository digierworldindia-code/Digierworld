<?php

namespace Tests\Feature;

use App\Services\DispatchPicking;
use App\Services\WarehouseService;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\PolyfixTestCase;

/**
 * The warehouse panel over HTTP: every screen renders, and the doors are
 * where they are supposed to be.
 *
 * The unit tests prove the counting is right. These prove an operator can
 * actually reach it — a view that throws, a route that is not registered or
 * a filter on the wrong group all look identical from a service test.
 *
 * @internal
 */
final class WarehousePanelTest extends PolyfixTestCase
{
    use FeatureTestTrait;

    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->variant = $this->makeProduct()['variant'];
    }

    // =====================================================================
    // Who gets in
    // =====================================================================

    /** @dataProvider warehouseAddresses */
    public function testAnAnonymousVisitorIsSentToSignIn(string $path): void
    {
        $result = $this->get($path);

        $this->assertTrue($result->isRedirect(), "{$path} must not answer an anonymous request");
        $this->assertStringContainsString('login', $result->getRedirectUrl());
    }

    public static function warehouseAddresses(): array
    {
        return [
            'panel'    => ['warehouse'],
            'batches'  => ['warehouse/batches'],
            'new'      => ['warehouse/batches/new'],
            'stock'    => ['warehouse/stock'],
            'find'     => ['warehouse/find'],
            'dispatch' => ['warehouse/dispatch'],
            'history'  => ['warehouse/dispatches'],
        ];
    }

    /** A dealer account is not staff, so the panel is not theirs. */
    public function testADealerCannotReachTheWarehousePanel(): void
    {
        $this->signedInAs($this->makeUser(['DEALER'], $this->makeDealer()));

        $result = $this->get('warehouse');

        $this->assertTrue($result->isRedirect());
        $this->assertStringNotContainsString('/warehouse', (string) $result->getRedirectUrl());
    }

    /**
     * A manager is staff and holds plenty of permissions, but the scanning
     * panel is not their screen: they are sent to the console instead of
     * being shown a 403.
     */
    public function testAConsoleRoleIsSentBackToTheConsole(): void
    {
        $this->signedInAs($this->makeUser(['SALES_MANAGER']));

        $result = $this->get('warehouse');

        $this->assertTrue($result->isRedirect());
        $this->assertStringContainsString('/admin', (string) $result->getRedirectUrl());
    }

    /** Someone has to be able to stand at the station and see what the operator sees. */
    public function testSuperAdminCanOpenThePanel(): void
    {
        $this->signedInAs($this->makeUser(['SUPER_ADMIN']));

        $this->get('warehouse')->assertStatus(200);
    }

    public function testTheWarehousePanelIsNeverIndexedOrCached(): void
    {
        $this->signedInAs($this->makeUser(['WAREHOUSE']));

        $result = $this->get('warehouse');

        $this->assertStringContainsString('noindex', $result->response()->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    // =====================================================================
    // The screens
    // =====================================================================

    public function testEveryWarehouseScreenRenders(): void
    {
        $this->signedInAs($this->makeUser(['WAREHOUSE']));

        $warehouse = new WarehouseService($this->db, service('requestContext'));
        $batch     = $warehouse->openBatch([
            'warehouse_id' => $this->warehouseId(), 'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => 3]]);
        $unit = $warehouse->batchUnits($batch['id'])[0];

        $picking  = new DispatchPicking($this->db, service('requestContext'));
        $dispatch = $picking->start(['warehouse_id' => $this->warehouseId(), 'dealer_id' => $this->makeDealer()]);
        $picking->addUnits($dispatch['id'], [$unit['id']]);

        foreach ([
            'warehouse',
            'warehouse/batches',
            'warehouse/batches/new',
            'warehouse/batches/' . $batch['id'],
            'warehouse/batches/' . $batch['id'] . '/scan',
            'warehouse/batches/' . $batch['id'] . '/labels',
            'warehouse/stock',
            'warehouse/find',
            'warehouse/find?code=' . rawurlencode($unit['serial_number']),
            'warehouse/dispatch',
            'warehouse/dispatch/' . $dispatch['id'],
            'warehouse/dispatch/' . $dispatch['id'] . '?batch=' . $batch['id'],
            'warehouse/dispatch/' . $dispatch['id'] . '/confirm',
            'warehouse/dispatches',
        ] as $path) {
            $result = $this->get($path);
            $this->assertSame(200, $result->response()->getStatusCode(), "{$path} did not render");
        }
    }

    /** The label sheet is the thing that makes "no typing" true. */
    public function testTheLabelSheetCarriesAQrCodeAndSerialForEveryUnit(): void
    {
        $this->signedInAs($this->makeUser(['WAREHOUSE']));

        $warehouse = new WarehouseService($this->db, service('requestContext'));
        $batch     = $warehouse->openBatch([
            'warehouse_id' => $this->warehouseId(), 'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => 4]]);

        $body = (string) $this->get('warehouse/batches/' . $batch['id'] . '/labels')->response()->getBody();

        $this->assertSame(4, substr_count($body, 'label-card__qr'), 'one QR code per mattress');
        foreach ($warehouse->batchUnits($batch['id']) as $unit) {
            $this->assertStringContainsString($unit['serial_number'], $body);
        }
        $this->assertStringContainsString(brand('name'), $body);
    }

    /** The scanning screen must load the scanner, or the camera never opens. */
    public function testTheScanningScreenLoadsTheScannerAndNoInlineScript(): void
    {
        $this->signedInAs($this->makeUser(['WAREHOUSE']));

        $warehouse = new WarehouseService($this->db, service('requestContext'));
        $batch     = $warehouse->openBatch([
            'warehouse_id' => $this->warehouseId(), 'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => 2]]);

        $body = (string) $this->get('warehouse/batches/' . $batch['id'] . '/scan')->response()->getBody();

        $this->assertStringContainsString('assets/js/qr-scanner.js', $body);
        $this->assertStringContainsString('data-scanner', $body);
        $this->assertStringContainsString('0 / 2 Mattresses Scanned', $body);

        // Every <script> on the page must have a src: the policy allows no
        // inline script, so an inline block would simply not run in production.
        preg_match_all('/<script\b([^>]*)>/i', $body, $tags);
        foreach ($tags[1] as $attributes) {
            $this->assertStringContainsString('src=', $attributes, 'a script tag with no src would be blocked by the policy');
        }
    }

    // =====================================================================
    // Scanning over HTTP
    // =====================================================================

    public function testAScanPostedByTheBrowserAnswersJsonWithAFreshToken(): void
    {
        $this->signedInAs($this->makeUser(['WAREHOUSE']));

        $warehouse = new WarehouseService($this->db, service('requestContext'));
        $batch     = $warehouse->openBatch([
            'warehouse_id' => $this->warehouseId(), 'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => 3]]);
        $unit = $warehouse->batchUnits($batch['id'])[0];

        $result = $this->post('warehouse/batches/' . $batch['id'] . '/scan', $this->signed([
            'code' => $unit['serial_number'], 'source' => 'CAMERA',
        ]));

        $result->assertStatus(200);
        $this->assertStringContainsString('application/json', $result->response()->getHeaderLine('Content-Type'));

        // The response object, not the test harness's rendering of it: the
        // harness wraps a body in HTML for display, which the browser never sees.
        $body = json_decode((string) $result->response()->getBody(), true);

        $this->assertTrue($body['ok']);
        $this->assertSame('ACCEPTED', $body['status']);
        $this->assertSame('1 / 3 Mattresses Scanned', $body['counter']['label']);

        // The application rotates the CSRF token on every request, so a
        // scanner that was not handed the new one would work exactly once.
        $this->assertArrayHasKey('csrf', $body);
        $this->assertNotSame('', $body['csrf']);
    }

    public function testAnEmptyScanIsAnsweredWithoutTouchingAnything(): void
    {
        $this->signedInAs($this->makeUser(['WAREHOUSE']));

        $warehouse = new WarehouseService($this->db, service('requestContext'));
        $batch     = $warehouse->openBatch([
            'warehouse_id' => $this->warehouseId(), 'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
        ], [['product_variant_id' => $this->variant, 'quantity' => 2]]);

        $result = $this->post('warehouse/batches/' . $batch['id'] . '/scan', $this->signed(['code' => '']));

        $body = json_decode((string) $result->response()->getBody(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame(0, $warehouse->batchProgress($batch['id'])['scanned']);
    }

    // =====================================================================
    // Opening a batch through the form
    // =====================================================================

    public function testTheNewBatchFormIssuesSerialsAndLandsOnTheBatch(): void
    {
        $this->signedInAs($this->makeUser(['WAREHOUSE']));

        $before = $this->db->table('mattresses')->countAllResults();

        $result = $this->post('warehouse/batches', $this->signed([
            'warehouse_id'    => $this->warehouseId(),
            'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
            'qty'             => [$this->variant => '12'],
        ]));

        $this->assertTrue($result->isRedirect());
        $this->assertSame($before + 12, $this->db->table('mattresses')->countAllResults());
    }

    /**
     * Posts carry a CSRF token, because the application requires one and a
     * test that quietly worked without it would be testing nothing.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function signed(array $fields): array
    {
        return $fields + [csrf_token() => csrf_hash()];
    }

    /** A form with every quantity blank must not open an empty batch. */
    public function testABatchWithNoQuantitiesIsRefused(): void
    {
        $this->signedInAs($this->makeUser(['WAREHOUSE']));

        $before = $this->db->table('manufacturing_batches')->countAllResults();

        $this->post('warehouse/batches', $this->signed([
            'warehouse_id'    => $this->warehouseId(),
            'manufactured_on' => local_time(utc_now(), 'Y-m-d'),
            'qty'             => [$this->variant => '0'],
        ]));

        $this->assertSame($before, $this->db->table('manufacturing_batches')->countAllResults());
    }
}

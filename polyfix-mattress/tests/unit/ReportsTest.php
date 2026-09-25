<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Libraries\Crypto;
use App\Services\ReportService;
use App\Services\SalesService;
use Tests\Support\PolyfixTestCase;

/**
 * Reports: the numbers, the filters, and the fact that a filter value can
 * never become SQL.
 *
 * @internal
 */
final class ReportsTest extends PolyfixTestCase
{
    private ReportService $reports;
    private string $dealerId;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports  = new ReportService($this->db);
        $this->dealerId = $this->makeDealer();
        $this->product  = $this->makeProduct();
        $this->actingAs($this->makeUser(['DEALER'], $this->dealerId));
    }

    public function testEveryReportInTheCatalogueRuns(): void
    {
        foreach (array_keys(ReportService::catalogue()) as $key) {
            $result = $this->reports->run($key, []);
            $this->assertIsArray($result['rows'], "{$key} must return rows");
            $this->assertIsInt($result['total']);
        }
    }

    public function testEveryReportReturnsExactlyTheColumnsItDeclares(): void
    {
        $this->sell('INV-R1');

        foreach (ReportService::catalogue() as $key => $definition) {
            $rows = $this->reports->run($key, [])['rows'];
            if ($rows === []) {
                continue;
            }
            $this->assertSame(array_keys($definition['columns']), array_keys($rows[0]), "{$key} returned different columns");
        }
    }

    public function testTheSalesReportCountsAndValuesWhatWasSold(): void
    {
        $this->sell('INV-R2');
        $this->sell('INV-R3');

        $sales = $this->reports->run('sales', []);
        $this->assertSame(2, $sales['total']);
        $this->assertSame('21500.00', $sales['rows'][0]['sale_price']);

        $monthly = $this->reports->run('sales-by-month', []);
        $this->assertSame(2, (int) $monthly['rows'][0]['units']);
        $this->assertSame('43000.00', $monthly['rows'][0]['value']);
    }

    public function testADateWindowNarrowsTheReport(): void
    {
        $this->sell('INV-R4');

        $inside  = $this->reports->run('sales', ['from' => utc_today(), 'to' => utc_today()]);
        $outside = $this->reports->run('sales', ['from' => '2020-01-01', 'to' => '2020-12-31']);

        $this->assertSame(1, $inside['total']);
        $this->assertSame(0, $outside['total']);
    }

    public function testADealerFilterOnlyShowsThatDealer(): void
    {
        $this->sell('INV-R5');
        $otherDealer = $this->makeDealer();

        $this->assertSame(1, $this->reports->run('sales', ['dealer' => $this->dealerId])['total']);
        $this->assertSame(0, $this->reports->run('sales', ['dealer' => $otherDealer])['total']);
    }

    public function testAFilterValueCanNeverBecomeSql(): void
    {
        $this->sell('INV-R6');

        foreach ([
            "' OR '1'='1",
            '2026-01-01; DROP TABLE sales; --',
            "x' UNION SELECT NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL -- ",
        ] as $attempt) {
            $result = $this->reports->run('sales', ['from' => $attempt, 'dealer' => $attempt, 'status' => $attempt]);
            $this->assertIsArray($result['rows'], 'the query still runs, with the value ignored');
        }

        // The table is still there, with its row.
        $this->assertSame(1, $this->db->table('sales')->where('deleted_at', null)->countAllResults());
    }

    public function testAnUnknownReportIsRefused(): void
    {
        $this->expectException(AppException::class);
        $this->reports->run('../../etc/passwd', []);
    }

    public function testTheInventoryReportCountsUnitsWhereTheyActuallyAre(): void
    {
        $this->makeMattress($this->product['variant']);
        $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);

        $rows = $this->reports->run('inventory', [])['rows'];
        $byStatus = array_column($rows, 'units', 'status');

        $this->assertSame(1, (int) $byStatus['MANUFACTURED']);
        $this->assertSame(1, (int) $byStatus['DEALER_RECEIVED']);
    }

    public function testDealerPerformanceShowsClaimsAgainstSales(): void
    {
        $this->sell('INV-R7');

        $row = $this->reports->run('dealer-performance', ['dealer' => $this->dealerId])['rows'];
        $mine = array_values(array_filter($row, fn ($r) => $r['code'] === $this->db->table('dealers')->select('code')->where('id', $this->dealerId)->get()->getRow()->code));

        $this->assertCount(1, $mine);
        $this->assertSame(1, (int) $mine[0]['sales']);
        $this->assertSame(0, (int) $mine[0]['claims']);
    }

    public function testEveryRowIsStreamedForAnExport(): void
    {
        for ($n = 0; $n < 5; $n++) {
            $this->sell('INV-EXPORT-' . $n);
        }

        $rows = iterator_to_array($this->reports->each('sales', [], 2));
        $this->assertCount(5, $rows, 'an export is not limited to the page on screen');
    }

    private function sell(string $invoice): void
    {
        $unit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);
        (new SalesService($this->db, service('requestContext'), Crypto::instance()))->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => $invoice, 'sold_on' => utc_today(),
            'sale_price' => 21500, 'payment_mode' => 'CASH',
            'customer' => ['full_name' => 'Ritu Sharma', 'phone' => '98' . random_int(10_000_000, 99_999_999), 'city' => 'Jaipur'],
        ]);
    }
}

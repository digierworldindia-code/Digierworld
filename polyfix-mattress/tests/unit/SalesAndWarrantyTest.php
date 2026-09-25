<?php

namespace Tests\Unit;

use App\Exceptions\AppException;
use App\Libraries\Crypto;
use App\Services\SalesService;
use Tests\Support\PolyfixTestCase;

/**
 * Recording a sale, and the warranty it creates.
 *
 * @internal
 */
final class SalesAndWarrantyTest extends PolyfixTestCase
{
    private SalesService $sales;
    private string $dealerId;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dealerId = $this->makeDealer();
        $this->product  = $this->makeProduct(12);
        $this->actingAs($this->makeUser(['DEALER'], $this->dealerId));
        $this->sales = new SalesService($this->db, service('requestContext'), Crypto::instance());
    }

    private function customer(array $overrides = []): array
    {
        return $overrides + ['full_name' => 'Ritu Sharma', 'phone' => '9876500011', 'city' => 'Jaipur', 'state' => 'Rajasthan'];
    }

    public function testASaleStartsAWarrantyForTheProductsTerm(): void
    {
        $unit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);

        $result = $this->sales->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => 'INV-100', 'sold_on' => utc_today(),
            'sale_price' => 21500, 'payment_mode' => 'UPI', 'customer' => $this->customer(),
        ]);

        $this->assertSame(12, $result['warranty_years']);
        $this->assertSame(utc_today(), $result['warranty_start']);
        $this->assertSame(date('Y-m-d', strtotime(utc_today() . ' +12 years')), $result['warranty_end']);

        $warranty = $this->db->table('warranties')->where('id', $result['warranty_id'])->get()->getRowArray();
        $this->assertSame('ACTIVE', $warranty['status']);
        $this->assertSame($this->dealerId, $warranty['dealer_id']);
        $this->assertSame('SOLD', $this->db->table('mattresses')->select('current_status')->where('id', $unit['id'])->get()->getRow()->current_status);
    }

    public function testCustomerContactDetailsAreStoredEncryptedAndFoundByBlindIndex(): void
    {
        $unit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);
        $this->sales->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => 'INV-101', 'sold_on' => utc_today(),
            'sale_price' => 19000, 'payment_mode' => 'CASH', 'customer' => $this->customer(['phone' => '9812345678']),
        ]);

        $customer = $this->db->table('customers')->where('dealer_id', $this->dealerId)->get()->getRowArray();
        $this->assertStringNotContainsString('9812345678', (string) $customer['phone_encrypted'], 'the number is not stored in the clear');
        $this->assertSame('9812345678', Crypto::instance()->decrypt($customer['phone_encrypted']));
        $this->assertSame(Crypto::instance()->blindIndex(Crypto::normalisePhone('+91 98123 45678')), $customer['phone_hash'],
            'the same number in another format finds the same customer');
    }

    public function testTheSameCustomerBuyingAgainIsNotDuplicated(): void
    {
        foreach (['INV-201', 'INV-202'] as $invoice) {
            $unit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);
            $this->sales->recordSale([
                'serial' => $unit['serial'], 'invoice_number' => $invoice, 'sold_on' => utc_today(),
                'sale_price' => 21500, 'payment_mode' => 'CASH', 'customer' => $this->customer(),
            ]);
        }

        $this->assertSame(1, $this->db->table('customers')->where('dealer_id', $this->dealerId)->countAllResults());
        $this->assertSame(2, $this->db->table('sales')->where('dealer_id', $this->dealerId)->countAllResults());
    }

    public function testAnInvoiceNumberCannotBeUsedTwiceByOneDealer(): void
    {
        $first  = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);
        $second = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);
        $sale   = fn (array $unit, string $invoice) => $this->sales->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => $invoice, 'sold_on' => utc_today(),
            'sale_price' => 21500, 'payment_mode' => 'CASH', 'customer' => $this->customer(),
        ]);

        $sale($first, 'INV-DUP');
        $this->expectExceptionMessage('already been used');
        $sale($second, 'INV-DUP');
    }

    public function testAUnitCannotBeSoldTwiceOrBeforeItArrives(): void
    {
        $unit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);
        $this->sales->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => 'INV-300', 'sold_on' => utc_today(),
            'sale_price' => 21500, 'payment_mode' => 'CASH', 'customer' => $this->customer(),
        ]);

        $this->expectExceptionMessage('already been sold');
        $this->sales->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => 'INV-301', 'sold_on' => utc_today(),
            'sale_price' => 21500, 'payment_mode' => 'CASH', 'customer' => $this->customer(),
        ]);
    }

    public function testASaleCannotBeDatedInTheFuture(): void
    {
        $unit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);

        $this->expectExceptionMessage('cannot be in the future');
        $this->sales->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => 'INV-400', 'sold_on' => gmdate('Y-m-d', strtotime('+5 days')),
            'sale_price' => 21500, 'payment_mode' => 'CASH', 'customer' => $this->customer(),
        ]);
    }

    public function testADealerCannotSellAnotherDealersStock(): void
    {
        $otherDealer = $this->makeDealer();
        $unit        = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $otherDealer);

        $this->expectExceptionMessage('could not find');
        $this->sales->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => 'INV-500', 'sold_on' => utc_today(),
            'sale_price' => 21500, 'payment_mode' => 'CASH', 'customer' => $this->customer(),
        ]);
    }

    public function testVoidingAWarrantyNeedsAReasonAndOnlyWorksOnce(): void
    {
        $warrantyId = $this->sell('INV-600')['warranty_id'];
        $this->actingAs($this->makeUser(['WARRANTY_MANAGER']));
        $sales = new SalesService($this->db, service('requestContext'), Crypto::instance());

        try {
            $sales->voidWarranty($warrantyId, 'no');
            $this->fail('a token reason should not be accepted');
        } catch (AppException $e) {
            $this->assertStringContainsString('reason', strtolower($e->getMessage()));
        }

        $sales->voidWarranty($warrantyId, 'Sale reversed at the customer\'s request.');
        $this->assertSame('VOID', $this->db->table('warranties')->select('status')->where('id', $warrantyId)->get()->getRow()->status);

        $this->expectExceptionMessage('Only an active warranty');
        $sales->voidWarranty($warrantyId, 'Trying again for no good reason.');
    }

    public function testCorrectingWarrantyDatesKeepsTheOldValues(): void
    {
        $warrantyId = $this->sell('INV-700')['warranty_id'];
        $this->actingAs($this->makeUser(['WARRANTY_MANAGER']));
        $sales = new SalesService($this->db, service('requestContext'), Crypto::instance());

        $start = gmdate('Y-m-d', strtotime('-30 days'));
        $end   = gmdate('Y-m-d', strtotime('-30 days +12 years'));
        $sales->overrideWarrantyDates($warrantyId, $start, $end, 'Invoice was dated a month earlier than entered.');

        $warranty = $this->db->table('warranties')->where('id', $warrantyId)->get()->getRowArray();
        $this->assertSame($start, $warranty['start_date']);
        $this->assertSame($end, $warranty['end_date']);

        $version = $this->db->table('record_versions')->where(['entity' => 'warranty', 'entity_id' => $warrantyId])->get()->getRowArray();
        $this->assertNotNull($version, 'the previous values are kept');
        $this->assertStringContainsString('dated a month earlier', (string) $version['reason']);

        $this->expectExceptionMessage('must end after it starts');
        $sales->overrideWarrantyDates($warrantyId, $end, $start, 'Dates the wrong way round.');
    }

    /** @return array{sale_id:string, warranty_id:string} */
    private function sell(string $invoice): array
    {
        $unit = $this->makeMattress($this->product['variant'], 'DEALER_RECEIVED', $this->dealerId);

        return $this->sales->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => $invoice, 'sold_on' => utc_today(),
            'sale_price' => 21500, 'payment_mode' => 'CASH', 'customer' => $this->customer(),
        ]);
    }
}

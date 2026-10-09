<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OrderModel;
use App\Models\QuotationModel;
use App\Models\RfqMatchModel;
use App\Models\RfqModel;
use Tests\Support\BCTestCase;

/**
 * TEST 4: Buyer registers → searches part → submits RFQ → verified supplier
 * receives matched RFQ → quotation submitted → buyer responds.
 */
final class T04RfqFlowTest extends BCTestCase
{
    public function testRfqMatchingQuotationAndAcceptance(): void
    {
        $verified = $this->verifiedSupplier('IN');
        $p = $this->product($verified['company'], ['part_number' => '30205', 'name' => 'Tapered roller bearing'], 5000);
        $free = $this->registerCompany('supplier', 'DE');
        $this->product($free['company'], ['part_number' => '30205', 'sale_mode' => 'lot', 'unit_price' => '', 'lot_price' => '1000', 'lot_quantity' => 100]);
        $other = $this->verifiedSupplier('IN'); // verified, but no matching stock

        $email = $this->uniqueEmail('buyer');
        $this->postAs(null, 'register/buyer', ['legal_name' => 'Gulf Test Trading LLC', 'country_code' => 'AE', 'city' => 'Dubai', 'phone' => '+971 500000000',
            'email' => $email, 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD, 'terms' => '1'])->assertRedirectTo(site_url('buyer/dashboard'));
        $buyer = auth()->getProvider()->findByCredentials(['email' => $email]);

        $__r = $this->getAs($buyer, 'marketplace?q=30205');
        $__r->assertOK();
        $__r->assertSee('Tapered roller bearing');

        $this->postAs($buyer, 'buyer/rfqs', ['title' => 'Annual 30205 requirement', 'destination_country' => 'AE', 'currency' => 'INR', 'delivery_terms' => 'CIF',
            'deadline_at' => date('Y-m-d\TH:i', strtotime('+5 days')), 'items' => [['part_number' => '30205', 'description' => 'Tapered roller bearing', 'quantity' => '1000', 'unit' => 'pcs']]])->assertRedirect();
        $rfq = model(RfqModel::class)->orderBy('id', 'DESC')->first();
        $this->assertMatchesRegularExpression('/^RFQ-\d{4}-\d{6}$/', $rfq['rfq_number']);

        // Matching is database-driven: only the verified supplier that lists 30205.
        $matches = model(RfqMatchModel::class)->where('rfq_id', $rfq['id'])->findAll();
        $this->assertCount(1, $matches);
        $this->assertSame((int) $verified['company']['id'], (int) $matches[0]['supplier_company_id']);
        $this->assertStringContainsString('Lists part number 30205', $matches[0]['match_reasons']);

        // Not yet distributed → supplier cannot open it.
        $this->assertNotFoundFor($verified['user'], 'supplier/rfqs/' . $rfq['id']);

        $pm = $this->staff('procurement_manager');
        $this->postAs($pm, "admin/rfqs/{$rfq['id']}/distribute", ['suppliers' => [(string) $verified['company']['id']], 'extra_supplier' => (string) $free['company']['id']])->assertRedirect();
        $this->assertSame('distributed', model(RfqModel::class)->find($rfq['id'])['status']);
        $this->assertSame(0, model(RfqMatchModel::class)->where('rfq_id', $rfq['id'])->where('supplier_company_id', $free['company']['id'])->countAllResults(), 'unverified supplier never receives premium RFQs');

        $page = $this->getAs($verified['user'], 'supplier/rfqs/' . $rfq['id']);
        $__r = $page;
        $__r->assertOK();
        $__r->assertSee('Annual 30205 requirement');
        $__r->assertDontSee('Gulf Test Trading');
        $this->assertNotFoundFor($other['user'], 'supplier/rfqs/' . $rfq['id']);

        $item = db_connect()->table('rfq_items')->where('rfq_id', $rfq['id'])->get()->getRowArray();
        $this->postAs($verified['user'], "supplier/rfqs/{$rfq['id']}/quote", ['currency' => 'INR', 'valid_until' => date('Y-m-d', strtotime('+20 days')), 'lead_time_days' => '10',
            'payment_terms' => '100% advance', 'logistics_option' => 'confidential', 'lines' => [$item['id'] => ['unit_price' => '75.50', 'quantity' => '1000', 'product_id' => $p['id']]]])->assertRedirect();
        $q = model(QuotationModel::class)->where('rfq_id', $rfq['id'])->first();
        $this->assertSame(75500.0, (float) $q['total_amount']);

        // Revision supersedes the first bid.
        $this->postAs($verified['user'], "supplier/rfqs/{$rfq['id']}/quote", ['currency' => 'INR', 'valid_until' => date('Y-m-d', strtotime('+20 days')), 'logistics_option' => 'confidential',
            'lines' => [$item['id'] => ['unit_price' => '72', 'quantity' => '1000', 'product_id' => $p['id']]]])->assertRedirect();
        $current = model(QuotationModel::class)->where('rfq_id', $rfq['id'])->where('is_current', 1)->first();
        $this->assertSame(2, (int) $current['revision']);
        $this->assertSame('superseded', model(QuotationModel::class)->find($q['id'])['status']);

        // Buyer compares (supplier identity masked) and negotiates, then accepts.
        $view = $this->getAs($buyer, 'buyer/rfqs/' . $rfq['id']);
        $__r = $view;
        $__r->assertOK();
        $__r->assertSee('72,000.00');
        $__r->assertDontSee($verified['company']['legal_name']);
        $this->postAs($buyer, "buyer/quotations/{$current['id']}/message", ['message' => 'Can you do 70?', 'proposed_total' => '70000'])->assertRedirect();
        $this->postAs($buyer, "buyer/quotations/{$current['id']}/accept", ['delivery_address' => 'Jebel Ali, Dubai', 'accept_terms' => '1'])->assertRedirect();

        $order = model(OrderModel::class)->where('quotation_id', $current['id'])->first();
        $this->assertNotNull($order);
        $this->assertSame('awaiting_payment', $order['status'], 'accepted quotation = agreed terms → proforma issued');
        $this->assertSame('awarded', model(RfqModel::class)->find($rfq['id'])['status']);
        $this->assertSame(1000, (int) db_connect()->table('products')->where('id', $p['id'])->get()->getRow()->stock_reserved);
    }
}

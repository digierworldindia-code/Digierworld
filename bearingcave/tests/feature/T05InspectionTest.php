<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InspectionRequestModel;
use App\Models\ProductModel;
use Tests\Support\BCTestCase;

/**
 * TEST 5: Buyer selects inspection → fee calculation → admin assigns
 * inspection → result/report uploaded.
 */
final class T05InspectionTest extends BCTestCase
{
    public function testInHouseInspectionLifecycle(): void
    {
        $s = $this->verifiedSupplier();
        $p = $this->product($s['company']);
        $b = $this->registerCompany('buyer');

        $this->postAs($b['user'], 'buyer/inspections', ['inspection_type' => 'in_house', 'scope' => ['physical', 'quantity', 'authenticity'], 'location_country' => 'IN',
            'location_city' => 'Pune', 'invoice_value' => '250000', 'currency' => 'INR', 'product_id' => $p['id']])->assertRedirect();
        $req = model(InspectionRequestModel::class)->orderBy('id', 'DESC')->first();
        $this->assertSame(25000.0, (float) $req['estimated_fee'], '10% of invoice value');
        $this->assertSame('requested', model(ProductModel::class)->find($p['id'])['inspection_status']);

        $im = $this->staff('inspection_manager');
        // Report cannot be uploaded before quote/acceptance/assignment.
        $this->postAs($im, "admin/inspections/{$req['id']}/report", ['result' => 'pass', 'summary' => 'x', 'inspected_on' => date('Y-m-d')])->assertRedirect();
        $this->assertNotNull($this->flashError());

        $this->postAs($im, "admin/inspections/{$req['id']}/quote", ['quoted_fee' => '25000', 'quote_valid_until' => date('Y-m-d', strtotime('+7 days'))])->assertRedirect();
        $this->postAs($b['user'], "buyer/inspections/{$req['id']}/respond", ['decision' => 'accept'])->assertRedirect();
        $req = model(InspectionRequestModel::class)->find($req['id']);
        $this->assertSame('accepted', $req['status']);
        $this->assertNotNull($req['invoice_id']);

        $this->postAs($im, "admin/inspections/{$req['id']}/assign", ['inspector_user_id' => $im->id, 'scheduled_on' => date('Y-m-d', strtotime('+2 days'))])->assertRedirect();
        $this->assertSame('assigned', model(InspectionRequestModel::class)->find($req['id'])['status']);

        // Report upload (file stored privately via DocumentService).
        $this->actingAs($im);
        service('inspections')->uploadReport(model(InspectionRequestModel::class)->find($req['id']), ['result' => 'pass', 'inspected_on' => date('Y-m-d'), 'quantity_expected' => '100', 'quantity_verified' => '100', 'packaging_ok' => '1', 'summary' => 'All units verified; original packaging intact.'], $this->pdf('report.pdf'), (int) $im->id);
        $this->assertSame('report_uploaded', model(InspectionRequestModel::class)->find($req['id'])['status']);
        $this->assertSame('passed', model(ProductModel::class)->find($p['id'])['inspection_status']);

        $this->postAs($im, "admin/inspections/{$req['id']}/complete")->assertRedirect();
        $this->assertSame('completed', model(InspectionRequestModel::class)->find($req['id'])['status']);
        $__r = $this->getAs($b['user'], 'buyer/inspections/' . $req['id']);
        $__r->assertOK();
        $__r->assertSee('All units verified');
        $__r->assertSee('Inspection report');
    }
}

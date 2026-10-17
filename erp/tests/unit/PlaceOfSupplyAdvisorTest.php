<?php

namespace Tests\Unit;

use App\Services\Billing\PlaceOfSupplyAdvisor;
use CodeIgniter\Test\CIUnitTestCase;

final class PlaceOfSupplyAdvisorTest extends CIUnitTestCase
{
    private function ctx(array $o = []): array
    {
        return $o + [
            'doc_type' => 'tax_invoice', 'supplier_state' => '08', 'supplier_registration' => 'regular', 'supplier_has_gstin' => true,
            'customer_gstin' => null, 'customer_registration' => 'unregistered', 'customer_state' => '08',
            'pos_state' => '08', 'gst_type' => 'cgst_sgst', 'is_sez' => false, 'export_type' => 'none', 'reverse_charge' => false,
            'lines' => [['tax_treatment' => 'regular', 'gst_rate' => '18']],
            'treatments' => [
                'regular' => ['charges_tax' => true, 'requires_reason' => false, 'label' => 'Regular'],
                'exempt' => ['charges_tax' => false, 'requires_reason' => true, 'label' => 'Exempt'],
                'zero_rated_lut' => ['charges_tax' => false, 'requires_reason' => true, 'label' => 'LUT'],
            ],
            'states' => [
                '08' => ['name' => 'Rajasthan', 'uses_utgst' => false, 'is_special' => false],
                '07' => ['name' => 'Delhi', 'uses_utgst' => false, 'is_special' => false],
                '04' => ['name' => 'Chandigarh', 'uses_utgst' => true, 'is_special' => false],
                '96' => ['name' => 'Other Country', 'uses_utgst' => false, 'is_special' => true],
            ],
            'lut_valid' => false, 'einvoice_applicable' => false, 'taxable_doc' => true,
        ];
    }

    public function testIntraStateIsOkWithCgstSgst(): void
    {
        $r = PlaceOfSupplyAdvisor::advise($this->ctx());
        $this->assertSame('ok', $r['status']);
        $this->assertSame('cgst_sgst', $r['suggested_type']);
        $this->assertSame(['cgst_sgst', 'none'], $r['allowed_types']);
    }

    public function testChoosingIgstForIntraStateIsBlocked(): void
    {
        $r = PlaceOfSupplyAdvisor::advise($this->ctx(['gst_type' => 'igst']));
        $this->assertSame('block', $r['status']);
        $this->assertStringContainsString('not legally possible', $r['messages'][0]['text']);
    }

    public function testRegisteredRecipientInOtherStateSuggestsIgstFromGstinState(): void
    {
        $r = PlaceOfSupplyAdvisor::advise($this->ctx(['customer_gstin' => '07AAACD1234E1ZB', 'customer_registration' => 'registered', 'customer_state' => '07', 'pos_state' => '07', 'gst_type' => 'igst']));
        $this->assertSame('07', $r['suggested_pos']);
        $this->assertSame('igst', $r['suggested_type']);
        $this->assertSame('ok', $r['status']);
        $blocked = PlaceOfSupplyAdvisor::advise($this->ctx(['customer_gstin' => '07AAACD1234E1ZB', 'customer_registration' => 'registered', 'customer_state' => '07', 'pos_state' => '07', 'gst_type' => 'cgst_sgst']));
        $this->assertSame('block', $blocked['status']);
    }

    public function testMissingPlaceOfSupplyBlocksFinalization(): void
    {
        $r = PlaceOfSupplyAdvisor::advise($this->ctx(['pos_state' => null]));
        $this->assertSame('block', $r['status']);
        $this->assertContains('Place of supply is required before finalization.', array_column($r['messages'], 'text'));
    }

    public function testOverridingSuggestedPosNeedsReview(): void
    {
        $r = PlaceOfSupplyAdvisor::advise($this->ctx(['customer_gstin' => '07AAACD1234E1ZB', 'customer_registration' => 'registered', 'customer_state' => '07', 'pos_state' => '08', 'gst_type' => 'cgst_sgst']));
        $this->assertSame('review', $r['status']);
    }

    public function testUnionTerritoryRequiresUtgst(): void
    {
        $ok = PlaceOfSupplyAdvisor::advise($this->ctx(['supplier_state' => '04', 'customer_state' => '04', 'pos_state' => '04', 'gst_type' => 'cgst_utgst']));
        $this->assertSame('ok', $ok['status']);
        $bad = PlaceOfSupplyAdvisor::advise($this->ctx(['supplier_state' => '04', 'customer_state' => '04', 'pos_state' => '04', 'gst_type' => 'cgst_sgst']));
        $this->assertSame('block', $bad['status']);
    }

    public function testNoGstWithTaxableLinesIsBlocked(): void
    {
        $r = PlaceOfSupplyAdvisor::advise($this->ctx(['gst_type' => 'none']));
        $this->assertSame('block', $r['status']);
    }

    public function testExemptTreatmentRequiresReview(): void
    {
        $r = PlaceOfSupplyAdvisor::advise($this->ctx(['gst_type' => 'none', 'lines' => [['tax_treatment' => 'exempt', 'gst_rate' => '0']]]));
        $this->assertSame('review', $r['status']);
    }

    public function testExportUnderLutNeedsValidLut(): void
    {
        $base = ['customer_registration' => 'overseas', 'customer_state' => null, 'pos_state' => '96', 'export_type' => 'lut', 'gst_type' => 'none', 'lines' => [['tax_treatment' => 'zero_rated_lut', 'gst_rate' => '18']]];
        $this->assertSame('block', PlaceOfSupplyAdvisor::advise($this->ctx($base))['status']);
        $this->assertSame('review', PlaceOfSupplyAdvisor::advise($this->ctx($base + ['lut_valid' => true]))['status']);
    }

    public function testCompositionSupplierCannotIssueTaxInvoice(): void
    {
        $r = PlaceOfSupplyAdvisor::advise($this->ctx(['supplier_registration' => 'composition']));
        $this->assertSame('block', $r['status']);
    }

    public function testSpecialRuleServiceNeedsReview(): void
    {
        $r = PlaceOfSupplyAdvisor::advise($this->ctx(['lines' => [['tax_treatment' => 'regular', 'gst_rate' => '18', 'pos_rule' => 'review_required']]]));
        $this->assertSame('review', $r['status']);
    }
}

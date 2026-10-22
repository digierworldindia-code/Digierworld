<?php

namespace Tests\Feature;

use Smalot\PdfParser\Parser;
use Tests\Support\DgrTestCase;

/**
 * End-to-end workflows through the real forms (no service shortcuts):
 * customer → GST invoice → finalize → PDF download, and lead → follow-up.
 */
final class RegressionWorkflowTest extends DgrTestCase
{
    public function testCustomerToFinalizedInvoicePdfThroughTheForms(): void
    {
        $this->setupCompany('08');
        $this->loginAs($this->makeUser('super_admin', 'Workflow Owner'));
        $gstin = $this->gstin('08', 'WFLCW1234W'); // 4th PAN letter C = company

        $res = $this->postForm('contacts', ['name' => 'Workflow Traders Pvt Ltd', 'is_customer' => '1', 'gstin' => $gstin, 'gst_registration_type' => 'registered',
            'phone' => '9876501234', 'state_code' => '08', 'billing_line1' => 'Plot 7, RIICO', 'city' => 'Bhiwadi', 'pincode' => '301019']);
        $res->assertRedirect();
        $this->assertSame([], (array) (session('errors') ?? []), 'no validation errors');
        $contact = db_connect()->table('contacts')->where('gstin', $gstin)->get()->getRow();
        $this->assertNotNull($contact, 'customer saved from the form');

        $draft = $this->postForm('billing/documents', [
            'doc_type' => 'tax_invoice', 'contact_id' => $contact->id, 'issue_date' => date('Y-m-d'), 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'price_mode' => 'exclusive',
            'lines' => [['item_name' => 'Website maintenance', 'sac_code' => '998314', 'qty' => '2', 'unit' => 'month', 'unit_rate' => '7500', 'tax_treatment' => 'regular', 'gst_rate' => '18']],
        ]);
        $draft->assertRedirect();
        preg_match('#billing/documents/(\d+)$#', $draft->getRedirectUrl(), $m);
        $id = (int) $m[1];
        $this->postForm('billing/documents/' . $id . '/finalize', [])->assertRedirectTo(site_url('billing/documents/' . $id));
        $doc = db_connect()->table('billing_documents')->where('id', $id)->get()->getRow();
        $this->assertSame('finalized', $doc->status);
        $this->assertSame('17700.00', $doc->grand_total);

        $pdf = $this->get('billing/documents/' . $id . '/pdf?download=1');
        $pdf->assertOK();
        $this->assertStringContainsString('attachment', $pdf->response()->getHeaderLine('Content-Disposition'));
        $text = preg_replace('/\s+/', ' ', (new Parser())->parseContent((string) $pdf->response()->getBody())->getText());
        foreach (['TAX INVOICE', $doc->document_number, 'Workflow Traders Pvt Ltd', $gstin, 'CGST', 'SGST', '1,350.00', '17,700.00', 'Seventeen Thousand Seven Hundred'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function testLeadAndFollowUpThroughTheForms(): void
    {
        $this->loginAs($this->makeUser('telecaller', 'Workflow Caller'));
        $mobile = '9' . random_int(100000000, 999999999);
        $res = $this->postForm('crm/leads', ['company_name' => 'Workflow Clinic', 'contact_person' => 'Dr. Mehta', 'mobile' => $mobile, 'city' => 'Alwar']);
        $res->assertRedirect();
        preg_match('#crm/leads/(\d+)$#', $res->getRedirectUrl(), $m);
        $lead = (int) $m[1];
        $this->assertGreaterThan(0, $lead);

        $due = date('Y-m-d 11:00', strtotime('+2 days'));
        $this->postForm('crm/leads/' . $lead . '/followups', ['due_at' => $due, 'title' => 'Share website proposal'])->assertRedirect();
        $f = db_connect()->table('followups')->where('lead_id', $lead)->get()->getRow();
        $this->assertSame('Share website proposal', $f->title);
        $this->assertSame('pending', $f->status);

        $page = $this->get('crm/leads/' . $lead);
        $page->assertOK();
        $page->assertSee('Workflow Clinic');
        $page->assertSee('Share website proposal');
    }
}

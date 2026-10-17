<?php

namespace Tests\Feature;

use Smalot\PdfParser\Parser;
use Tests\Support\DgrTestCase;

/**
 * Billing screens over HTTP, the printed PDF (text extracted from the real
 * Dompdf output) and record-level access on billing documents.
 */
final class BillingUiPdfTest extends DgrTestCase
{
    private \CodeIgniter\Shield\Entities\User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompany('08');
        $this->owner = $this->makeUser('super_admin', 'Test Owner');
        $this->loginAs($this->owner);
    }

    private function body(string $uri): string
    {
        $res = $this->get($uri);
        $res->assertOK();

        return (string) $res->response()->getBody();
    }

    private function pdfText(string $bytes): string
    {
        $this->assertStringStartsWith('%PDF-', $bytes);

        return preg_replace('/\s+/u', ' ', (new Parser())->parseContent($bytes)->getText());
    }

    private function draftViaHttp(int $contact, string $pos, string $gstType, string $amount = '10000', string $type = 'tax_invoice'): int
    {
        $res = $this->postForm('billing/documents', [
            'doc_type' => $type, 'contact_id' => $contact, 'issue_date' => date('Y-m-d'),
            'place_of_supply_state_code' => $pos, 'gst_type' => $gstType, 'price_mode' => 'exclusive',
            'lines' => [['item_name' => 'SEO Monthly Retainer', 'sac_code' => '998361', 'qty' => '1', 'unit' => 'month', 'unit_rate' => $amount, 'tax_treatment' => 'regular', 'gst_rate' => '18']],
        ]);
        $res->assertRedirect();
        $this->assertMatchesRegularExpression('#billing/documents/(\d+)$#', $res->getRedirectUrl());
        preg_match('#billing/documents/(\d+)$#', $res->getRedirectUrl(), $m);

        return (int) $m[1];
    }

    public function testIntraStateInvoiceThroughScreensAndPdfShowsOnlyCgstSgst(): void
    {
        $bank = $this->makeBankAccount();
        db_connect()->table('bank_cash_accounts')->where('id', $bank)->update(['is_default_on_invoice' => 1]);
        $c = $this->makeContact(['name' => 'Jaipur Retail Pvt Ltd', 'gstin' => $this->gstin('08'), 'state_code' => '08']);

        $this->get('billing/documents/new?type=tax_invoice')->assertSee('GST Type');

        // live calculation endpoint
        $calc = $this->withHeaders(['X-CSRF-TOKEN' => csrf_hash()])->withBodyFormat('json')->post('billing/documents/calc', [
            'doc_type' => 'tax_invoice', 'contact_id' => $c, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst',
            'lines' => [['item_name' => 'SEO', 'qty' => '1', 'unit_rate' => '10000', 'tax_treatment' => 'regular', 'gst_rate' => '18']],
        ]);
        $calc->assertOK();
        $j = json_decode($calc->getJSON(), true);
        $this->assertSame('900.00', $j['totals']['cgst_total']);
        $this->assertSame('900.00', $j['totals']['sgst_total']);
        $this->assertSame('0.00', $j['totals']['igst_total']);
        $this->assertSame('1800.00', $j['preview']['igst']['tax_total'], 'IGST preview is the full rate, never 18% + 18%');
        $this->assertSame('11800.00', $j['totals']['grand_total']);
        $this->withHeaders([])->withBodyFormat('');

        $id = $this->draftViaHttp($c, '08', 'cgst_sgst');
        $this->get('billing/documents/' . $id)->assertSee('Finalize');
        $draftPdf = $this->pdfText($this->body('billing/documents/' . $id . '/pdf'));
        $this->assertStringContainsString('DRAFT', $draftPdf);

        $this->postForm('billing/documents/' . $id . '/finalize', [])->assertRedirectTo(site_url('billing/documents/' . $id));
        $doc = db_connect()->table('billing_documents')->where('id', $id)->get()->getRowArray();
        $this->assertSame('finalized', $doc['status']);

        $res = $this->get('billing/documents/' . $id . '/pdf?download=1');
        $res->assertOK();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', $res->response()->getHeaderLine('Content-Disposition'));
        $text = $this->pdfText((string) $res->response()->getBody());
        if ($dir = getenv('DGR_EVIDENCE_DIR')) {
            file_put_contents($dir . '/tax-invoice-intra-state.pdf', (string) $res->response()->getBody());
        }

        $this->assertStringContainsString('TAX INVOICE', $text);
        $this->assertStringContainsString($doc['document_number'], $text);
        $this->assertStringContainsString($this->gstin('08'), $text);
        $this->assertStringContainsString('CGST', $text);
        $this->assertStringContainsString('SGST', $text);
        $this->assertStringContainsString('900.00', $text);
        $this->assertStringContainsString('11,800.00', $text);
        $this->assertStringContainsString('998361', $text);
        $this->assertStringNotContainsString('IGST', $text, 'Intra-state invoice must not print IGST columns');
        $this->assertStringNotContainsString('DRAFT', $text);
        $this->assertStringNotContainsString('CIF998877', $text, 'Company CIF never appears in PDFs');
        $this->assertStringContainsString('123456789012', $text, 'Bank account for payment is printed');
        $this->assertStringContainsString('HDFC0001234', $text);
        $this->assertStringContainsString('Eleven Thousand Eight Hundred', $text);

        // PDF view/download is audited for finalized documents
        $this->assertGreaterThan(0, db_connect()->table('audit_logs')->where(['entity_type' => 'billing_document', 'entity_id' => $id, 'action' => 'billing.pdf_downloaded'])->countAllResults());

        // finalized documents cannot be edited through the screen either
        $this->get('billing/documents/' . $id . '/edit')->assertRedirect();
        $this->postForm('billing/documents/' . $id, ['doc_type' => 'tax_invoice', 'contact_id' => $c, 'lines' => [$this->line('1')]])->assertRedirect();
        $this->assertSame('11800.00', db_connect()->table('billing_documents')->where('id', $id)->get()->getRow()->grand_total);

        // the list shows it and the export works
        $this->get('billing/documents?type=tax_invoice')->assertSee($doc['document_number']);
        $x = $this->get('billing/documents/export?type=tax_invoice&format=csv');
        $x->assertOK();
        $this->assertStringContainsString($doc['document_number'], (string) $x->response()->getBody());
    }

    public function testInterStateInvoicePdfShowsOnlyIgst(): void
    {
        $c  = $this->makeContact(['name' => 'Delhi Brands LLP', 'gstin' => $this->gstin('07'), 'state_code' => '07']);
        $id = $this->draftViaHttp($c, '07', 'igst');
        $this->postForm('billing/documents/' . $id . '/finalize', [])->assertRedirect();
        $doc = db_connect()->table('billing_documents')->where('id', $id)->get()->getRowArray();
        $this->assertSame('finalized', $doc['status']);
        $this->assertSame('1800.00', $doc['igst_total']);

        $text = $this->pdfText($this->body('billing/documents/' . $id . '/pdf'));
        $this->assertStringContainsString('IGST', $text);
        $this->assertStringContainsString('1,800.00', $text);
        $this->assertStringContainsString('11,800.00', $text);
        $this->assertStringNotContainsString('CGST', $text);
        $this->assertStringNotContainsString('SGST', $text);
    }

    public function testCashMemoPdfIsTitledAndTaxedAsTaxInvoice(): void
    {
        $c  = $this->makeContact(['name' => 'Walk-in Customer', 'state_code' => '08']);
        $id = $this->draftViaHttp($c, '08', 'cgst_sgst', '1000', 'cash_memo');
        $this->postForm('billing/documents/' . $id . '/finalize', [])->assertRedirect();
        $text = $this->pdfText($this->body('billing/documents/' . $id . '/pdf'));
        $this->assertStringContainsString('CASH MEMO', $text);
        $this->assertStringContainsString('CM-', $text);
        $this->assertStringContainsString('90.00', $text);
        $this->assertStringContainsString('1,180.00', $text);
    }

    public function testImpossibleGstTypeDraftIsBlockedAndCannotBeFinalizedFromScreen(): void
    {
        // Delhi (07) has its own legislature: CGST+UTGST is impossible there.
        $c  = $this->makeContact(['gstin' => $this->gstin('07'), 'state_code' => '07']);
        $id = $this->draftViaHttp($c, '07', 'cgst_utgst');
        $this->assertSame('blocked', db_connect()->table('billing_documents')->where('id', $id)->get()->getRow()->tax_review_status);
        $advice = json_decode(db_connect()->table('billing_documents')->where('id', $id)->get()->getRow()->tax_advice, true);
        $block  = array_values(array_filter($advice['messages'], static fn ($m) => $m['level'] === 'block'));
        $this->assertNotEmpty($block);
        $this->get('billing/documents/' . $id)->assertSee($block[0]['text']);

        $this->postForm('billing/documents/' . $id . '/finalize', ['tax_review_reason' => 'Trying to force it through anyway'])->assertRedirect();
        $row = db_connect()->table('billing_documents')->where('id', $id)->get()->getRow();
        $this->assertSame('draft', $row->status);
        $this->assertNull($row->document_number, 'A blocked draft never consumes a number');
    }

    public function testB2bLineWithoutSacBlocksFinalizationButB2cOnlyAdvises(): void
    {
        $b2b = $this->makeContact(['gstin' => $this->gstin('08'), 'state_code' => '08']);
        $id  = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $b2b, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'lines' => [$this->line('1000', '18', ['sac_code' => ''])]]);
        $this->assertSame('blocked', service('billing')->find($id)['tax_review_status']);

        try {
            service('billing')->finalize($id);
            $this->fail('B2B invoice without SAC must not finalize');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertStringContainsString('SAC', $e->getMessage());
        }

        $b2c = $this->makeContact(['state_code' => '08']);
        [$ok] = $this->issue('cash_memo', $b2c, [$this->line('1000', '18', ['sac_code' => ''])], 'cgst_sgst', '08');
        $this->assertSame('finalized', service('billing')->find($ok)['status']);
    }

    public function testBillingOperatorSeesOnlyOwnDocumentsAndCannotFinalizeOrDownload(): void
    {
        $c = $this->makeContact();
        // owner's own finalized invoice
        [$ownerDoc] = $this->issue('tax_invoice', $c, [$this->line()], 'cgst_sgst', '08');

        $op = $this->makeUser('billing_operator', 'Operator One');
        $this->loginAs($op);
        $mine = $this->draftViaHttp($c, '08', 'cgst_sgst', '5000');

        // record scope: owner's document is invisible (404, not 403, to avoid enumeration)
        $this->body('billing/documents/' . $mine);
        $list = $this->get('billing/documents');
        $list->assertOK();
        $ownerNumber = db_connect()->table('billing_documents')->where('id', $ownerDoc)->get()->getRow()->document_number;
        $list->assertDontSee($ownerNumber);
        $this->assertSame(404, $this->statusOf(fn () => $this->get('billing/documents/' . $ownerDoc)));
        $this->assertSame(404, $this->statusOf(fn () => $this->get('billing/documents/' . $ownerDoc . '/pdf')));

        // no revenue totals for this role
        $list->assertDontSee('Finalized value');

        // finalize is a separate permission (deny by default)
        $this->assertSame(403, $this->statusOf(fn () => $this->postForm('billing/documents/' . $mine . '/finalize', [])));
        $this->assertSame('draft', db_connect()->table('billing_documents')->where('id', $mine)->get()->getRow()->status);

        // can preview own draft PDF, but not export the register
        $this->body('billing/documents/' . $mine . '/pdf');
        $this->assertSame(403, $this->statusOf(fn () => $this->get('billing/documents/export?format=csv')));

        // once finalized by someone authorised, the operator cannot download it without the download/print permission
        $this->loginAs($this->owner);
        service('billing')->finalize($mine);
        $this->loginAs($op);
        $this->assertSame(403, $this->statusOf(fn () => $this->get('billing/documents/' . $mine . '/pdf')));

        // denied attempts are audited
        $this->assertGreaterThan(0, db_connect()->table('audit_logs')->where(['action' => 'access.denied', 'user_id' => $op->id])->countAllResults());
    }
}

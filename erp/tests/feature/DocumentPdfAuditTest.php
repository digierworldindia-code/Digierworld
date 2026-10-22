<?php

namespace Tests\Feature;

use Smalot\PdfParser\Parser;
use Tests\Support\DgrTestCase;

/**
 * Every printable document type through the real download route: a valid
 * A4 PDF (not an HTML error page), a safe filename, the title, number,
 * amount in words, bank details without CIF, logo/signature/stamp images,
 * long descriptions and many lines flowing onto further pages, and no PHP
 * warning text anywhere in the output.
 */
final class DocumentPdfAuditTest extends DgrTestCase
{
    private const TITLES = [
        'tax_invoice' => 'TAX INVOICE', 'bill_of_supply' => 'BILL OF SUPPLY', 'cash_memo' => 'CASH MEMO',
        'estimate' => 'ESTIMATE', 'quotation' => 'QUOTATION', 'proforma' => 'PROFORMA INVOICE',
        'credit_note' => 'CREDIT NOTE', 'debit_note' => 'DEBIT NOTE',
    ];

    private \CodeIgniter\Shield\Entities\User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompany('08');
        $this->owner = $this->makeUser('super_admin', 'Pdf Owner');
        $this->loginAs($this->owner);
        $db = db_connect();
        $db->table('bank_cash_accounts')->update(['is_default_on_invoice' => 0]);
        $db->table('bank_cash_accounts')->where('id', $this->makeBankAccount('Audit Current A/c'))->update(['is_default_on_invoice' => 1]);
        $types = array_keys(self::TITLES);
        foreach (['logo', 'signature', 'stamp'] as $asset) {
            $this->brandAsset($asset, (int) $this->owner->id, $types);
        }
        $db->table('signature_policies')->where('user_id', $this->owner->id)->delete();
        foreach ($types as $type) {
            $db->table('signature_policies')->insert(['doc_type' => $type, 'user_id' => $this->owner->id, 'allowed' => 1, 'approved_by' => $this->owner->id]);
        }
    }

    /** @return list<array<string, string>> */
    private function lines(int $count, string $rate = '18', string $treatment = 'regular'): array
    {
        $lines = [];
        for ($i = 1; $i <= $count; $i++) {
            $lines[] = $this->line((string) (1000 + $i), $rate, [
                'item_name'     => 'Service line ' . $i,
                'tax_treatment' => $treatment,
                'description'   => $i === 1
                    // longer than a page, with a line break and a token with no break opportunity
                    ? str_repeat('Monthly search engine optimisation, content calendar, backlink outreach and reporting. ', 60)
                        . "\nSecond paragraph after a line break.\nhttps://drive.example.test/file/d/" . str_repeat('AbCdEfGhIj', 15) . "/view\nEND-OF-LONG-DESCRIPTION"
                    : 'Deliverables for week ' . $i,
            ]);
        }

        return $lines;
    }

    /** @return array{bytes: string, text: string, disposition: string, pages: int, size: string, images: int, maxX: float} */
    private function download(int $id): array
    {
        $res = $this->get('billing/documents/' . $id . '/pdf?download=1');
        $res->assertOK();
        $res->assertHeader('Content-Type', 'application/pdf');
        $bytes = (string) $res->response()->getBody();
        $this->assertStringStartsWith('%PDF-', $bytes, 'the response is a PDF, not an error page');
        $this->assertStringContainsString('%%EOF', substr($bytes, -32));
        $file = tempnam(sys_get_temp_dir(), 'pdfaudit');
        file_put_contents($file, $bytes);
        if ($dir = getenv('DGR_EVIDENCE_DIR')) {
            copy($file, $dir . '/audit-' . $id . '.pdf');
        }
        $info = [];
        if (is_executable('/usr/bin/pdfinfo')) {
            exec('/usr/bin/pdfinfo ' . escapeshellarg($file), $info);
        }
        $maxX = 0.0; // right edge of the right-most word, in points (A4 is 595.28 wide, 12 mm margins)
        if (is_executable('/usr/bin/pdftotext')) {
            exec('/usr/bin/pdftotext -bbox ' . escapeshellarg($file) . ' -', $words);
            foreach ($words as $w) {
                if (preg_match('/<word xMin="[\d.]+" yMin="[\d.]+" xMax="([\d.]+)"/', $w, $m)) {
                    $maxX = max($maxX, (float) $m[1]);
                }
            }
        }
        $images = [];
        if (is_executable('/usr/bin/pdfimages')) {
            exec('/usr/bin/pdfimages -list ' . escapeshellarg($file), $images);
        }
        unlink($file);
        $meta = [];
        foreach ($info as $row) {
            [$k, $v] = array_map('trim', explode(':', $row, 2)) + [1 => ''];
            $meta[$k] = $v;
        }

        return [
            'bytes'       => $bytes,
            'text'        => preg_replace('/\s+/u', ' ', (new Parser())->parseContent($bytes)->getText()),
            'disposition' => $res->response()->getHeaderLine('Content-Disposition'),
            'pages'       => (int) ($meta['Pages'] ?? 0),
            'size'        => (string) ($meta['Page size'] ?? ''),
            'images'      => max(0, count($images) - 2), // two header rows
            'maxX'        => $maxX,
        ];
    }

    private function assertCleanPdf(array $pdf, string $docType, string $number, bool $signed): void
    {
        $title = self::TITLES[$docType];
        $this->assertStringContainsString($title, $pdf['text'], $docType . ' title');
        $this->assertStringContainsString($number, $pdf['text']);
        $this->assertMatchesRegularExpression('/^attachment; filename="' . preg_quote(str_replace(' ', '_', doc_type_label($docType)), '/') . '_[A-Za-z0-9_\-]+\.pdf"$/', $pdf['disposition']);
        $this->assertStringContainsString('Rupees', $pdf['text'], 'amount in words');
        $this->assertStringContainsString('Service line 30', $pdf['text'], 'the last line is printed');
        $this->assertStringContainsString('Second paragraph after a line break', $pdf['text'], 'long descriptions are printed in full');
        $this->assertStringContainsString('END-OF-LONG-DESCRIPTION', $pdf['text'], 'a description longer than a page is not cut off');
        // (one sentence may straddle a page break, with the page footer and repeated column headings in between)
        foreach (['Monthly', 'optimisation', 'calendar', 'outreach', 'reporting.'] as $word) {
            $this->assertSame(60, substr_count($pdf['text'], $word), "every sentence of the long description is printed ({$word})");
        }
        $this->assertLessThanOrEqual(562.0, $pdf['maxX'], 'no text runs into the right margin or off the page');
        $this->assertStringContainsString('Page 1 of', $pdf['text']);
        $this->assertStringNotContainsString('CIF998877', $pdf['text'], 'company CIF never appears in PDFs');
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Undefined', 'Stack trace', '<br'] as $leak) {
            $this->assertStringNotContainsString($leak, $pdf['text'], 'no PHP error text in the PDF');
        }
        if (is_executable('/usr/bin/pdfinfo')) {
            $this->assertGreaterThan(1, $pdf['pages'], '30 lines flow onto a second page');
            $this->assertStringContainsString('595.28 x 841.89', $pdf['size'], 'A4');
        }
        if (is_executable('/usr/bin/pdfimages')) {
            // logo on every type; signature + stamp when the document was signed
            $this->assertGreaterThanOrEqual($signed ? 3 : 1, $pdf['images'], 'branding images are embedded');
        }
    }

    public function testEveryDocumentTypeDownloadsAsACompleteA4Pdf(): void
    {
        $c = $this->makeContact(['name' => 'Audit Customer Pvt Ltd', 'gstin' => $this->gstin('08'), 'state_code' => '08']);
        $invoiceId = null;
        foreach (['tax_invoice', 'cash_memo', 'bill_of_supply', 'estimate', 'quotation', 'proforma'] as $type) {
            $bos = $type === 'bill_of_supply';
            $extra = ['terms' => str_repeat('Payment is due within 15 days of the invoice date. ', 40) . 'END-OF-TERMS', 'notes' => 'Thank you for your business.'];
            [$id, $number] = $this->issue($type, $c, $this->lines(30, $bos ? '0' : '18', $bos ? 'exempt' : 'regular'), $bos ? 'none' : 'cgst_sgst', '08', $extra, ['apply_signature' => true, 'tax_review_reason' => 'Exempt service checked by the accountant']);
            $invoiceId ??= $id;
            $pdf = $this->download($id);
            $this->assertCleanPdf($pdf, $type, $number, true);
            $this->assertStringContainsString('END-OF-TERMS', $pdf['text'], 'long terms are printed in full');
            if (in_array($type, ['tax_invoice', 'cash_memo', 'proforma'], true)) {
                $this->assertStringContainsString('123456789012', $pdf['text'], 'bank account printed for payment');
                $this->assertStringContainsString('HDFC0001234', $pdf['text']);
            }
        }

        foreach (['credit_note', 'debit_note'] as $type) {
            $id = service('billing')->duplicate($invoiceId, $type);
            $db = db_connect();
            $db->table('billing_line_items')->where('document_id', $id)->where('line_no >', 1)->update(['unit_rate' => '1.00']);
            service('billing')->saveDraft(['doc_type' => $type, 'contact_id' => $c, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'original_document_id' => $invoiceId,
                'lines' => array_map(static fn (array $l) => ['unit_rate' => '10'] + $l, $this->lines(30))], $id);
            $res = service('billing')->finalize($id, ['apply_signature' => true, 'reason_code' => 'price_revision', 'reason_text' => 'Rate revised after review']);
            $pdf = $this->download($id);
            $this->assertCleanPdf($pdf, $type, $res['document_number'], true);
            $original = $db->table('billing_documents')->where('id', $invoiceId)->get()->getRow()->document_number;
            $this->assertStringContainsString($original, $pdf['text'], 'notes name the original invoice');
        }
    }

    public function testDraftPreviewAndCancelledReprintAreMarkedAndUnsignedDocumentsSaySo(): void
    {
        $c  = $this->makeContact(['state_code' => '08']);
        $id = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $c, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'lines' => $this->lines(30)]);
        $draft = $this->get('billing/documents/' . $id . '/pdf');
        $draft->assertOK();
        $this->assertStringContainsString('inline', $draft->response()->getHeaderLine('Content-Disposition'));
        $text = (new Parser())->parseContent((string) $draft->response()->getBody())->getText();
        $this->assertStringContainsString('DRAFT', $text);

        $res = service('billing')->finalize($id);
        $pdf = $this->download($id);
        $this->assertCleanPdf($pdf, 'tax_invoice', $res['document_number'], false);
        $this->assertStringContainsString('signature not applied', $pdf['text']);

        service('billing')->cancel($id, 'Raised against the wrong customer');
        $this->assertStringContainsString('CANCELLED', $this->download($id)['text']);
    }

    public function testReceiptVoucherPdfDownloads(): void
    {
        $c = $this->makeContact(['state_code' => '08']);
        [$inv] = $this->issue('tax_invoice', $c, [$this->line('5000')], 'cgst_sgst', '08');
        $rid = service('receipts')->create([
            'contact_id' => $c, 'receipt_date' => date('Y-m-d'), 'amount' => '5900.00', 'mode' => 'cash', 'bank_cash_account_id' => $this->cashAccountId(), 'allow_duplicate' => true,
            'allocations' => [['document_id' => $inv, 'amount' => '5900.00']],
        ]);
        $res = $this->get('finance/receipts/' . $rid . '/pdf?download=1');
        $res->assertOK();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertMatchesRegularExpression('/^attachment; filename="Receipt_[A-Za-z0-9_\-]+\.pdf"$/', $res->response()->getHeaderLine('Content-Disposition'));
        $text = (new Parser())->parseContent((string) $res->response()->getBody())->getText();
        $this->assertStringContainsString('Five Thousand Nine Hundred', $text);
    }
}

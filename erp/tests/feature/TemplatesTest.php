<?php

namespace Tests\Feature;

use Smalot\PdfParser\Parser;
use Tests\Support\DgrTestCase;

/** Template settings are versioned; finalized documents keep the version they were issued with. */
final class TemplatesTest extends DgrTestCase
{
    public function testSaveBumpsVersionAndPreviewRendersSample(): void
    {
        $this->setupCompany('08');
        $this->loginAs($this->makeUser('super_admin'));
        $tpl = db_connect()->table('document_templates')->where('code', 'classic')->get()->getRowArray();

        // issue an invoice on this template, then change the template
        $c = $this->makeContact();
        [$inv] = $this->issue('tax_invoice', $c, [$this->line('1000')], 'cgst_sgst', '08', ['template_id' => $tpl['id']]);
        $snapBefore = json_decode(db_connect()->table('billing_document_snapshots')->where('document_id', $inv)->get()->getRow()->template, true);

        $this->get('settings/templates')->assertSee('Classic Professional');
        $this->postForm('settings/templates/' . $tpl['id'], ['accent' => '#123456', 'font_size' => '10', 'margin_mm' => '14', 'paper' => 'A4', 'logo_position' => 'top_center',
            'footer_note' => 'Thank you for your business', 'show_logo' => '1', 'show_bank' => '1', 'show_tax_summary' => '1', 'show_amount_words' => '1', 'is_active' => '1', 'make_default' => '1'])->assertRedirect();
        $after = db_connect()->table('document_templates')->where('id', $tpl['id'])->get()->getRowArray();
        $this->assertSame((int) $tpl['version'] + 1, (int) $after['version']);
        $this->assertSame('#123456', json_decode($after['settings'], true)['accent']);
        $this->assertSame((int) $tpl['id'], (int) db_connect()->table('company_settings')->where('id', 1)->get()->getRow()->default_template_id);

        // the issued invoice's snapshot still carries the old version/settings
        $snapAfter = json_decode(db_connect()->table('billing_document_snapshots')->where('document_id', $inv)->get()->getRow()->template, true);
        $this->assertSame($snapBefore, $snapAfter);
        $this->assertSame((int) $tpl['version'], (int) $snapAfter['version']);

        // invalid colour refused
        $this->postForm('settings/templates/' . $tpl['id'], ['accent' => 'red'])->assertRedirect();
        $this->assertSame((int) $after['version'], (int) db_connect()->table('document_templates')->where('id', $tpl['id'])->get()->getRow()->version);

        $pdf = (string) $this->get('settings/templates/' . $tpl['id'] . '/preview?gst=igst')->response()->getBody();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $text = (new Parser())->parseContent($pdf)->getText();
        $this->assertStringContainsString('SAMPLE', $text);
        $this->assertStringContainsString('IGST', $text);
        $this->assertStringContainsString('DRAFT', $text, 'Previews are watermarked, never a valid document');
        $this->assertSame(0, db_connect()->table('billing_documents')->where('document_number', 'SAMPLE-001')->countAllResults(), 'Preview stores nothing');
    }

    public function testTemplatesNeedPermission(): void
    {
        $this->loginAs($this->makeUser('billing_operator'));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('settings/templates')));
    }
}

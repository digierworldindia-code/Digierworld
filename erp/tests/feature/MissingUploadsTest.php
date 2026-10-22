<?php

namespace Tests\Feature;

use App\Services\Admin\Diagnostics;
use App\Services\Billing\SnapshotBuilder;
use Tests\Support\DgrTestCase;

/**
 * The database can list uploaded files whose contents are gone: writable/
 * replaced on an upgrade, a restore without the uploads backup, or the demo
 * SQL imported into another install. The app must keep working, never show
 * a broken logo, and say exactly what is missing and how to fix it.
 */
final class MissingUploadsTest extends DgrTestCase
{
    private function brand(string $type, int $ownerId): int
    {
        $fid = service('files')->storeContents((string) file_get_contents(ROOTPATH . '../assets/images/logo.png'), $type . '.png', 'image/png', $type);
        db_connect()->table('branding_assets')->insert([
            'asset_type' => $type, 'version' => 1, 'stored_file_id' => $fid, 'position_preset' => $type === 'logo' ? 'top_left' : 'bottom_right',
            'display_width_mm' => 40, 'enabled_doc_types' => json_encode(['tax_invoice']), 'is_active' => 1, 'created_by' => $ownerId,
        ]);

        return $fid;
    }

    private function deleteFile(int $fileId): void
    {
        unlink(service('files')->path(service('files')->find($fileId)));
    }

    /** @return array<string, array{0: string, 1: string}> diagnostics rows by label */
    private function diagnostics(): array
    {
        $rows = [];
        foreach ((new Diagnostics(db_connect()))->run() as [, $label, $status, $detail]) {
            $rows[$label] = [$status, $detail];
        }

        return $rows;
    }

    public function testLostLogoAndSignatureFilesAreReportedAndNothingBreaks(): void
    {
        $this->setupCompany('08');
        $owner = $this->makeUser('super_admin', 'Upload Owner');
        $this->loginAs($owner);
        $logo = $this->brand('logo', (int) $owner->id);
        $sig  = $this->brand('signature', (int) $owner->id);
        db_connect()->table('signature_policies')->insert(['doc_type' => 'tax_invoice', 'user_id' => $owner->id, 'allowed' => 1, 'approved_by' => $owner->id]);
        $contact = $this->makeContact();

        // files present: logo shown and served, signing offered, invoice snapshot carries both images
        $this->assertSame('ok', brand_logo_state());
        $this->assertStringContainsString('brand/logo', (string) $this->get('/')->response()->getBody());
        $this->get('brand/logo')->assertOK();
        $this->assertTrue(service('billing')->canSign('tax_invoice', (int) $owner->id));
        [$before] = $this->issue('tax_invoice', $contact, [$this->line()], 'cgst_sgst', '08', [], ['apply_signature' => 1]);
        $snap = (new SnapshotBuilder(db_connect()))->load($before);
        $this->assertSame($logo, $snap['branding']['logo']['file_id']);
        $this->assertSame($sig, $snap['branding']['signature']['file_id']);
        $this->assertSame('ok', $this->diagnostics()['Active logo/signature/stamp files'][0]);

        // writable/uploads lost (e.g. the whole folder replaced on an upgrade)
        $this->deleteFile($logo);
        $this->deleteFile($sig);

        $this->assertSame('missing', brand_logo_state());
        $this->assertSame(404, $this->get('brand/logo')->response()->getStatusCode());
        $dashboard = (string) $this->get('/')->response()->getBody();
        $this->assertStringNotContainsString('brand/logo', $dashboard, 'no broken logo image in the layout');
        $this->assertStringContainsString('Official logo file is missing on the server', $dashboard);
        $this->get('settings/branding')->assertSee('missing on the server');
        $this->get('settings/setup')->assertSee('The logo file is missing on the server');

        // the invoice issued with them still downloads (without the lost images) instead of failing
        $res = $this->get('billing/documents/' . $before . '/pdf');
        $res->assertOK();
        $this->assertStringStartsWith('%PDF-', (string) $res->response()->getBody());

        // signing is no longer offered and a new invoice does not reference the lost files
        $this->assertFalse(service('billing')->canSign('tax_invoice', (int) $owner->id));
        [$after] = $this->issue('tax_invoice', $contact, [$this->line()], 'cgst_sgst', '08');
        $snap = (new SnapshotBuilder(db_connect()))->load($after);
        $this->assertNull($snap['branding']['logo']);

        $diag = $this->diagnostics();
        $this->assertSame('fail', $diag['Active logo/signature/stamp files'][0]);
        $this->assertStringContainsString('logo, signature', $diag['Active logo/signature/stamp files'][1]);
        $this->assertSame('warn', $diag['Uploaded and generated files'][0]);
    }
}

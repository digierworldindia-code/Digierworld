<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Seeds\SupplierMasterFieldSeeder;
use App\Models\ImportJobModel;
use Tests\Support\BCTestCase;

/**
 * Workbook tab "Suppiler list": a file with all 86 real column headings is
 * mapped automatically, imported through the admin screens, every column is
 * preserved, native fields are filled and the bank account is encrypted.
 */
final class SupplierMasterSheetImportTest extends BCTestCase
{
    public function testAllEightySixSheetColumnsImportAndBankAccountIsEncrypted(): void
    {
        $headings = [];
        foreach (SupplierMasterFieldSeeder::fields() as $group) {
            foreach ($group as [$h]) {
                $headings[] = $h;
            }
        }
        $this->assertCount(86, $headings);
        $this->assertCount(86, service('supplierMaster')->fields(), 'only the sheet headings are active');

        $values = array_map(static fn ($h) => 'v-' . substr(md5($h), 0, 6), $headings);
        $set    = static function (string $h, string $v) use (&$values, $headings): void {
            $values[array_search($h, $headings, true)] = $v;
        };
        $set('Company Name', '[SAMPLE] Sheet Import Bearings Pvt Ltd');
        $set('Country', 'India');
        $set('Email', 'sheet.import@example.com');
        $set('GST Registration', '27AAAAA0000A1Z5');
        $set('Bank account', '123456789012');
        $set('Manufacturing Catagory', 'Ball bearings; Tapered roller bearings');

        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $headings);
        fputcsv($fh, $values);
        rewind($fh);
        $file = $this->tempFile('supplier-list.csv', stream_get_contents($fh));

        $admin = $this->staff('superadmin');
        $this->actingAs($admin);
        $job = service('imports')->upload($file, 'supplier_master', null, (int) $admin->id);
        $fields = [];
        foreach (service('supplierMaster')->fields() as $f) {
            $fields[$f['field_key']] = [$f['source_heading']];
        }
        $map = service('imports')->autoMap(json_decode($job['headers'], true), $fields);
        $this->assertCount(86, $map, 'every sheet heading maps automatically');

        $this->postAs($admin, 'admin/supplier-master/' . $job['uuid'] . '/map', ['map' => $map])->assertRedirect();
        $job = model(ImportJobModel::class)->find($job['id']);
        $this->assertSame(1, (int) $job['valid_rows']);
        $rowData = (string) db_connect()->table('import_job_rows')->where('import_job_id', $job['id'])->get()->getRow()->data;
        $this->assertStringNotContainsString('123456789012', $rowData, 'bank account never stored in plain text in import rows');

        $this->postAs($admin, 'admin/supplier-master/' . $job['uuid'] . '/confirm')->assertRedirect();
        $db = db_connect();
        $co = $db->table('companies')->where('email', 'sheet.import@example.com')->get()->getRowArray();
        $this->assertSame('[SAMPLE] Sheet Import Bearings Pvt Ltd', $co['legal_name']);
        $this->assertSame('IN', $co['country_code']);
        $this->assertSame('Ball bearings; Tapered roller bearings', $db->table('supplier_profiles')->where('company_id', $co['id'])->get()->getRow()->main_categories);

        $kyc = $db->table('kyc_records')->where('company_id', $co['id'])->get()->getRowArray();
        $this->assertSame('27AAAAA0000A1Z5', $kyc['gst_number']);
        $this->assertSame('123456789012', service('encrypter')->decrypt(base64_decode($kyc['bank_account_number_enc'], true)));
        $this->assertSame('9012', $kyc['bank_account_last4']);

        $attrs = array_column($db->table('supplier_master_attributes')->where('company_id', $co['id'])->get()->getResultArray(), 'value', 'field_key');
        $this->assertCount(86, $attrs, 'all 86 source columns preserved');
        $this->assertSame('••••••••9012', $attrs['bank_account']);
        $this->assertSame($values[array_search('Labor law compliance', $headings, true)], $attrs['labor_law_compliance']);

        $this->getAs($admin, 'admin/supplier-master')->assertSee('Manufacturing Catagory');
    }
}

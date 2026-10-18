<?php

namespace Tests\Feature;

use App\Libraries\BankStatementParser;
use Tests\Support\DgrTestCase;

/**
 * The files shipped in docs/samples must import cleanly through the real
 * importers (preview → commit), and a second import must be caught as
 * duplicates.
 */
final class SampleImportFilesTest extends DgrTestCase
{
    private const DIR = ROOTPATH . 'docs/samples/';

    private function admin(): \CodeIgniter\Shield\Entities\User
    {
        $u = $this->makeUser('super_admin', 'Import Tester');
        db_connect()->table('user_profiles')->where('user_id', $u->id)->update(['must_change_password' => 0]);
        $this->loginAs($u);

        return $u;
    }

    private function stored(string $name, string $category, ?string $entity = null, ?int $entityId = null): int
    {
        return service('files')->storeContents((string) file_get_contents(self::DIR . $name), $name, 'text/csv', $category, $entity, $entityId);
    }

    public function testContactsSampleImportsWithoutErrors(): void
    {
        $db = db_connect();
        $db->table('contacts')->like('name', 'SAMPLE ', 'after')->where('deleted_at', null)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->admin();

        $fid = $this->stored('contacts-import-sample.csv', 'import');
        $this->postForm('contacts/import/commit', ['file_id' => (string) $fid])->assertRedirectTo(site_url('contacts'));
        $rows = $db->table('contacts')->like('name', 'SAMPLE ', 'after')->where('deleted_at', null)->get()->getResultArray();
        $this->assertCount(4, $rows);
        $byName = array_column($rows, null, 'name');
        $this->assertSame('27', $byName['SAMPLE Northwind Traders Pvt Ltd']['state_code'], 'state derived from GSTIN');
        $this->assertSame(1, (int) $byName['SAMPLE Quickprint Studio']['is_vendor']);
        $this->assertSame(1, (int) $byName['SAMPLE Bluebird Cafe']['is_prospect']);

        // the same file again: every row is reported as a duplicate, nothing new is created
        $fid2 = $this->stored('contacts-import-sample.csv', 'import');
        $this->postForm('contacts/import/commit', ['file_id' => (string) $fid2]);
        $this->assertSame(4, $db->table('contacts')->like('name', 'SAMPLE ', 'after')->where('deleted_at', null)->countAllResults());
    }

    public function testLeadsSampleImportsWithoutErrors(): void
    {
        $db = db_connect();
        $db->table('leads')->like('company_name', 'SAMPLE ', 'after')->where('deleted_at', null)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $u = $this->admin();

        $fid = $this->stored('crm-leads-import-sample.csv', 'import');
        $review = $this->get('crm/leads/import/review?file=' . $fid);
        $review->assertOK();
        $review->assertSee('SAMPLE Green Leaf Salon');
        $review->assertDontSee('Missing or invalid mobile');
        $this->postForm('crm/leads/import/commit', ['file' => (string) $fid, 'assign_to' => (string) $u->id])->assertRedirect();
        $leads = $db->table('leads')->like('company_name', 'SAMPLE ', 'after')->where('deleted_at', null)->get()->getResultArray();
        $this->assertCount(4, $leads);
        $apex = array_column($leads, null, 'company_name')['SAMPLE Apex Coaching Centre'];
        $this->assertSame(['Website Development', 'SEO'], json_decode($apex['services_interested'], true));
        $this->assertNotNull($apex['source_id'], 'source name matched the seeded lead source');
        $this->assertSame((int) $u->id, (int) $apex['assigned_to']);

        $fid2 = $this->stored('crm-leads-import-sample.csv', 'import');
        $this->get('crm/leads/import/review?file=' . $fid2)->assertSee('Already in CRM as');
        $this->postForm('crm/leads/import/commit', ['file' => (string) $fid2]);
        $this->assertSame(4, $db->table('leads')->like('company_name', 'SAMPLE ', 'after')->where('deleted_at', null)->countAllResults());
    }

    public function testBankStatementSampleIsDetectedAndImported(): void
    {
        $this->admin();
        $acc = $this->makeBankAccount('Sample Import A/c');
        $fid = $this->stored('bank-statement-sample.csv', 'bank_statement', 'bank_cash_account', $acc);

        $rows   = BankStatementParser::readRaw(service('files')->path(service('files')->find($fid)), 'bank-statement-sample.csv');
        $header = BankStatementParser::detectHeader($rows);
        $map    = BankStatementParser::guessMapping($rows[$header]);
        foreach (['txn_date', 'value_date', 'description', 'reference', 'debit', 'credit', 'balance'] as $f) {
            $this->assertArrayHasKey($f, $map, $f . ' column detected');
        }
        $this->assertSame(3, $header, 'header row found below the preamble (blank rows are dropped)');
        $parsed = BankStatementParser::parse($rows, $header, $map);
        $this->assertSame([], $parsed['errors']);
        $this->assertCount(7, $parsed['lines']);

        $mapPage = $this->get('finance/reconciliation/map?file=' . $fid . '&account=' . $acc);
        $mapPage->assertOK();
        $mapPage->assertSee('NEFT CR-SAMPLE CLIENT ONE-INV 121');
        $post = ['file' => (string) $fid, 'account_id' => (string) $acc, 'header' => (string) $header];
        foreach (array_keys(BankStatementParser::FIELDS) as $f) {
            $post['map_' . $f] = isset($map[$f]) ? (string) $map[$f] : '';
        }
        $this->postForm('finance/reconciliation/import/commit', $post)->assertRedirect();
        $lines = db_connect()->table('bank_import_lines')->where('bank_cash_account_id', $acc)->get()->getResultArray();
        $this->assertCount(7, $lines);
        $this->assertSame('61300.00', number_format(array_sum(array_column($lines, 'credit')), 2, '.', ''));
        $this->assertSame('17596.00', number_format(array_sum(array_column($lines, 'debit')), 2, '.', ''));

        // re-importing the same statement adds nothing
        $this->postForm('finance/reconciliation/import/commit', $post);
        $this->assertSame(7, db_connect()->table('bank_import_lines')->where('bank_cash_account_id', $acc)->countAllResults());
    }
}

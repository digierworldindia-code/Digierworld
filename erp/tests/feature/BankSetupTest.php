<?php

namespace Tests\Feature;

use App\Services\Settings\SettingsStore;
use Smalot\PdfParser\Parser;
use Tests\Support\DgrTestCase;

/**
 * Settings > Bank & payment profiles through the real screens: the edit
 * form is prefilled, changes persist across a reload, one default at a
 * time, deactivation, masking and CIF confidentiality, audit without
 * secrets, and which bank details invoices print (new and draft documents
 * use the current details; issued documents keep what they were issued with).
 */
final class BankSetupTest extends DgrTestCase
{
    private const CIF = 'CIF-77001234';

    private \CodeIgniter\Shield\Entities\User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompany('08');
        $this->owner = $this->makeUser('super_admin', 'Bank Owner');
        $this->loginAs($this->owner);
        db_connect()->table('bank_cash_accounts')->update(['is_default_on_invoice' => 0]);
    }

    /** @return array<string, string> */
    private function form(array $over = []): array
    {
        return $over + [
            'display_name' => 'ICICI Current A/c', 'account_kind' => 'bank', 'ownership' => 'company', 'bank_name' => 'ICICI Bank',
            'beneficiary_name' => 'TEST COMPANY', 'account_number' => '000405001234', 'ifsc' => 'ICIC0000004', 'account_type' => 'current',
            'branch' => 'Bhiwadi', 'upi_id' => '', 'cif' => '', 'show_on_invoice' => '1', 'is_default_on_invoice' => '1',
            'display_priority' => '10', 'opening_balance' => '0', 'opening_date' => '', 'is_active' => '1',
        ];
    }

    private function create(array $over = []): int
    {
        $this->postForm('settings/banks', $this->form($over))->assertRedirectTo(site_url('settings/banks'));

        return (int) db_connect()->table('bank_cash_accounts')->selectMax('id', 'm')->get()->getRow()->m;
    }

    private function row(int $id): array
    {
        return db_connect()->table('bank_cash_accounts')->where('id', $id)->get()->getRowArray();
    }

    public function testEditFormIsPrefilledAndChangesPersistAfterReload(): void
    {
        $id = $this->create(['cif' => self::CIF]);
        $this->assertSame('1234', $this->row($id)['account_last4']);

        $page = $this->get('settings/banks/' . $id);
        $page->assertOK();
        foreach (['ICICI Current A/c', 'ICICI Bank', 'TEST COMPANY', 'ICIC0000004', 'Bhiwadi'] as $value) {
            $page->assertSee($value);
        }
        $html = (string) $page->response()->getBody();
        $this->assertStringNotContainsString('000405001234', $html, 'the full account number is never put back in the form');
        $this->assertStringNotContainsString(self::CIF, $html, 'CIF is never shown in the form');

        // change details, leave account number and CIF blank (= keep)
        $this->postForm('settings/banks/' . $id, $this->form(['bank_name' => 'ICICI Bank Ltd', 'branch' => 'Alwar Road', 'ifsc' => 'icic0001111', 'beneficiary_name' => 'TEST COMPANY PVT LTD',
            'upi_id' => 'testco@icici', 'account_number' => '', 'cif' => '']))->assertRedirectTo(site_url('settings/banks'));
        $row = $this->row($id);
        $this->assertSame(['ICICI Bank Ltd', 'Alwar Road', 'ICIC0001111', 'TEST COMPANY PVT LTD', 'testco@icici', '1'], [$row['bank_name'], $row['branch'], $row['ifsc'], $row['beneficiary_name'], $row['upi_id'], (string) $row['is_active']]);
        $this->assertSame('000405001234', SettingsStore::decrypt($row['account_number_enc']), 'blank keeps the stored number');
        $this->assertSame(self::CIF, SettingsStore::decrypt($row['cif_enc']), 'blank keeps the stored CIF');

        $reload = $this->get('settings/banks/' . $id);
        $reload->assertSee('ICICI Bank Ltd');
        $reload->assertSee('Alwar Road');
        $reload->assertSee('ICIC0001111');
        $reload->assertSee('testco@icici');

        // a new number and CIF replace the old ones, encrypted
        $this->postForm('settings/banks/' . $id, $this->form(['account_number' => '0004 0500 9999', 'cif' => 'CIF-NEW-5555']));
        $row = $this->row($id);
        $this->assertSame('9999', $row['account_last4']);
        $this->assertSame('000405009999', SettingsStore::decrypt($row['account_number_enc']));
        $this->assertStringNotContainsString('CIF-NEW-5555', $row['cif_enc']);
        $this->assertStringNotContainsString('CIF-NEW-5555', (string) $this->get('settings/banks')->response()->getBody());

        // every change is audited, without the secrets
        $audit = db_connect()->table('audit_logs')->where(['entity_type' => 'bank_cash_account', 'entity_id' => $id])->get()->getResultArray();
        $this->assertSame(['bank_account.created', 'bank_account.updated', 'bank_account.updated'], array_column($audit, 'action'));
        $dump = json_encode($audit);
        foreach (['000405001234', '000405009999', self::CIF, 'CIF-NEW-5555', $row['account_number_enc'], $row['cif_enc']] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, 'audit log holds no bank secrets');
        }
        $this->assertStringContainsString('Alwar Road', $dump, 'the audit shows what changed');
    }

    public function testOneDefaultAtATimeAndDeactivatingClearsDefault(): void
    {
        $name = 'Axis Current A/c ' . bin2hex(random_bytes(2)); // accounts from other tests stay in the test database
        $a    = $this->create(['display_name' => $name]);
        $b    = $this->create(['display_name' => 'HDFC Current A/c', 'bank_name' => 'HDFC Bank', 'ifsc' => 'HDFC0000001', 'account_number' => '50200011112222']);
        $this->assertSame([0, 1], [(int) $this->row($a)['is_default_on_invoice'], (int) $this->row($b)['is_default_on_invoice']]);

        $this->postForm('settings/banks/' . $a, $this->form(['display_name' => $name, 'account_number' => '']));
        $this->assertSame([1, 0], [(int) $this->row($a)['is_default_on_invoice'], (int) $this->row($b)['is_default_on_invoice']]);

        // deactivate the default: it stops being the default and is not offered on invoices
        $this->postForm('settings/banks/' . $a, array_diff_key($this->form(['display_name' => $name, 'account_number' => '']), ['is_active' => 1]));
        $row = $this->row($a);
        $this->assertSame([0, 0], [(int) $row['is_active'], (int) $row['is_default_on_invoice']]);
        $this->get('billing/documents/new?type=tax_invoice')->assertDontSee($name);

        // reactivate
        $this->postForm('settings/banks/' . $a, $this->form(['display_name' => $name, 'account_number' => '', 'is_default_on_invoice' => '']));
        $this->assertSame(1, (int) $this->row($a)['is_active']);
        $this->get('billing/documents/new?type=tax_invoice')->assertSee($name);
    }

    public function testInvoicesPrintCurrentDetailsAndIssuedOnesKeepTheirs(): void
    {
        $bank = $this->create();
        $c    = $this->makeContact(['state_code' => '08']);
        [$issued] = $this->issue('tax_invoice', $c, [$this->line()], 'cgst_sgst', '08');
        $draft = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $c, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'lines' => [$this->line()]]);
        $this->assertSame($bank, (int) service('billing')->find($issued)['bank_account_id'], 'new invoices take the default bank');

        $this->postForm('settings/banks/' . $bank, $this->form(['account_number' => '000405007777', 'ifsc' => 'ICIC0002222', 'branch' => 'Jaipur']));

        $pdf = fn (int $id) => (new Parser())->parseContent((string) $this->get('billing/documents/' . $id . '/pdf')->response()->getBody())->getText();
        $old = $pdf($issued);
        $this->assertStringContainsString('ICIC0000004', $old, 'an issued invoice reprints with the details it was issued with');
        $this->assertStringContainsString('000405001234', $old);
        $this->assertStringNotContainsString('ICIC0002222', $old);

        $live = $pdf($draft);
        $this->assertStringContainsString('ICIC0002222', $live, 'a draft shows the current details');
        $this->assertStringContainsString('000405007777', $live);

        [$next] = $this->issue('tax_invoice', $c, [$this->line()], 'cgst_sgst', '08');
        $snap = json_decode(db_connect()->table('billing_document_snapshots')->where('document_id', $next)->get()->getRow()->bank, true);
        $this->assertSame(['ICIC0002222', '000405007777'], [$snap['ifsc'], $snap['account_number']], 'invoices issued after the change use the new details');
        $this->assertArrayNotHasKey('cif', $snap);
    }

    public function testInvalidInputIsExplainedAndSecretsAreNotKeptInTheSession(): void
    {
        $id = $this->create();
        $res = $this->postForm('settings/banks/' . $id, $this->form(['bank_name' => str_repeat('B', 200), 'display_priority' => '999999', 'account_number' => '000405008888', 'cif' => 'CIF-SESSION-1']));
        $res->assertRedirect();
        $errors = session('errors') ?? [];
        $this->assertArrayHasKey('bank_name', $errors);
        $this->assertArrayHasKey('display_priority', $errors);
        $this->assertSame('ICICI Bank', $this->row($id)['bank_name'], 'nothing saved');
        $old = json_encode($_SESSION['_ci_old_input'] ?? []);
        $this->assertStringContainsString(str_repeat('B', 200), $old, 'typed values come back to the form');
        $this->assertStringNotContainsString('000405008888', $old, 'the account number is not kept in the session');
        $this->assertStringNotContainsString('CIF-SESSION-1', $old, 'the CIF is not kept in the session');

        $this->postForm('settings/banks/' . $id, $this->form(['opening_balance' => '12abc', 'opening_date' => '31-02-2026']))->assertRedirect();
        $errors = session('errors') ?? [];
        $this->assertArrayHasKey('opening_balance', $errors);
        $this->assertArrayHasKey('opening_date', $errors);
    }

    public function testOpeningBalanceIsNotRepostedOnLaterEdits(): void
    {
        $id = $this->create(['opening_balance' => '50,000.00', 'opening_date' => '']);
        $db = db_connect();
        $journals = fn () => $db->table('journal_entries')->where(['source_type' => 'opening_balance', 'source_id' => $id])->countAllResults();
        $this->assertSame(1, $journals());
        $row = $this->row($id);
        $this->assertNotNull($row['opening_date'], 'an opening balance always gets a fixed date');

        // a later edit (the opening entry is from an earlier day) changes nothing in the ledger
        $db->table('journal_entries')->where(['source_type' => 'opening_balance', 'source_id' => $id])->update(['entry_date' => '2026-04-01']);
        $db->table('bank_cash_accounts')->where('id', $id)->update(['opening_date' => '2026-04-01']);
        $this->postForm('settings/banks/' . $id, $this->form(['account_number' => '', 'branch' => 'Changed branch', 'opening_balance' => '50000', 'opening_date' => '2026-04-01']));
        $this->assertSame(1, $journals(), 'no reversal and re-post when the opening balance did not change');
        $this->assertSame('50000.00', $this->bankBalance($id));
    }

    public function testOnlyAuthorisedUsersCanChangeBanks(): void
    {
        $id = $this->create();
        $this->loginAs($this->makeUser('telecaller', 'No Bank Access'));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('settings/banks/' . $id)));
        $this->assertSame(403, $this->statusOf(fn () => $this->postForm('settings/banks/' . $id, $this->form(['bank_name' => 'Hijacked']))));
        $this->assertSame('ICICI Bank', $this->row($id)['bank_name']);
    }
}

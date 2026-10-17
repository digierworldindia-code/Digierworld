<?php

namespace Tests\Support;

use App\Database\Seeds\DatabaseSeeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Base for integration/feature tests against the disposable MySQL test DB.
 * Migrations (Shield + Settings + App) and base seeds run once per test run.
 */
abstract class DgrTestCase extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = null;
    protected $seed        = DatabaseSeeder::class;
    protected $seedOnce    = true;
    protected $DBGroup     = 'tests';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        service('authz')->forget();
        \Config\Services::resetSingle('authz');
        \Config\Services::resetSingle('billingAccess');
        \Config\Services::resetSingle('leadAccess');
        \Config\Services::resetSingle('candidateAccess');
    }

    /** Create an active user holding the given role codes. */
    protected function makeUser(string|array $roles, string $name = ''): User
    {
        self::$seq++;
        $email = 'test' . self::$seq . '_' . bin2hex(random_bytes(3)) . '@example.test';
        $id    = service('users')->create($email, $name ?: 'Test User ' . self::$seq, 'Zx9-' . bin2hex(random_bytes(6)), (array) $roles);

        return auth()->getProvider()->findById($id);
    }

    protected function loginAs(User $user): void
    {
        if (auth('session')->loggedIn()) {
            auth('session')->logout();
        }
        $this->actingAs($user);
        service('authz')->forget();
    }

    /** Owner-entered demo legal identity (clearly fake, checksum-valid GSTIN). */
    protected function setupCompany(string $state = '08'): void
    {
        $base  = $state . 'AAAAA0000A1Z';
        $gstin = $base . \App\Libraries\Gstin::checkChar($base);
        db_connect()->table('company_settings')->where('id', 1)->update([
            'legal_name' => 'TEST COMPANY (DEMO)', 'trade_name' => 'DIGIE R WORLD', 'gstin' => $gstin, 'pan' => 'AAAAA0000A',
            'gst_registration_type' => 'regular', 'state_code' => $state, 'reg_address_line1' => 'Test Address', 'reg_city' => 'Bhiwadi', 'reg_pincode' => '301019',
            'rounding_policy' => 'nearest_rupee', 'default_price_mode' => 'exclusive',
        ]);
    }

    protected function gstin(string $state, string $panBody = 'BBBCB1111B'): string
    {
        $base = $state . $panBody . '1Z';

        return $base . \App\Libraries\Gstin::checkChar($base);
    }

    /** @param array<string, mixed> $extra */
    protected function makeContact(array $extra = []): int
    {
        self::$seq++;

        return service_contacts()->create($extra + [
            'name' => 'Client ' . self::$seq, 'is_customer' => 1, 'phone' => '98' . str_pad((string) (10000000 + self::$seq * 7919 % 89999999), 8, '0', STR_PAD_LEFT),
            'state_code' => '08', 'billing_line1' => 'Street ' . self::$seq, 'city' => 'Bhiwadi',
        ], true)['id'];
    }

    /** Set the next number of a document series for the current FY. */
    protected function setNextNumber(string $docType, int $next): void
    {
        $fy = service('fy')->forDate(date('Y-m-d'));
        db_connect()->table('document_sequences')->where(['doc_type' => $docType, 'financial_year_id' => $fy['id']])->update(['next_number' => $next]);
    }

    protected function cashAccountId(): int
    {
        return (int) db_connect()->table('bank_cash_accounts')->where('code', 'CASH')->get()->getRow()->id;
    }

    protected function makeBankAccount(string $name = 'Test Current A/c', string $ownership = 'company'): int
    {
        $db = db_connect();
        self::$seq++;
        $db->table('ledger_accounts')->insert(['code' => 'T' . str_pad((string) self::$seq, 4, '0', STR_PAD_LEFT), 'name' => $name, 'account_group' => 'asset', 'sub_type' => $ownership === 'owner_personal' ? 'owner_clearing' : 'bank']);
        $lid = (int) $db->insertID();
        $db->table('bank_cash_accounts')->insert([
            'code' => 'BANK' . self::$seq, 'account_kind' => 'bank', 'ownership' => $ownership, 'display_name' => $name, 'bank_name' => 'Test Bank',
            'beneficiary_name' => 'TEST COMPANY', 'account_number_enc' => \App\Services\Settings\SettingsStore::encrypt('123456789012'), 'account_last4' => '9012',
            'ifsc' => 'HDFC0001234', 'cif_enc' => \App\Services\Settings\SettingsStore::encrypt('CIF998877'), 'show_on_invoice' => 1, 'is_default_on_invoice' => 0,
            'ledger_account_id' => $lid, 'requires_review' => $ownership === 'owner_personal' ? 1 : 0, 'is_active' => 1,
        ]);

        return (int) $db->insertID();
    }

    /** Ledger balance (Dr − Cr) for a system sub_type, optional contact. */
    protected function balance(string $subType, ?int $contactId = null): string
    {
        return service('ledger')->balance(service('ledger')->account($subType), null, $contactId);
    }

    protected function bankBalance(int $accountId): string
    {
        return service('ledger')->bankBalance($accountId);
    }

    /** Draft + finalize an invoice-like document; returns [id, number]. */
    protected function issue(string $docType, int $contactId, array $lines, string $gstType, string $pos, array $extra = [], array $finalizeOpts = []): array
    {
        $id  = service('billing')->saveDraft($extra + [
            'doc_type' => $docType, 'contact_id' => $contactId, 'issue_date' => date('Y-m-d'),
            'place_of_supply_state_code' => $pos, 'gst_type' => $gstType, 'lines' => $lines,
        ]);
        $res = service('billing')->finalize($id, $finalizeOpts);

        return [$id, $res['document_number']];
    }

    protected function line(string $amount = '10000', string $rate = '18', array $extra = []): array
    {
        return $extra + ['item_name' => 'SEO Monthly Retainer', 'qty' => '1', 'unit_rate' => $amount, 'tax_treatment' => 'regular', 'gst_rate' => $rate, 'sac_code' => '998361'];
    }

    /**
     * HTTP status of a request, mapping the 404/403 exceptions that the
     * framework would render outside of tests.
     */
    protected function statusOf(\Closure $request): int
    {
        try {
            return $request()->response()->getStatusCode();
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            return 404;
        } catch (\App\Exceptions\AccessDeniedException) {
            return 403;
        }
    }

    /** POST with a valid CSRF token (feature tests). */
    protected function postForm(string $uri, array $data = []): TestResponse
    {
        return $this->post($uri, $data + [csrf_token() => csrf_hash()]);
    }
}

function service_contacts(): \App\Services\Contacts\ContactService
{
    return new \App\Services\Contacts\ContactService(db_connect());
}

<?php

namespace Tests\Feature;

use App\Services\Billing\RecurringBillingService;
use Tests\Support\DgrTestCase;

/** Recurring retainers generate drafts only (idempotent per period); integrations page shows status only. */
final class RecurringIntegrationsTest extends DgrTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompany('08');
    }

    public function testRetainerGeneratesDraftsOnlyOncePerPeriodAndEnds(): void
    {
        $owner = $this->makeUser('super_admin');
        $this->loginAs($owner);
        $c = $this->makeContact(['name' => 'Retainer Client']);
        $start = date('Y-m-01', strtotime('-1 month'));

        $this->get('billing/recurring/new')->assertSee('Lines on each draft');
        $res = $this->postForm('billing/recurring', [
            'name' => 'SEO retainer', 'contact_id' => (string) $c, 'doc_type' => 'tax_invoice', 'frequency' => 'monthly', 'start_date' => $start, 'installments_total' => '2',
            'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'renewal_reminder_days' => '15',
            'lines' => [['item_name' => 'SEO monthly retainer', 'sac_code' => '998361', 'qty' => '1', 'unit' => 'month', 'unit_rate' => '20000', 'gst_rate' => '18', 'tax_treatment' => 'regular']],
        ]);
        $res->assertRedirect();
        $pid = (int) db_connect()->table('recurring_billing_profiles')->where('name', 'SEO retainer')->orderBy('id', 'DESC')->get()->getRow()->id;
        $numbersBefore = db_connect()->table('billing_documents')->where('document_number IS NOT NULL', null, false)->countAllResults();

        // cron run (as the system) generates the overdue period as a DRAFT
        $out = (new RecurringBillingService(db_connect()))->runDue();
        $this->loginAs($owner);
        $this->assertGreaterThanOrEqual(1, $out['generated']);
        $doc = db_connect()->table('billing_documents')->where('recurring_profile_id', $pid)->orderBy('id')->get()->getRowArray();
        $this->assertSame('draft', $doc['status']);
        $this->assertNull($doc['document_number'], 'No number is consumed by a retainer');
        $this->assertSame($start, $doc['service_period_from']);
        $this->assertSame(date('Y-m-t', strtotime($start)), $doc['service_period_to']);
        $this->assertSame('23600.00', $doc['grand_total']);
        $this->assertNull($doc['journal_entry_id'], 'Nothing is posted');
        $this->assertSame($numbersBefore, db_connect()->table('billing_documents')->where('document_number IS NOT NULL', null, false)->countAllResults());
        $this->assertGreaterThan(0, db_connect()->table('notifications')->where(['user_id' => $owner->id, 'entity_type' => 'billing_document', 'entity_id' => $doc['id']])->countAllResults());

        // second installment via the button; then the profile ends (2 bills)
        $this->postForm('billing/recurring/' . $pid . '/generate')->assertRedirect();
        $p = db_connect()->table('recurring_billing_profiles')->where('id', $pid)->get()->getRowArray();
        $this->assertSame(2, (int) $p['installments_done']);
        $this->assertSame(0, (int) $p['is_active']);
        $this->postForm('billing/recurring/' . $pid . '/generate')->assertRedirect();
        $this->assertSame(2, db_connect()->table('billing_documents')->where('recurring_profile_id', $pid)->countAllResults());

        // the same period cannot be generated twice even if the profile is re-activated and rewound
        db_connect()->table('recurring_billing_profiles')->where('id', $pid)->update(['is_active' => 1, 'installments_done' => 0, 'next_run_date' => $start]);
        try {
            (new RecurringBillingService(db_connect()))->generate($pid);
            $this->fail('Duplicate period must be refused');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->get('billing/recurring')->assertSee('SEO retainer');
        $this->get('billing/recurring/' . $pid)->assertSee('Generated documents');
    }

    public function testIntegrationsShowStatusWithoutSecretsAndNeedPermission(): void
    {
        $this->loginAs($this->makeUser('super_admin'));
        $page = $this->get('settings/integrations');
        $page->assertSee('Email (SMTP)');
        $page->assertSee('Not configured');
        $this->postForm('settings/integrations/smtp/test')->assertRedirect();
        $this->assertSame(0, db_connect()->table('integration_settings')->like('setting_key', 'pass')->countAllResults(), 'No SMTP secret is stored in the database');

        $this->loginAs($this->makeUser('accountant'));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('settings/integrations')));
        $this->get('billing/recurring')->assertOK(); // accountants manage retainers

        $this->loginAs($this->makeUser('billing_operator'));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('billing/recurring')));
    }
}

<?php

namespace Tests\Feature;

use App\Services\Sync\FakeSheetsClient;
use App\Services\Sync\SheetsSyncService;
use Tests\Support\DgrTestCase;

/** Spec §19: lead timeline, follow-ups, Google Sheets upsert/idempotency/outage. */
final class CrmLeadSyncTest extends DgrTestCase
{
    private FakeSheetsClient $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('google_sync_settings')->where('id', 1)->update(['enabled' => 1, 'spreadsheet_id' => 'TEST-SPREADSHEET']);
        db_connect()->table('google_sync_jobs')->truncate();
        db_connect()->table('google_sync_row_map')->truncate();
        $this->sheet = new FakeSheetsClient();
    }

    private function sync(): array
    {
        return (new SheetsSyncService(db_connect(), $this->sheet))->process(500, 'test');
    }

    private function mobile(): string
    {
        return '9' . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT);
    }

    public function testTelecallerLeadWithThreeCallsShowsThreeEventsLatestStatusNextReminderAndOwner(): void
    {
        $tc = $this->makeUser('telecaller', 'Tele Caller One');
        $this->loginAs($tc);
        $leads = service('leads');
        $id = $leads->create(['company_name' => 'Sharma Dental Clinic', 'contact_person' => 'Dr Sharma', 'mobile' => $this->mobile(), 'city' => 'Bhiwadi', 'industry' => 'Healthcare', 'services_interested' => ['SEO', 'GBP']]);

        $leads->logCall($id, ['outcome' => 'no_answer', 'called_at' => date('Y-m-d H:i:s', time() - 7200)]);
        $leads->logCall($id, ['outcome' => 'busy', 'called_at' => date('Y-m-d H:i:s', time() - 3600), 'notes' => 'Line busy']);
        $next = date('Y-m-d H:i:00', strtotime('+1 day 11:00'));
        $leads->logCall($id, ['outcome' => 'interested', 'notes' => 'Wants SEO proposal', 'next_action' => 'Send proposal & call', 'next_action_at' => $next, 'remind' => '1']);

        $calls = db_connect()->table('lead_activities')->where(['lead_id' => $id, 'activity_type' => 'call'])->countAllResults();
        $this->assertSame(3, $calls, 'Three separate call events, none overwritten');
        $this->assertSame(3, db_connect()->table('call_logs')->where('lead_id', $id)->countAllResults());
        $lead = db_connect()->table('leads')->where('id', $id)->get()->getRowArray();
        $this->assertSame(3, (int) $lead['call_attempts']);
        $this->assertSame(1, (int) $lead['connected_calls']);
        $this->assertSame('interested', db_connect()->table('crm_stages')->where('id', $lead['stage_id'])->get()->getRow()->code, 'Latest status reflects the last call');
        $this->assertSame($next, $lead['next_followup_at']);
        $this->assertSame((int) $tc->id, (int) $lead['assigned_to']);
        $f = db_connect()->table('followups')->where(['lead_id' => $id, 'status' => 'pending'])->get()->getRowArray();
        $this->assertSame((int) $tc->id, (int) $f['owner_id']);
        $this->assertSame(1, db_connect()->table('task_reminders')->where(['entity_type' => 'followup', 'entity_id' => $f['id']])->countAllResults(), 'Reminder is set');

        // HTTP view shows the three calls and the next follow-up
        $page = $this->get('crm/leads/' . $id);
        $page->assertSee('Wants SEO proposal');
        $page->assertSee('Line busy');
    }

    public function testSheetsAppendOnceUpdateSameRowSurviveOutageAndRetry(): void
    {
        $this->loginAs($this->makeUser('super_admin'));
        $leads = service('leads');
        $id = $leads->create(['company_name' => 'Gupta Sweets', 'mobile' => $this->mobile(), 'city' => 'Alwar']);
        $this->sync();
        $rows = $this->sheet->rows('Leads');
        $this->assertCount(1, $rows, 'Lead creation appends one row');
        $this->assertSame('L' . $id, $rows[0][0]);

        // edit → same logical row updated, no duplicate
        $leads->update($id, ['company_name' => 'Gupta Sweets & Namkeen', 'mobile' => db_connect()->table('leads')->where('id', $id)->get()->getRow()->mobile, 'city' => 'Alwar']);
        $this->sync();
        $rows = $this->sheet->rows('Leads');
        $this->assertCount(1, $rows, 'Edit must not add a second row');
        $this->assertSame('Gupta Sweets & Namkeen', $rows[0][2]);
        $this->assertSame(1, db_connect()->table('google_sync_jobs')->where('idempotency_key', 'leads:lead:' . $id)->countAllResults(), 'One job row per logical record');

        // Google down: the CRM save still commits; the job fails and is kept for retry
        $this->sheet->failNext(100);
        $leads->update($id, ['company_name' => 'Gupta Sweets (Outage edit)', 'mobile' => db_connect()->table('leads')->where('id', $id)->get()->getRow()->mobile, 'city' => 'Alwar']);
        $this->assertSame('Gupta Sweets (Outage edit)', db_connect()->table('leads')->where('id', $id)->get()->getRow()->company_name, 'MySQL data is saved regardless of Google');
        $r = $this->sync();
        $this->assertGreaterThan(0, $r['failed']);
        $job = db_connect()->table('google_sync_jobs')->where('idempotency_key', 'leads:lead:' . $id)->get()->getRowArray();
        $this->assertSame('failed', $job['status']);
        $this->assertSame(1, (int) $job['attempts']);
        $this->assertStringContainsString('outage', $job['last_error']);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $job['next_attempt_at'], 'Backoff schedules the retry later');

        // Retry now after Google recovers → same row updated, still one row
        $this->sheet->failNext(0);
        (new SheetsSyncService(db_connect(), $this->sheet))->retryFailed();
        $this->sync();
        $rows = $this->sheet->rows('Leads');
        $this->assertCount(1, $rows);
        $this->assertSame('Gupta Sweets (Outage edit)', $rows[0][2]);
        $this->assertSame('done', db_connect()->table('google_sync_jobs')->where('idempotency_key', 'leads:lead:' . $id)->get()->getRow()->status);

        // someone sorts the sheet: upsert finds the row by ID again (no duplicate)
        array_splice($this->sheet->tabs['Leads'], 1, 0, [['L999999', 'manual row']]);
        $leads->update($id, ['company_name' => 'Gupta Sweets Final', 'mobile' => db_connect()->table('leads')->where('id', $id)->get()->getRow()->mobile, 'city' => 'Alwar']);
        $this->sync();
        $mine = array_values(array_filter($this->sheet->rows('Leads'), static fn ($r) => $r[0] === 'L' . $id));
        $this->assertCount(1, $mine);
        $this->assertSame('Gupta Sweets Final', $mine[0][2]);
    }

    public function testDndLeadsAreMaskedInSheetsAndCallsAreBlocked(): void
    {
        $this->loginAs($this->makeUser('super_admin'));
        $leads = service('leads');
        $m  = $this->mobile();
        $id = $leads->create(['company_name' => 'Privacy Test Co', 'mobile' => $m]);
        $leads->logCall($id, ['outcome' => 'dnd_requested', 'notes' => 'Asked not to call']);
        $this->sync();
        $row = array_values(array_filter($this->sheet->rows('Leads'), static fn ($r) => $r[0] === 'L' . $id))[0];
        $this->assertStringNotContainsString($m, implode('|', $row), 'DND numbers are masked in the shared sheet');
        $this->assertStringContainsString('(DND)', implode('|', $row));
        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $leads->logCall($id, ['outcome' => 'connected']);
    }

    public function testDuplicateMobileIsDetected(): void
    {
        $this->loginAs($this->makeUser('super_admin'));
        $m = $this->mobile();
        service('leads')->create(['company_name' => 'First Co', 'mobile' => $m]);
        try {
            service('leads')->create(['company_name' => 'Second Co', 'mobile' => '+91 ' . $m]);
            $this->fail('Duplicate mobile must be detected');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
    }
}

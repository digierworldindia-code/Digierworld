<?php

namespace Tests\Feature;

use App\Services\Notify\ReminderService;
use Tests\Support\DgrTestCase;

/** Spec §19 / §27: overdue visibility, owner visit timeline, telecaller isolation. */
final class CrmAccessReminderTest extends DgrTestCase
{
    private function mobile(): string
    {
        return '8' . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT);
    }

    public function testOverdueFollowupVisibleToTelecallerAndManagerWithReminders(): void
    {
        $mgr = $this->makeUser('crm_manager', 'CRM Manager');
        $tc  = $this->makeUser('telecaller', 'Tele Caller Two');
        db_connect()->table('user_profiles')->where('user_id', $tc->id)->update(['manager_user_id' => $mgr->id]);
        $this->loginAs($tc);
        $id = service('leads')->create(['company_name' => 'Overdue Clinic', 'mobile' => $this->mobile()]);
        // follow-up that was due yesterday
        db_connect()->transBegin();
        service('leads')->addFollowup($id, (int) $tc->id, date('Y-m-d 10:00:00', strtotime('-1 day')), 'Call back about pricing');
        db_connect()->transCommit();

        $this->get('crm/today')->assertSee('Overdue Clinic');
        $this->get('crm/followups?tab=overdue')->assertSee('Call back about pricing');

        $this->loginAs($mgr);
        $this->get('crm/today?user=' . $tc->id)->assertSee('Overdue Clinic');
        $this->get('crm/followups?tab=overdue')->assertSee('Overdue Clinic');

        $out = (new ReminderService(db_connect(), service('notifier')))->run();
        $this->assertGreaterThanOrEqual(1, $out['overdue_digests']);
        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', $tc->id)->like('dedupe_key', 'overdue:' . $tc->id, 'after')->countAllResults());
        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', $mgr->id)->like('dedupe_key', 'overdue-mgr:' . $tc->id, 'after')->countAllResults());
        // running again the same day sends nothing new
        (new ReminderService(db_connect(), service('notifier')))->run();
        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', $tc->id)->like('dedupe_key', 'overdue:' . $tc->id, 'after')->countAllResults());
    }

    public function testOwnerVisitHasItsOwnTimelineAndNextActionFollowup(): void
    {
        $owner = $this->makeUser('super_admin', 'Owner Visitor');
        $tc    = $this->makeUser('telecaller', 'Tele Caller Three');
        $this->loginAs($tc);
        $id = service('leads')->create(['company_name' => 'Visit Motors', 'mobile' => $this->mobile(), 'address' => 'Plot 9, RIICO', 'city' => 'Bhiwadi']);

        // telecaller schedules a visit for the owner (no accounting access needed)
        $res = $this->postForm('crm/visits', ['lead_id' => (string) $id, 'visitor_id' => (string) $owner->id, 'scheduled_at' => date('Y-m-d\TH:i', strtotime('-3 hours')), 'purpose' => 'Owner to present SEO plan']);
        $res->assertRedirect();
        $visit = db_connect()->table('visits')->where('lead_id', $id)->get()->getRowArray();
        $this->assertSame((int) $owner->id, (int) $visit['visitor_id']);
        $this->assertSame(1, db_connect()->table('notifications')->where(['user_id' => $owner->id, 'entity_type' => 'visit', 'entity_id' => $visit['id']])->countAllResults(), 'Owner is told about the visit');

        // reminder: time has passed without an outcome → nudge visitor and scheduler
        $out = (new ReminderService(db_connect(), service('notifier')))->run();
        $this->assertGreaterThanOrEqual(1, $out['visit_nudges']);

        $this->loginAs($owner);
        $this->get('crm/visits/' . $visit['id'])->assertSee('Visit timeline');
        $this->postForm('crm/visits/' . $visit['id'] . '/complete', ['status' => 'completed', 'outcome' => 'Agreed to start with GBP', 'client_feedback' => 'Wants monthly report',
            'next_action' => 'Send GBP quote', 'next_action_at' => date('Y-m-d\TH:i', strtotime('+2 days'))])->assertRedirect();
        $page = $this->get('crm/visits/' . $visit['id']);
        $page->assertSee('Agreed to start with GBP');
        $page->assertSee('Wants monthly report');
        $this->assertSame(1, db_connect()->table('followups')->where(['lead_id' => $id, 'status' => 'pending', 'title' => 'Send GBP quote'])->countAllResults(), 'Next action becomes a follow-up');
        $this->assertGreaterThanOrEqual(2, db_connect()->table('lead_activities')->where(['lead_id' => $id, 'ref_type' => 'visit'])->countAllResults());
    }

    public function testTelecallerSeesOnlyOwnLeadsAndNoFinanceGstPayrollOrOthersData(): void
    {
        $tc1 = $this->makeUser('telecaller', 'TC Alpha');
        $tc2 = $this->makeUser('telecaller', 'TC Beta');
        $this->loginAs($tc2);
        $other = service('leads')->create(['company_name' => 'Beta Secret Lead', 'mobile' => $this->mobile()]);

        $this->loginAs($tc1);
        $mine = service('leads')->create(['company_name' => 'Alpha Own Lead', 'mobile' => $this->mobile()]);
        $list = $this->get('crm/leads');
        $list->assertSee('Alpha Own Lead');
        $list->assertDontSee('Beta Secret Lead');
        $this->assertSame(404, $this->statusOf(fn () => $this->get('crm/leads/' . $other)));
        $this->assertSame(404, $this->statusOf(fn () => $this->postForm('crm/leads/' . $other . '/call', ['outcome' => 'connected'])));
        $this->assertSame(404, $this->statusOf(fn () => $this->get('crm/leads/' . $other . '/edit')));
        $this->assertSame(0, db_connect()->table('call_logs')->where('lead_id', $other)->countAllResults(), 'No call was logged on another telecaller lead');
        $lookup = (string) $this->get('crm/leads/lookup?q=Beta')->response()->getBody();
        $this->assertStringNotContainsString('Beta Secret Lead', $lookup);

        foreach (['finance/accounts', 'finance/accounts/1', 'finance/receipts', 'finance/transfers', 'finance/ledger', 'finance/reconciliation', 'finance/expenses',
            'reports', 'reports/gst', 'reports/gst/gstr1', 'reports/sales_register', 'billing/documents', 'settings/banks', 'crm/export', 'crm/sync', 'admin/users',
            'hr/employees', 'hr/employees/1', 'hr/payroll', 'hr/payroll/statutory', 'hr/candidates', 'hr/vacancies', 'hr/incentives', 'hr/attendance', 'hr/leaves', 'admin/ai'] as $url) {
            $this->assertSame(403, $this->statusOf(fn () => $this->get($url)), "Telecaller must be denied {$url}");
        }
        $this->assertSame(403, $this->statusOf(fn () => $this->postForm('finance/transfers', ['amount' => '1'])));
        $this->assertSame(403, $this->statusOf(fn () => $this->postForm('hr/employees/1/salary', ['effective_from' => date('Y-m-d'), 'monthly_gross' => '1'])));

        // ...but can hand work to the owner
        $owner = $this->makeUser('super_admin', 'Owner For Tasks');
        $this->loginAs($tc1);
        $this->postForm('crm/tasks', ['title' => 'Owner to call back Alpha Own Lead', 'assigned_to' => (string) $owner->id, 'lead_id' => (string) $mine])->assertRedirect();
        $this->assertSame(1, db_connect()->table('tasks')->where(['assigned_to' => $owner->id, 'assigned_by' => $tc1->id])->countAllResults());
    }
}

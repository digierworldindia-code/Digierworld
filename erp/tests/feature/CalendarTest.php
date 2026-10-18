<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/** Calendar & reminders shows only the caller's own items, plus holidays. */
final class CalendarTest extends DgrTestCase
{
    private function ready(string $role, string $name): \CodeIgniter\Shield\Entities\User
    {
        $u = $this->makeUser($role, $name);
        db_connect()->table('user_profiles')->where('user_id', $u->id)->update(['must_change_password' => 0]);

        return $u;
    }

    public function testShowsOwnItemsAndHolidaysButNeverOtherPeoplesWork(): void
    {
        $mine  = $this->ready('telecaller', 'Calendar Caller');
        $other = $this->ready('telecaller', 'Other Caller');
        $tag   = bin2hex(random_bytes(3));
        $day   = date('Y-m-d', strtotime('+3 days'));
        $month = substr($day, 0, 7);

        $this->loginAs($mine);
        $lead = service('leads')->create(['company_name' => 'Cal Lead ' . $tag, 'mobile' => '8' . random_int(100000000, 999999999)]);
        service('leads')->createTask(['title' => 'Send proposal ' . $tag, 'due_at' => $day . ' 11:00']);
        service('leads')->addFollowup($lead, (int) $mine->id, $day . ' 15:30', 'Call back ' . $tag);
        $this->loginAs($other);
        service('leads')->createTask(['title' => 'Secret task ' . $tag, 'due_at' => $day . ' 10:00']);
        db_connect()->query('INSERT IGNORE INTO holidays (holiday_date, name, is_optional) VALUES (?, ?, 0)', [$day, 'Test Festival']);
        service('hrCalendar')->reset();
        $holiday = (string) db_connect()->table('holidays')->where('holiday_date', $day)->get()->getRow()->name;

        $this->loginAs($mine);
        $page = $this->get('calendar?month=' . $month);
        $page->assertOK();
        $page->assertSee('Send proposal ' . $tag);
        $page->assertSee('Call back ' . $tag . ' — Cal Lead ' . $tag);
        $page->assertSee('11:00 AM');
        $page->assertSee(esc($holiday));
        $page->assertDontSee('Secret task ' . $tag);
        $page->assertSee(site_url('crm/leads/' . $lead));

        // the menu entry and the task quick-add both lead to real pages
        $this->get('/')->assertSee(site_url('calendar'));
        $this->assertSame(200, $this->statusOf(fn () => $this->get('crm/tasks')));
        $this->assertSame(200, $this->statusOf(fn () => $this->get('calendar?month=bogus')), 'invalid month falls back to this month');
    }

    public function testCalendarNeedsPermission(): void
    {
        $auditor = $this->ready('auditor', 'Calendar Auditor');
        $this->loginAs($auditor);
        $this->assertSame(403, $this->statusOf(fn () => $this->get('calendar')));

        $employee = $this->ready('employee', 'Calendar Employee');
        $this->loginAs($employee);
        $this->assertSame(200, $this->statusOf(fn () => $this->get('calendar')));
    }
}

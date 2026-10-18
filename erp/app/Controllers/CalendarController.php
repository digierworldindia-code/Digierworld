<?php

namespace App\Controllers;

use App\Services\Hr\HrCalendar;

/**
 * The caller's own month: tasks and follow-ups assigned to them, visits
 * they make, meetings they organise or attend, interviews they conduct,
 * their own leave, plus company holidays and weekly offs. Nothing about
 * other people's work is shown, so the page needs only calendar.view.
 */
class CalendarController extends BaseController
{
    public function index()
    {
        $m     = (string) $this->request->getGet('month');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
        $from  = $month . '-01';
        $to    = date('Y-m-t', strtotime($from));
        $uid   = (int) $this->userId;
        $range = static fn ($b, string $col) => $b->where($col . ' >=', $from . ' 00:00:00')->where($col . ' <=', $to . ' 23:59:59');

        $events = [];
        $add    = static function (string $at, string $kind, string $title, ?string $url, string $status = '') use (&$events): void {
            $events[substr($at, 0, 10)][] = ['time' => strlen($at) > 10 ? date('h:i A', strtotime($at)) : '', 'at' => $at, 'kind' => $kind, 'title' => $title, 'url' => $url, 'status' => $status];
        };

        if ($this->can(['crm.task.view_own', 'crm.task.view_all'])) {
            foreach ($range($this->db->table('tasks')->where('assigned_to', $uid)->where('status !=', 'cancelled'), 'due_at')->get()->getResultArray() as $t) {
                $add($t['due_at'], 'task', $t['title'], site_url('crm/tasks'), $t['status']);
            }
        }
        foreach ($range($this->db->table('followups f')->select('f.*, l.company_name')->join('leads l', 'l.id = f.lead_id')->where('f.owner_id', $uid)->where('l.deleted_at', null)->where('f.status !=', 'cancelled'), 'f.due_at')->get()->getResultArray() as $f) {
            $add($f['due_at'], 'follow-up', ($f['title'] ?: 'Follow up') . ' — ' . $f['company_name'], $this->can(['crm.lead.view_assigned', 'crm.lead.view_team', 'crm.lead.view_all']) ? site_url('crm/leads/' . $f['lead_id']) : null, $f['status']);
        }
        if ($this->can('crm.visit.view')) {
            foreach ($range($this->db->table('visits')->where('visitor_id', $uid)->where('status !=', 'cancelled'), 'scheduled_at')->get()->getResultArray() as $v) {
                $add($v['scheduled_at'], 'visit', $v['purpose'] ?: 'Client visit', site_url('crm/visits/' . $v['id']), $v['status']);
            }
        }
        foreach ($range($this->db->table('meetings')->where('status !=', 'cancelled')->groupStart()->where('organizer_id', $uid)->orWhere("JSON_CONTAINS(attendee_ids, '" . $uid . "')", null, false)->groupEnd(), 'meeting_at')->get()->getResultArray() as $mt) {
            $add($mt['meeting_at'], 'meeting', $mt['title'], $this->can('crm.meeting.manage') ? site_url('crm/meetings') : null, $mt['status']);
        }
        foreach ($range($this->db->table('interviews')->where('interviewer_id', $uid)->where('status !=', 'cancelled'), 'scheduled_at')->get()->getResultArray() as $i) {
            $add($i['scheduled_at'], 'interview', 'Interview round ' . (int) $i['round_no'] . ' (' . str_replace('_', ' ', $i['interview_type']) . ')', $this->can('hr.interview.manage') ? site_url('hr/interviews') : null, $i['status']);
        }

        $me = service('employees')->forUser($uid);
        if ($me !== null) {
            $leaves = $this->db->table('leave_requests r')->select('r.*, t.name AS type_name')->join('leave_types t', 't.id = r.leave_type_id')
                ->where('r.employee_id', $me['id'])->whereIn('r.status', ['pending', 'approved'])->where('r.from_date <=', $to)->where('r.to_date >=', $from)->get()->getResultArray();
            foreach ($leaves as $l) {
                foreach (HrCalendar::dates(max($l['from_date'], $from), min($l['to_date'], $to)) as $d) {
                    $add($d, 'leave', $l['type_name'] . ($l['half_day'] ? ' (half day)' : ''), $this->can('self.leave.request') ? site_url('me/leaves') : null, $l['status']);
                }
            }
        }

        $cal  = service('hrCalendar');
        $days = [];
        foreach (HrCalendar::dates($from, $to) as $d) {
            $holiday  = $cal->holiday($d);
            $days[$d] = ['holiday' => $holiday, 'weekOff' => $holiday === null && $cal->isWeekOff($d), 'events' => $events[$d] ?? []];
            usort($days[$d]['events'], static fn ($a, $b) => strcmp($a['at'], $b['at']));
        }

        $reminders = $this->db->table('task_reminders')->where('user_id', $uid)->where('sent_at', null)->where('cancelled_at', null)
            ->where('remind_at >=', date('Y-m-d H:i:s'))->where('remind_at <=', date('Y-m-d H:i:s', strtotime('+14 days')))->orderBy('remind_at')->limit(30)->get()->getResultArray();

        return $this->render('calendar/index', [
            'pageTitle' => 'Calendar & reminders', 'breadcrumbs' => [['Calendar']],
            'month' => $month, 'days' => $days, 'reminders' => $reminders,
            'prev' => date('Y-m', strtotime($from . ' -1 month')), 'next' => date('Y-m', strtotime($from . ' +1 month')),
        ]);
    }
}

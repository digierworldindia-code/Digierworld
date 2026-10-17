<?php

namespace App\Controllers\Crm;

use App\Exceptions\BusinessRuleException;
use App\Services\Crm\LeadService;

/** My Day, pipeline, call log, performance analytics and permission-limited export. */
class CrmController extends CrmBase
{
    /** Users whose work the caller may review on My Day / performance. @return array<int, string> */
    private function reviewable(): array
    {
        return $this->scopeUsers();
    }

    public function today()
    {
        $users = $this->reviewable();
        $uid   = (int) ($this->request->getGet('user') ?: $this->userId);
        if (! isset($users[$uid])) {
            $uid = (int) $this->userId;
        }
        $now   = date('Y-m-d H:i:s');
        $start = date('Y-m-d 00:00:00');
        $end   = date('Y-m-d 23:59:59');
        $fu = fn () => $this->db->table('followups f')->select('f.*, l.company_name, l.mobile, l.lead_code, l.priority, l.dnd, s.name AS stage_name')
            ->join('leads l', 'l.id = f.lead_id')->join('crm_stages s', 's.id = l.stage_id', 'left')
            ->where(['f.status' => 'pending', 'f.owner_id' => $uid])->where('l.deleted_at', null);
        $target = $this->db->table('crm_daily_targets')->where('user_id', $uid)->where('effective_from <=', date('Y-m-d'))->orderBy('effective_from', 'DESC')->limit(1)->get()->getRowArray();
        $callsToday = $this->db->table('call_logs')->where('caller_id', $uid)->where('called_at >=', $start)->where('called_at <=', $end);

        return $this->render('crm/today', [
            'pageTitle' => 'My Day', 'breadcrumbs' => [['CRM'], ['My Day']], 'users' => $users, 'uid' => $uid,
            'overdue' => $fu()->where('f.due_at <', $start)->orderBy('f.due_at')->limit(100)->get()->getResultArray(),
            'dueToday' => $fu()->where('f.due_at >=', $start)->where('f.due_at <=', $end)->orderBy('f.due_at')->get()->getResultArray(),
            'newLeads' => $this->db->table('leads l')->select('l.id, l.lead_code, l.company_name, l.mobile, l.city, l.created_at, l.priority')
                ->join('crm_stages s', 's.id = l.stage_id', 'left')->where(['l.assigned_to' => $uid, 'l.call_attempts' => 0, 'l.deleted_at' => null, 'l.dnd' => 0])
                ->where('(s.is_closed = 0 OR s.is_closed IS NULL)', null, false)->orderBy('l.priority = \'hot\'', 'DESC', false)->orderBy('l.created_at')->limit(50)->get()->getResultArray(),
            'visits' => $this->db->table('visits v')->select('v.*, l.company_name')->join('leads l', 'l.id = v.lead_id', 'left')
                ->where('v.visitor_id', $uid)->where('v.scheduled_at >=', $start)->where('v.scheduled_at <=', $end)->orderBy('v.scheduled_at')->get()->getResultArray(),
            'meetings' => $this->db->table('meetings m')->select('m.*, l.company_name')->join('leads l', 'l.id = m.lead_id', 'left')
                ->groupStart()->where('m.organizer_id', $uid)->orWhere("JSON_CONTAINS(m.attendee_ids, '" . $uid . "')", null, false)->groupEnd()
                ->where('m.meeting_at >=', $start)->where('m.meeting_at <=', $end)->orderBy('m.meeting_at')->get()->getResultArray(),
            'tasks' => $this->db->table('tasks')->where('assigned_to', $uid)->whereIn('status', ['pending', 'in_progress'])->groupStart()->where('due_at <=', $end)->orWhere('due_at', null)->groupEnd()->orderBy('due_at')->limit(30)->get()->getResultArray(),
            'done' => $this->db->table('call_logs c')->select('c.*, l.company_name')->join('leads l', 'l.id = c.lead_id')->where('c.caller_id', $uid)
                ->where('c.called_at >=', $start)->where('c.called_at <=', $end)->orderBy('c.called_at', 'DESC')->get()->getResultArray(),
            'actual' => [
                'calls' => (clone $callsToday)->countAllResults(),
                'connected' => (clone $callsToday)->where('is_connected', 1)->countAllResults(),
                'followups' => $this->db->table('followups')->where(['completed_by' => $uid, 'status' => 'done'])->where('completed_at >=', $start)->countAllResults(),
            ],
            'target' => $target, 'now' => $now, 'outcomes' => LeadService::OUTCOMES,
        ]);
    }

    public function pipeline()
    {
        $b = $this->db->table('leads l')->select('l.id, l.company_name, l.lead_code, l.expected_value, l.next_followup_at, l.priority, l.stage_id, p.full_name AS assignee')
            ->join('user_profiles p', 'p.user_id = l.assigned_to', 'left')->where('l.deleted_at', null);
        service('leadAccess')->applyScope($b, 'l');
        if ($u = (int) $this->request->getGet('user')) {
            $b->where('l.assigned_to', $u);
        }
        $cards = [];
        foreach ($b->orderBy('l.next_followup_at')->limit(1500)->get()->getResultArray() as $r) {
            $cards[(int) $r['stage_id']][] = $r;
        }

        return $this->render('crm/pipeline', ['pageTitle' => 'Pipeline', 'breadcrumbs' => [['CRM'], ['Pipeline']], 'stages' => $this->stages(), 'cards' => $cards, 'users' => $this->scopeUsers()]);
    }

    public function calls()
    {
        $g    = $this->request->getGet();
        $from = ymd((string) ($g['from'] ?? '')) ?? date('Y-m-d', strtotime('-6 days'));
        $to   = ymd((string) ($g['to'] ?? '')) ?? date('Y-m-d');
        $b = fn () => $this->db->table('call_logs c')->join('leads l', 'l.id = c.lead_id')->join('user_profiles p', 'p.user_id = c.caller_id', 'left')
            ->where('l.deleted_at', null)->where('c.called_at >=', $from . ' 00:00:00')->where('c.called_at <=', $to . ' 23:59:59')
            ->when(isset(LeadService::OUTCOMES[$g['outcome'] ?? '']), static fn ($q) => $q->where('c.outcome', $g['outcome']))
            ->when((int) ($g['caller'] ?? 0) > 0, static fn ($q) => $q->where('c.caller_id', (int) $g['caller']));
        $scoped = function () use ($b) {
            $q = $b();
            service('leadAccess')->applyScope($q, 'l');

            return $q;
        };
        [$page, $perPage, $offset] = $this->paging(50);

        return $this->render('crm/calls', [
            'pageTitle' => 'Call log', 'breadcrumbs' => [['CRM'], ['Calls']],
            'rows' => $scoped()->select('c.*, l.company_name, l.lead_code, p.full_name AS caller')->orderBy('c.called_at', 'DESC')->limit($perPage, $offset)->get()->getResultArray(),
            'total' => $scoped()->countAllResults(), 'page' => $page, 'perPage' => $perPage,
            'from' => $from, 'to' => $to, 'outcome' => (string) ($g['outcome'] ?? ''), 'caller' => (int) ($g['caller'] ?? 0),
            'outcomes' => LeadService::OUTCOMES, 'users' => $this->scopeUsers(),
        ]);
    }

    /** Per-person activity, conversion and source performance for a period. */
    public function performance()
    {
        $from = ymd((string) $this->request->getGet('from')) ?? date('Y-m-01');
        $to   = ymd((string) $this->request->getGet('to')) ?? date('Y-m-d');
        $teamView = $this->can('crm.reports.view_team') || $this->can('crm.lead.view_all');
        $users = $teamView ? $this->reviewable() : [(int) $this->userId => current_user_name()];
        $ids   = array_keys($users) ?: [0];
        $f = $from . ' 00:00:00';
        $t = $to . ' 23:59:59';
        $per = [];
        foreach ($users as $uid => $name) {
            $per[$uid] = ['name' => $name, 'calls' => 0, 'connected' => 0, 'followups_done' => 0, 'overdue' => 0, 'meetings' => 0, 'visits' => 0, 'new_leads' => 0, 'conversions' => 0];
        }
        foreach ($this->db->query('SELECT caller_id u, COUNT(*) n, SUM(is_connected) c FROM call_logs WHERE called_at BETWEEN ? AND ? AND caller_id IN ? GROUP BY caller_id', [$f, $t, $ids])->getResultArray() as $r) {
            $per[(int) $r['u']]['calls'] = (int) $r['n'];
            $per[(int) $r['u']]['connected'] = (int) $r['c'];
        }
        $add = function (string $key, string $sql, array $binds) use (&$per): void {
            foreach ($this->db->query($sql, $binds)->getResultArray() as $r) {
                if (isset($per[(int) $r['u']])) {
                    $per[(int) $r['u']][$key] = (int) $r['n'];
                }
            }
        };
        $add('followups_done', "SELECT completed_by u, COUNT(*) n FROM followups WHERE status = 'done' AND completed_at BETWEEN ? AND ? GROUP BY completed_by", [$f, $t]);
        $add('overdue', "SELECT owner_id u, COUNT(*) n FROM followups WHERE status = 'pending' AND due_at < ? GROUP BY owner_id", [date('Y-m-d H:i:s')]);
        $add('meetings', 'SELECT organizer_id u, COUNT(*) n FROM meetings WHERE created_at BETWEEN ? AND ? GROUP BY organizer_id', [$f, $t]);
        $add('visits', "SELECT visitor_id u, COUNT(*) n FROM visits WHERE status = 'completed' AND completed_at BETWEEN ? AND ? GROUP BY visitor_id", [$f, $t]);
        $add('new_leads', 'SELECT assigned_to u, COUNT(*) n FROM leads WHERE created_at BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY assigned_to', [$f, $t]);
        $add('conversions', 'SELECT attributed_user_id u, COUNT(*) n FROM lead_conversion_links WHERE converted_at BETWEEN ? AND ? GROUP BY attributed_user_id', [$f, $t]);

        $srcB = $this->db->table('leads l')->select('COALESCE(s.name, "Unknown") AS source, COUNT(*) AS leads, SUM(l.converted_at IS NOT NULL) AS converted, SUM(l.connected_calls > 0) AS reached', false)
            ->join('lead_sources s', 's.id = l.source_id', 'left')->where('l.deleted_at', null)->where('l.created_at >=', $f)->where('l.created_at <=', $t);
        if (! $teamView) {
            $srcB->where('l.assigned_to', $this->userId);
        } elseif (service('leadAccess')->scope()['mode'] !== 'all') {
            $srcB->whereIn('l.assigned_to', $ids);
        }
        $daily = $this->db->query('SELECT DATE(called_at) d, COUNT(*) n, SUM(is_connected) c FROM call_logs WHERE called_at BETWEEN ? AND ? AND caller_id IN ? GROUP BY DATE(called_at) ORDER BY d', [$f, $t, $ids])->getResultArray();

        return $this->render('crm/performance', [
            'pageTitle' => 'CRM performance', 'breadcrumbs' => [['CRM'], ['Performance']],
            'from' => $from, 'to' => $to, 'per' => $per, 'teamView' => $teamView,
            'sources' => $srcB->groupBy('COALESCE(s.name, "Unknown")', false)->orderBy('leads', 'DESC')->get()->getResultArray(),
            'daily' => $daily,
            'targets' => $this->can('crm.settings.manage') ? $this->db->table('crm_daily_targets t')->select('t.*, p.full_name')->join('user_profiles p', 'p.user_id = t.user_id', 'left')->orderBy('p.full_name')->orderBy('t.effective_from', 'DESC')->get()->getResultArray() : [],
            'allUsers' => $this->can('crm.settings.manage') ? $this->assignableUsers() : [],
        ]);
    }

    public function saveTarget()
    {
        $this->authorize('crm.settings.manage');
        $in = $this->request->getPost();
        $uid = (int) ($in['user_id'] ?? 0);
        if ($uid <= 0) {
            return redirect()->back()->with('error', 'Choose the person.');
        }
        $this->db->table('crm_daily_targets')->insert([
            'user_id' => $uid, 'effective_from' => ymd((string) ($in['effective_from'] ?? '')) ?? date('Y-m-d'),
            'calls_per_day' => max(0, (int) ($in['calls_per_day'] ?? 0)), 'connected_per_day' => max(0, (int) ($in['connected_per_day'] ?? 0)),
            'followups_per_day' => max(0, (int) ($in['followups_per_day'] ?? 0)), 'meetings_per_week' => max(0, (int) ($in['meetings_per_week'] ?? 0)), 'created_by' => $this->userId,
        ]);
        service('audit')->log('crm.target_set', 'user', $uid, null, $in, 'Daily CRM target set');

        return redirect()->back()->with('success', 'Target saved.');
    }

    /** Permission-limited export (also the fallback when live Sheets sync is not configured). */
    public function export()
    {
        $this->authorize('crm.export');
        $scoped = function (string $table, string $alias, string $leadCol = 'lead_id') {
            $b = $this->db->table("{$table} {$alias}")->join('leads l', "l.id = {$alias}.{$leadCol}")->where('l.deleted_at', null);
            service('leadAccess')->applyScope($b, 'l');

            return $b;
        };
        $leads = $this->db->table('leads l')->select('l.lead_code, l.company_name, l.contact_person, l.mobile, l.dnd, l.consent_status, l.email, l.address, l.city, l.pincode, l.industry, l.services_interested, l.campaign, l.priority, l.expected_value, l.next_followup_at, l.last_action, l.call_attempts, l.connected_calls, l.created_at, s.name AS stage, src.name AS source, p.full_name AS assigned_to')
            ->join('crm_stages s', 's.id = l.stage_id', 'left')->join('lead_sources src', 'src.id = l.source_id', 'left')->join('user_profiles p', 'p.user_id = l.assigned_to', 'left')->where('l.deleted_at', null);
        service('leadAccess')->applyScope($leads, 'l');
        $leadRows = $leads->orderBy('l.id')->get()->getResultArray();
        foreach ($leadRows as &$r) {
            $r['services_interested'] = implode(', ', json_decode((string) $r['services_interested'], true) ?: []);
            if ((int) $r['dnd'] === 1) {
                $r['mobile'] = mask_tail($r['mobile'], 2) . ' (DND)';
            }
            $r['dnd'] = (int) $r['dnd'] === 1 ? 'Yes' : 'No';
        }
        unset($r);
        $calls = $scoped('call_logs', 'c')->select('l.lead_code, l.company_name, c.called_at, c.direction, c.outcome, c.is_connected, c.notes, c.next_action, c.next_action_at, p.full_name AS caller')
            ->join('user_profiles p', 'p.user_id = c.caller_id', 'left')->orderBy('c.called_at')->get()->getResultArray();
        $fups = $scoped('followups', 'f')->select('l.lead_code, l.company_name, f.title, f.due_at, f.status, f.completed_at, f.outcome_notes, p.full_name AS owner')
            ->join('user_profiles p', 'p.user_id = f.owner_id', 'left')->orderBy('f.due_at')->get()->getResultArray();
        $visits = $scoped('visits', 'v')->select('l.lead_code, l.company_name, v.scheduled_at, v.address, v.purpose, v.status, v.outcome, v.client_feedback, v.next_action, p.full_name AS visitor')
            ->join('user_profiles p', 'p.user_id = v.visitor_id', 'left')->orderBy('v.scheduled_at')->get()->getResultArray();
        $col = static fn (string $k, string $l, string $t = 'text', int $w = 0) => array_filter(['key' => $k, 'label' => $l, 'type' => $t, 'width' => $w ?: null]);

        return service('exports')->download('crm_export', 'xlsx', 'CRM_export', [
            ['title' => 'Leads', 'rows' => $leadRows, 'columns' => [$col('lead_code', 'Lead code'), $col('company_name', 'Lead / company', 'text', 30), $col('contact_person', 'Contact'), $col('mobile', 'Mobile'),
                $col('dnd', 'DND'), $col('consent_status', 'Consent'), $col('email', 'Email'), $col('address', 'Address', 'text', 30), $col('city', 'City'), $col('pincode', 'PIN'), $col('industry', 'Industry'),
                $col('services_interested', 'Services', 'text', 26), $col('source', 'Source'), $col('campaign', 'Campaign'), $col('assigned_to', 'Assigned'), $col('stage', 'Stage'), $col('priority', 'Priority'),
                $col('expected_value', 'Expected deal', 'money'), $col('next_followup_at', 'Next follow-up'), $col('last_action', 'Last action', 'text', 30), $col('call_attempts', 'Calls', 'int'),
                $col('connected_calls', 'Connected', 'int'), $col('created_at', 'Created')]],
            ['title' => 'Call Logs', 'rows' => $calls, 'columns' => [$col('lead_code', 'Lead'), $col('company_name', 'Lead / company', 'text', 28), $col('called_at', 'Call date'), $col('direction', 'Direction'),
                $col('outcome', 'Outcome'), $col('is_connected', 'Connected', 'int'), $col('notes', 'Remark', 'text', 40), $col('next_action', 'Next action'), $col('next_action_at', 'Next follow-up'), $col('caller', 'Telecaller')]],
            ['title' => 'Follow Ups', 'rows' => $fups, 'columns' => [$col('lead_code', 'Lead'), $col('company_name', 'Lead / company', 'text', 28), $col('title', 'Follow-up', 'text', 28), $col('due_at', 'Due'),
                $col('status', 'Status'), $col('completed_at', 'Completed'), $col('outcome_notes', 'Outcome', 'text', 30), $col('owner', 'Owner')]],
            ['title' => 'Visits', 'rows' => $visits, 'columns' => [$col('lead_code', 'Lead'), $col('company_name', 'Lead / company', 'text', 28), $col('scheduled_at', 'Scheduled'), $col('address', 'Address', 'text', 28),
                $col('purpose', 'Purpose'), $col('status', 'Status'), $col('outcome', 'Outcome', 'text', 30), $col('client_feedback', 'Client feedback', 'text', 30), $col('next_action', 'Next action'), $col('visitor', 'Visitor')]],
        ], ['scope' => service('leadAccess')->scope()['mode']], ['Report' => 'CRM export', 'Privacy' => 'Contains personal contact data. Do-Not-Call numbers are masked. Share only with authorised staff; exports are logged.']);
    }
}

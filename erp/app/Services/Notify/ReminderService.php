<?php

namespace App\Services\Notify;

use CodeIgniter\Database\BaseConnection;

/**
 * Turns due reminders into in-app notifications (and e-mail when SMTP is
 * configured and the user opted in). Safe to run every minute: every
 * notification carries a dedupe key, so nothing is sent twice.
 */
class ReminderService
{
    public function __construct(private readonly BaseConnection $db, private readonly Notifier $notifier)
    {
    }

    private function wantsEmail(int $userId): bool
    {
        if (! Notifier::mailConfigured()) {
            return false;
        }
        $prefs = json_decode((string) ($this->db->table('user_profiles')->select('preferences')->where('user_id', $userId)->get()->getRow()->preferences ?? '{}'), true) ?: [];

        return ! empty($prefs['email_reminders']);
    }

    private function email(int $userId, string $subject, string $body, ?string $link): bool
    {
        if (! $this->wantsEmail($userId)) {
            return false;
        }
        $to = (string) ($this->db->table('auth_identities')->select('secret')->where(['user_id' => $userId, 'type' => 'email_password'])->get()->getRow()->secret ?? '');

        return $to !== '' && $this->notifier->email($to, $subject, '<p>' . esc($body) . '</p>' . ($link ? '<p><a href="' . esc($link) . '">Open in DGR Office</a></p>' : ''));
    }

    /** @return array{reminders: int, overdue_digests: int, visit_nudges: int, expense_reminders: int} */
    public function run(?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $out = ['reminders' => 0, 'overdue_digests' => 0, 'visit_nudges' => 0, 'expense_reminders' => 0];

        // 1. Scheduled reminders for follow-ups, visits, meetings and tasks
        foreach ($this->db->table('task_reminders')->where('sent_at', null)->where('cancelled_at', null)->where('remind_at <=', $now)->orderBy('remind_at')->limit(500)->get()->getResultArray() as $r) {
            [$open, $title, $link] = $this->describe($r['entity_type'], (int) $r['entity_id']);
            if (! $open) {
                $this->db->table('task_reminders')->where('id', $r['id'])->update(['cancelled_at' => $now]);

                continue;
            }
            if ($this->notifier->notify((int) $r['user_id'], 'reminder', $title, null, $link, $r['entity_type'], (int) $r['entity_id'], 'rem:' . $r['id'])) {
                $out['reminders']++;
                if ($this->email((int) $r['user_id'], $title, $title, $link)) {
                    $this->db->table('notifications')->where(['user_id' => $r['user_id'], 'dedupe_key' => 'rem:' . $r['id']])->update(['emailed_at' => $now]);
                }
            }
            $this->db->table('task_reminders')->where('id', $r['id'])->update(['sent_at' => $now]);
        }

        // 2. Daily overdue digest to the owner of the follow-ups and to their manager
        $day = substr($now, 0, 10);
        $rows = $this->db->query("SELECT owner_id, COUNT(*) n FROM followups f JOIN leads l ON l.id = f.lead_id AND l.deleted_at IS NULL
            WHERE f.status = 'pending' AND f.due_at < DATE_SUB(?, INTERVAL 30 MINUTE) GROUP BY owner_id", [$now])->getResultArray();
        foreach ($rows as $r) {
            $uid = (int) $r['owner_id'];
            if ($this->notifier->notify($uid, 'crm', (int) $r['n'] . ' overdue follow-up(s)', 'Clear them from My Day — overdue leads go cold.', site_url('crm/followups?tab=overdue'), null, null, 'overdue:' . $uid . ':' . $day)) {
                $out['overdue_digests']++;
            }
            $mgr = (int) ($this->db->table('user_profiles')->select('manager_user_id')->where('user_id', $uid)->get()->getRow()->manager_user_id ?? 0);
            if ($mgr > 0) {
                $name = $this->db->table('user_profiles')->select('full_name')->where('user_id', $uid)->get()->getRow()->full_name ?? 'A team member';
                $this->notifier->notify($mgr, 'crm', $name . ' has ' . (int) $r['n'] . ' overdue follow-up(s)', null, site_url('crm/followups?tab=overdue&owner=' . $uid), null, null, 'overdue-mgr:' . $uid . ':' . $day);
            }
        }

        // 3. Visits whose time has passed without an outcome
        foreach ($this->db->table('visits v')->select('v.*, l.company_name')->join('leads l', 'l.id = v.lead_id', 'left')->where('v.status', 'scheduled')
            ->where('v.scheduled_at <', date('Y-m-d H:i:s', strtotime($now) - 7200))->get()->getResultArray() as $v) {
            $title = 'Visit to ' . ($v['company_name'] ?? $v['location'] ?? 'client') . ' — completed? Record outcome, client feedback and next action';
            foreach (array_unique(array_filter([(int) $v['visitor_id'], (int) $v['created_by']])) as $uid) {
                if ($this->notifier->notify($uid, 'crm', $title, null, site_url('crm/visits/' . $v['id']), 'visit', (int) $v['id'], 'visit-outcome:' . $v['id'] . ':' . $uid . ':' . $day)) {
                    $out['visit_nudges']++;
                }
            }
        }

        // 4. Recurring expense bills coming due
        foreach ($this->db->table('recurring_expenses')->where('is_active', 1)->get()->getResultArray() as $re) {
            $daysLeft = (int) floor((strtotime($re['next_due_date']) - strtotime($day)) / 86400);
            if ($daysLeft <= (int) $re['reminder_days_before'] && $re['created_by']) {
                if ($this->notifier->notify((int) $re['created_by'], 'finance', 'Bill due ' . dmy($re['next_due_date']) . ': ' . $re['name'], 'Record the actual bill when it arrives.', site_url('finance/expenses/new?recurring=' . $re['id']), 'recurring_expense', (int) $re['id'], 'rexp:' . $re['id'] . ':' . $re['next_due_date'])) {
                    $out['expense_reminders']++;
                }
            }
        }

        return $out;
    }

    /** @return array{0: bool, 1: string, 2: string|null} [still open, title, link] */
    private function describe(string $type, int $id): array
    {
        switch ($type) {
            case 'followup':
                $f = $this->db->table('followups f')->select('f.*, l.company_name, l.deleted_at')->join('leads l', 'l.id = f.lead_id')->where('f.id', $id)->get()->getRowArray();

                return [$f && $f['status'] === 'pending' && $f['deleted_at'] === null, 'Call ' . ($f['company_name'] ?? '') . ' at ' . ($f ? date('h:i A', strtotime($f['due_at'])) : '') . ': ' . ($f['title'] ?? ''), $f ? site_url('crm/leads/' . $f['lead_id']) : null];
            case 'visit':
                $v = $this->db->table('visits v')->select('v.*, l.company_name')->join('leads l', 'l.id = v.lead_id', 'left')->where('v.id', $id)->get()->getRowArray();

                return [$v && $v['status'] === 'scheduled', 'Visit at ' . ($v ? date('h:i A', strtotime($v['scheduled_at'])) : '') . ': ' . ($v['company_name'] ?? $v['location'] ?? ''), site_url('crm/visits/' . $id)];
            case 'meeting':
                $m = $this->db->table('meetings')->where('id', $id)->get()->getRowArray();

                return [$m && $m['status'] === 'scheduled', 'Meeting at ' . ($m ? date('h:i A', strtotime($m['meeting_at'])) : '') . ': ' . ($m['title'] ?? ''), site_url('crm/meetings')];
            case 'task':
                $t = $this->db->table('tasks')->where('id', $id)->get()->getRowArray();

                return [$t && in_array($t['status'], ['pending', 'in_progress'], true), 'Task due' . ($t && $t['due_at'] ? ' ' . dmy($t['due_at'], true) : '') . ': ' . ($t['title'] ?? ''), site_url('crm/tasks')];
            default:
                return [false, '', null];
        }
    }
}

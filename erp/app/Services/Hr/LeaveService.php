<?php

namespace App\Services\Hr;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Leave types, yearly balances, requests and the approval workflow.
 * Approved leave writes Leave/Half-day rows into attendance (linked to the
 * request) so payroll sees paid and unpaid leave days from one source.
 */
class LeaveService
{
    public function __construct(private readonly BaseConnection $db, private readonly HrCalendar $calendar)
    {
    }

    private function uid(): ?int
    {
        return service('authz')->currentUserId();
    }

    private function tx(callable $fn): mixed
    {
        $this->db->transBegin();
        try {
            $r = $fn();
            $this->db->transCommit();

            return $r;
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> id => leave type */
    public function types(bool $activeOnly = true): array
    {
        $b = $this->db->table('leave_types')->orderBy('code');
        if ($activeOnly) {
            $b->where('is_active', 1);
        }

        return array_column($b->get()->getResultArray(), null, 'id');
    }

    /**
     * Create missing balance rows for the FY containing $date. New joiners get
     * the annual quota pro-rated by whole months remaining (rounded to 0.5);
     * carry-forward types bring over the unused prior-year balance up to max.
     */
    public function ensureBalances(int $employeeId, string $date): void
    {
        $emp = $this->db->table('employees')->select('date_of_joining')->where('id', $employeeId)->get()->getRowArray();
        if ($emp === null) {
            return;
        }
        $fy = service('fy')->forDate($date);
        foreach ($this->types() as $t) {
            $exists = $this->db->table('leave_balances')->where(['employee_id' => $employeeId, 'leave_type_id' => $t['id'], 'financial_year_id' => $fy['id']])->countAllResults();
            if ($exists > 0) {
                continue;
            }
            $entitled = (string) $t['annual_quota'];
            if ($emp['date_of_joining'] > $fy['start_date'] && Dec::isPositive($entitled)) {
                $months   = max(0, (int) ((strtotime($fy['end_date']) - strtotime(date('Y-m-01', strtotime($emp['date_of_joining'])))) / (86400 * 30.44)) + 1);
                $months   = min(12, $months);
                $raw      = (float) $entitled * $months / 12;
                $entitled = number_format(round($raw * 2) / 2, 1, '.', '');
            }
            $carried = '0.0';
            if ((int) $t['carry_forward'] === 1) {
                $prev = $this->db->table('leave_balances lb')->select('lb.*')->join('financial_years f', 'f.id = lb.financial_year_id')
                    ->where(['lb.employee_id' => $employeeId, 'lb.leave_type_id' => $t['id']])->where('f.end_date <', $fy['start_date'])->orderBy('f.end_date', 'DESC')->get(1)->getRowArray();
                if ($prev !== null) {
                    $left    = Dec::sub(Dec::add(Dec::add($prev['entitled'], $prev['carried']), $prev['adjusted']), $prev['used']);
                    $carried = Dec::isPositive($left) ? number_format(min((float) $left, (float) $t['max_carry']), 1, '.', '') : '0.0';
                }
            }
            $this->db->table('leave_balances')->insert([
                'employee_id' => $employeeId, 'leave_type_id' => $t['id'], 'financial_year_id' => $fy['id'],
                'entitled' => $entitled, 'carried' => $carried, 'adjusted' => '0.0', 'used' => '0.0', 'created_by' => $this->uid(),
            ]);
        }
    }

    /** @return list<array<string, mixed>> balances with type info and pending days for the FY of $date */
    public function balances(int $employeeId, ?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $this->ensureBalances($employeeId, $date);
        $fy   = service('fy')->forDate($date);
        $rows = $this->db->table('leave_balances lb')->select('lb.*, lt.code, lt.name, lt.is_paid, lt.annual_quota')
            ->join('leave_types lt', 'lt.id = lb.leave_type_id')->where(['lb.employee_id' => $employeeId, 'lb.financial_year_id' => $fy['id']])
            ->where('lt.is_active', 1)->orderBy('lt.code')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['pending']   = (string) ($this->db->query("SELECT COALESCE(SUM(days),0) d FROM leave_requests WHERE employee_id = ? AND leave_type_id = ? AND status = 'pending' AND from_date BETWEEN ? AND ?",
                [$employeeId, $r['leave_type_id'], $fy['start_date'], $fy['end_date']])->getRow()->d ?? '0');
            $r['available'] = Dec::sub(Dec::add(Dec::add($r['entitled'], $r['carried']), $r['adjusted']), $r['used']);
            $r['limited']   = (int) $r['is_paid'] === 1;
        }

        return $rows;
    }

    /**
     * balances() for many employees with a fixed number of queries (the
     * balances screen used to run ~15 queries per employee).
     *
     * @param list<int> $employeeIds
     *
     * @return array<int, list<array<string, mixed>>> employee id => balances() rows
     */
    public function balancesFor(array $employeeIds, ?string $date = null): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }
        $date ??= date('Y-m-d');
        $fy    = service('fy')->forDate($date);
        $types = count($this->types());
        $have  = array_column($this->db->table('leave_balances')->select('employee_id, COUNT(*) n')->where('financial_year_id', $fy['id'])
            ->whereIn('employee_id', $employeeIds)->groupBy('employee_id')->get()->getResultArray(), 'n', 'employee_id');
        foreach ($employeeIds as $id) {
            if ((int) ($have[$id] ?? 0) < $types) {
                $this->ensureBalances($id, $date); // only employees still missing a balance row (new joiners, new leave types)
            }
        }
        $pending = [];
        foreach ($this->db->query("SELECT employee_id, leave_type_id, COALESCE(SUM(days),0) d FROM leave_requests WHERE status = 'pending' AND from_date BETWEEN ? AND ? AND employee_id IN ? GROUP BY employee_id, leave_type_id",
            [$fy['start_date'], $fy['end_date'], $employeeIds])->getResultArray() as $p) {
            $pending[(int) $p['employee_id']][(int) $p['leave_type_id']] = (string) $p['d'];
        }
        $out = array_fill_keys($employeeIds, []);
        $rows = $this->db->table('leave_balances lb')->select('lb.*, lt.code, lt.name, lt.is_paid, lt.annual_quota')
            ->join('leave_types lt', 'lt.id = lb.leave_type_id')->where('lb.financial_year_id', $fy['id'])->whereIn('lb.employee_id', $employeeIds)
            ->where('lt.is_active', 1)->orderBy('lb.employee_id')->orderBy('lt.code')->get()->getResultArray();
        foreach ($rows as $r) {
            $r['pending']   = $pending[(int) $r['employee_id']][(int) $r['leave_type_id']] ?? '0';
            $r['available'] = Dec::sub(Dec::add(Dec::add($r['entitled'], $r['carried']), $r['adjusted']), $r['used']);
            $r['limited']   = (int) $r['is_paid'] === 1;
            $out[(int) $r['employee_id']][] = $r;
        }

        return $out;
    }

    /** Working days in a leave (holidays and weekly offs are not counted). */
    public function countDays(string $from, string $to, string $halfDay = 'none'): string
    {
        if ($halfDay !== 'none') {
            return $this->calendar->dayKind($from) === null ? '0.5' : '0.0';
        }

        return number_format($this->calendar->workingDays($from, $to), 1, '.', '');
    }

    /** @param array<string, mixed> $in */
    public function request(int $employeeId, array $in): int
    {
        $type = $this->types()[(int) ($in['leave_type_id'] ?? 0)] ?? null;
        $from = ymd((string) ($in['from_date'] ?? ''));
        $to   = ymd((string) ($in['to_date'] ?? '')) ?? $from;
        $half = in_array($in['half_day'] ?? 'none', ['first_half', 'second_half'], true) ? $in['half_day'] : 'none';
        $errors = [];
        if ($type === null) {
            $errors['leave_type_id'] = 'Choose the leave type.';
        }
        if ($from === null) {
            $errors['from_date'] = 'Choose the start date.';
        }
        if ($from !== null && $to < $from) {
            $errors['to_date'] = 'End date is before the start date.';
        }
        if ($half !== 'none' && $from !== $to) {
            $errors['half_day'] = 'Half-day leave must start and end on the same date.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the leave request.', $errors);
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
            throw new BusinessRuleException('A single leave request cannot exceed 3 months.');
        }
        $days = $this->countDays($from, $to, $half);
        if (! Dec::isPositive($days)) {
            throw new BusinessRuleException('The selected dates are holidays/weekly offs — no leave needed.');
        }
        $overlap = $this->db->table('leave_requests')->where('employee_id', $employeeId)->whereIn('status', ['pending', 'approved'])
            ->where('from_date <=', $to)->where('to_date >=', $from)->countAllResults();
        if ($overlap > 0) {
            throw new BusinessRuleException('These dates overlap another pending or approved leave request.');
        }
        if ((int) $type['is_paid'] === 1) {
            $bal = array_values(array_filter($this->balances($employeeId, $from), static fn ($b) => (int) $b['leave_type_id'] === (int) $type['id']))[0] ?? null;
            $free = $bal ? Dec::sub($bal['available'], $bal['pending']) : '0';
            if (Dec::cmp($free, $days) < 0 && ! service('settingsStore')->get('hr.leave_allow_negative', false)) {
                throw new BusinessRuleException(sprintf('Not enough %s balance: %s day(s) available, %s requested. Choose Leave Without Pay for the extra days.', $type['name'], Dec::max($free, '0'), $days));
            }
        }

        return $this->tx(function () use ($employeeId, $type, $from, $to, $half, $days, $in): int {
            $this->db->table('leave_requests')->insert([
                'employee_id' => $employeeId, 'leave_type_id' => $type['id'], 'from_date' => $from, 'to_date' => $to, 'half_day' => $half, 'days' => $days,
                'reason' => mb_substr(trim((string) ($in['reason'] ?? '')), 0, 500) ?: null, 'status' => 'pending', 'created_by' => $this->uid(),
            ]);
            $id  = (int) $this->db->insertID();
            $emp = $this->db->table('employees')->where('id', $employeeId)->get()->getRowArray();
            foreach ($this->approverUserIds($emp) as $uid) {
                service('notifier')->notify($uid, 'hr', 'Leave request: ' . $emp['full_name'] . ' — ' . $days . ' day(s) ' . $type['code'], dmy($from) . ($to !== $from ? ' to ' . dmy($to) : ''), site_url('hr/leaves?status=pending'), 'leave_request', $id, 'leave-req:' . $id . ':' . $uid);
            }
            service('audit')->log('leave.requested', 'leave_request', $id, null, ['employee_id' => $employeeId, 'type' => $type['code'], 'from' => $from, 'to' => $to, 'days' => $days], 'Leave requested');

            return $id;
        });
    }

    /** @return list<int> reporting manager's login, else users holding hr.leave.approve */
    private function approverUserIds(array $emp): array
    {
        $ids = [];
        if ($emp['reporting_manager_id']) {
            $mgrUser = $this->db->table('employees')->select('user_id')->where('id', $emp['reporting_manager_id'])->get()->getRow()->user_id ?? null;
            if ($mgrUser) {
                $ids[] = (int) $mgrUser;
            }
        }
        if ($ids === []) {
            foreach ($this->db->table('users')->select('id')->where('active', 1)->where('deleted_at', null)->get()->getResultArray() as $u) {
                if ((int) $u['id'] !== (int) $emp['user_id'] && service('authz')->can('hr.leave.approve', (int) $u['id'])) {
                    $ids[] = (int) $u['id'];
                }
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, 10);
    }

    /** @return array<string, mixed> */
    private function load(int $id): array
    {
        return $this->db->table('leave_requests')->where('id', $id)->get()->getRowArray() ?? throw new BusinessRuleException('Leave request not found.');
    }

    public function decide(int $id, string $decision, string $note = ''): void
    {
        service('authz')->authorize('hr.leave.approve');
        $req = $this->load($id);
        if ($req['status'] !== 'pending') {
            throw new BusinessRuleException('This request was already ' . $req['status'] . '.');
        }
        $emp = $this->db->table('employees')->where('id', $req['employee_id'])->get()->getRowArray();
        if ((int) $emp['user_id'] === (int) $this->uid() && ! service('authz')->isSuper()) {
            throw new AccessDeniedException('You cannot approve or reject your own leave.');
        }
        if ($decision === 'rejected' && trim($note) === '') {
            throw new BusinessRuleException('Give a reason for rejecting the leave.', ['decision_note' => 'Required']);
        }
        $this->tx(function () use ($req, $emp, $decision, $note): void {
            $this->db->table('leave_requests')->where('id', $req['id'])->update([
                'status' => $decision === 'approved' ? 'approved' : 'rejected', 'decided_by' => $this->uid(), 'decided_at' => date('Y-m-d H:i:s'),
                'decision_note' => mb_substr($note, 0, 500) ?: null, 'updated_by' => $this->uid(),
            ]);
            if ($decision === 'approved') {
                $this->assertNotLocked($req['from_date'], $req['to_date']);
                $this->ensureBalances((int) $req['employee_id'], $req['from_date']);
                $fy = service('fy')->forDate($req['from_date']);
                $this->db->query('UPDATE leave_balances SET used = used + ?, updated_by = ? WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?',
                    [$req['days'], $this->uid(), $req['employee_id'], $req['leave_type_id'], $fy['id']]);
                $this->writeAttendance($req);
            }
            if ($emp['user_id']) {
                service('notifier')->notify((int) $emp['user_id'], 'hr', 'Your leave ' . dmy($req['from_date']) . ' was ' . ($decision === 'approved' ? 'approved' : 'rejected'), $note ?: null, site_url('me/leaves'), 'leave_request', (int) $req['id'], 'leave-dec:' . $req['id']);
            }
            service('audit')->log('leave.' . ($decision === 'approved' ? 'approved' : 'rejected'), 'leave_request', (int) $req['id'], ['status' => 'pending'], ['status' => $decision, 'note' => $note], 'Leave ' . $decision);
        });
    }

    /** Mark the leave's working days in attendance (Leave or Half Day), linked to the request. */
    private function writeAttendance(array $req): void
    {
        foreach (HrCalendar::dates($req['from_date'], $req['to_date']) as $d) {
            if ($this->calendar->dayKind($d) !== null) {
                continue;
            }
            $status   = $req['half_day'] !== 'none' ? 'half_day' : 'leave';
            $existing = $this->db->table('attendance')->where(['employee_id' => $req['employee_id'], 'att_date' => $d])->get()->getRowArray();
            $row      = ['status' => $status, 'leave_request_id' => $req['id'], 'source' => 'system', 'remarks' => 'Approved leave', 'updated_by' => $this->uid()];
            if ($existing === null) {
                $this->db->table('attendance')->insert($row + ['employee_id' => $req['employee_id'], 'att_date' => $d, 'created_by' => $this->uid()]);
            } else {
                $this->db->table('attendance')->where('id', $existing['id'])->update($row + ['corrected' => 1, 'correction_reason' => 'Approved leave replaced earlier mark (' . $existing['status'] . ')']);
            }
        }
    }

    /** Cancel: the employee may cancel their own pending request; HR may cancel an approved one (balance restored). */
    public function cancel(int $id, string $reason = ''): void
    {
        $req = $this->load($id);
        $emp = $this->db->table('employees')->where('id', $req['employee_id'])->get()->getRowArray();
        $own = (int) $emp['user_id'] === (int) $this->uid();
        if ($req['status'] === 'pending') {
            if (! $own && ! service('authz')->can('hr.leave.approve')) {
                throw new AccessDeniedException();
            }
        } elseif ($req['status'] === 'approved') {
            service('authz')->authorize('hr.leave.approve');
            if (trim($reason) === '') {
                throw new BusinessRuleException('Give a reason for cancelling an approved leave.');
            }
            $this->assertNotLocked($req['from_date'], $req['to_date']);
        } else {
            throw new BusinessRuleException('Only pending or approved requests can be cancelled.');
        }
        $this->tx(function () use ($req, $reason): void {
            if ($req['status'] === 'approved') {
                $fy = service('fy')->forDate($req['from_date']);
                $this->db->query('UPDATE leave_balances SET used = GREATEST(used - ?, 0), updated_by = ? WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?',
                    [$req['days'], $this->uid(), $req['employee_id'], $req['leave_type_id'], $fy['id']]);
                $this->db->table('attendance')->where('leave_request_id', $req['id'])->delete();
            }
            $this->db->table('leave_requests')->where('id', $req['id'])->update(['status' => 'cancelled', 'decision_note' => mb_substr($reason, 0, 500) ?: $req['decision_note'], 'updated_by' => $this->uid()]);
            service('audit')->log('leave.cancelled', 'leave_request', (int) $req['id'], ['status' => $req['status']], ['status' => 'cancelled', 'reason' => $reason], 'Leave cancelled');
        });
    }

    /** Manual balance adjustment (e.g. opening balance, comp-off) with reason. */
    public function adjust(int $employeeId, int $typeId, string $days, string $reason, ?string $date = null): void
    {
        service('authz')->authorize('hr.leave.manage_types');
        if (! is_numeric($days) || Dec::isZero($days) || trim($reason) === '') {
            throw new BusinessRuleException('Enter the days (e.g. 2 or -1) and a reason.');
        }
        $date ??= date('Y-m-d');
        $this->ensureBalances($employeeId, $date);
        $fy = service('fy')->forDate($date);
        $this->db->query('UPDATE leave_balances SET adjusted = adjusted + ?, updated_by = ? WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?', [$days, $this->uid(), $employeeId, $typeId, $fy['id']]);
        service('audit')->log('leave.balance_adjusted', 'employee', $employeeId, null, ['leave_type_id' => $typeId, 'days' => $days, 'reason' => $reason], 'Leave balance adjusted');
    }

    public function assertNotLocked(string $from, string $to): void
    {
        $locked = $this->db->table('payroll_runs')->select('period')->where('is_locked', 1)->where('period_start <=', $to)->where('period_end >=', $from)->get()->getRowArray();
        if ($locked !== null) {
            throw new BusinessRuleException('Payroll for ' . $locked['period'] . ' is finalized and locked. Record the change as a payroll adjustment instead.');
        }
    }

    /** Calendar edits inside a locked payroll month would silently change history; refuse them. */
    public function assertNotLockedSafe(string $date): void
    {
        $this->assertNotLocked($date, $date);
    }
}

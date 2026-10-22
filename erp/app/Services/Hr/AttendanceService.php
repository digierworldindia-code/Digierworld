<?php

namespace App\Services\Hr;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\BusinessRuleException;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Manual daily attendance (no hardware needed) with optional self check-in.
 * The first mark of a day is free; changing an existing mark is a
 * correction that needs hr.attendance.correct and a reason, and is audited.
 * Days inside a finalized (locked) payroll period cannot be changed.
 */
class AttendanceService
{
    public const STATUSES = ['present' => 'Present', 'absent' => 'Absent', 'half_day' => 'Half day', 'leave' => 'Leave', 'holiday' => 'Holiday', 'week_off' => 'Week off'];

    public function __construct(private readonly BaseConnection $db, private readonly HrCalendar $calendar)
    {
    }

    private function uid(): ?int
    {
        return service('authz')->currentUserId();
    }

    /**
     * Rows for the daily sheet: every employee employed on $date with the
     * existing mark or a suggested default (holiday / week off / approved leave).
     *
     * @return list<array<string, mixed>>
     */
    public function daySheet(string $date, ?int $departmentId = null): array
    {
        $b = $this->db->table('employees e')->select('e.id, e.employee_code, e.full_name, e.date_of_joining, e.exit_date, e.attendance_mode, d.name AS department')
            ->join('departments d', 'd.id = e.department_id', 'left')->where('e.date_of_joining <=', $date)
            ->groupStart()->where('e.exit_date', null)->orWhere('e.exit_date >=', $date)->groupEnd()->orderBy('e.full_name');
        if ($departmentId) {
            $b->where('e.department_id', $departmentId);
        }
        $emps     = $b->get()->getResultArray();
        $existing = array_column($this->db->table('attendance')->where('att_date', $date)->whereIn('employee_id', array_column($emps, 'id') ?: [0])->get()->getResultArray(), null, 'employee_id');
        $kind     = $this->calendar->dayKind($date);
        foreach ($emps as &$e) {
            $row          = $existing[$e['id']] ?? null;
            $e['mark']    = $row;
            $e['suggest'] = $row['status'] ?? ($kind ?? 'present');
        }

        return $emps;
    }

    /**
     * Save the daily sheet.
     *
     * @param array<int|string, array<string, mixed>> $rows employee_id => [status, check_in, check_out, overtime_minutes, remarks]
     *
     * @return array{created: int, corrected: int, unchanged: int}
     */
    public function markDay(string $date, array $rows, string $reason = ''): array
    {
        service('authz')->authorize('hr.attendance.mark');
        if ($date > date('Y-m-d')) {
            throw new BusinessRuleException('Attendance cannot be marked for a future date.');
        }
        service('leaves')->assertNotLocked($date, $date);
        $existing = array_column($this->db->table('attendance')->where('att_date', $date)->get()->getResultArray(), null, 'employee_id');
        $emps     = array_column($this->db->table('employees')->select('id, full_name, date_of_joining, exit_date')->whereIn('id', array_map('intval', array_keys($rows)) ?: [0])->get()->getResultArray(), null, 'id');
        $changes  = [];
        foreach ($rows as $empId => $r) {
            $empId  = (int) $empId;
            $status = (string) ($r['status'] ?? '');
            if (! isset($emps[$empId]) || $status === '' || ! EmployeeService::employedOn($emps[$empId], $date)) {
                continue;
            }
            if (! isset(self::STATUSES[$status])) {
                throw new BusinessRuleException('Unknown attendance status for ' . $emps[$empId]['full_name'] . '.');
            }
            $data = [
                'status' => $status,
                'check_in_at' => $this->time($date, $r['check_in'] ?? null), 'check_out_at' => $this->time($date, $r['check_out'] ?? null),
                'overtime_minutes' => max(0, min(900, (int) ($r['overtime_minutes'] ?? 0))),
                'remarks' => mb_substr(trim((string) ($r['remarks'] ?? '')), 0, 255) ?: null,
            ];
            if ($data['check_in_at'] && $data['check_out_at'] && $data['check_out_at'] < $data['check_in_at']) {
                throw new BusinessRuleException('Check-out is before check-in for ' . $emps[$empId]['full_name'] . '.');
            }
            $old = $existing[$empId] ?? null;
            if ($old !== null && $old['status'] === $data['status'] && (string) $old['check_in_at'] === (string) $data['check_in_at']
                && (string) $old['check_out_at'] === (string) $data['check_out_at'] && (int) $old['overtime_minutes'] === $data['overtime_minutes'] && (string) $old['remarks'] === (string) $data['remarks']) {
                continue;
            }
            $changes[$empId] = [$old, $data];
        }
        $corrections = array_filter($changes, static fn ($c) => $c[0] !== null);
        if ($corrections !== []) {
            if (! service('authz')->can('hr.attendance.correct')) {
                throw new AccessDeniedException('Changing attendance that is already marked needs the “Correct attendance” permission.');
            }
            if (mb_strlen(trim($reason)) < 5) {
                throw new BusinessRuleException('Give a reason for correcting ' . count($corrections) . ' existing mark(s): ' . implode(', ', array_map(static fn ($id) => $emps[$id]['full_name'], array_keys($corrections))) . '.', ['reason' => 'Required for corrections']);
            }
        }
        $out = ['created' => 0, 'corrected' => 0, 'unchanged' => count($rows) - count($changes)];
        $this->db->transBegin();
        try {
            foreach ($changes as $empId => [$old, $data]) {
                if ($old === null) {
                    $this->db->table('attendance')->insert($data + ['employee_id' => $empId, 'att_date' => $date, 'source' => 'manual', 'created_by' => $this->uid()]);
                    $out['created']++;

                    continue;
                }
                if ($old['leave_request_id'] && ! in_array($data['status'], ['leave', 'half_day'], true)) {
                    throw new BusinessRuleException($emps[$empId]['full_name'] . ' has approved leave on this date. Cancel the leave first, then correct attendance.');
                }
                $this->db->table('attendance')->where('id', $old['id'])->update($data + ['corrected' => 1, 'correction_reason' => mb_substr(trim($reason), 0, 500), 'source' => 'manual', 'updated_by' => $this->uid()]);
                service('audit')->log('attendance.corrected', 'attendance', (int) $old['id'], array_intersect_key($old, $data), $data, 'Attendance corrected for ' . $emps[$empId]['full_name'] . ' on ' . dmy($date), ['reason' => trim($reason)]);
                $out['corrected']++;
            }
            if ($out['created'] > 0) {
                service('audit')->log('attendance.marked', 'attendance', null, null, ['date' => $date, 'count' => $out['created']], 'Attendance marked for ' . dmy($date));
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $out;
    }

    private function time(string $date, mixed $hm): ?string
    {
        $hm = trim((string) $hm);
        if ($hm === '') {
            return null;
        }
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $hm)) {
            throw new BusinessRuleException('Times must be HH:MM (24-hour).');
        }

        return $date . ' ' . $hm . ':00';
    }

    /** Self check-in / check-out for employees on self check-in mode. Returns the action taken. */
    public function selfPunch(array $employee): string
    {
        if ($employee['attendance_mode'] !== 'self_checkin' || ! service('authz')->can('self.attendance.checkin')) {
            throw new AccessDeniedException('Self check-in is not enabled for you. Your attendance is marked by HR.');
        }
        $today = date('Y-m-d');
        service('leaves')->assertNotLocked($today, $today);
        $row = $this->db->table('attendance')->where(['employee_id' => $employee['id'], 'att_date' => $today])->get()->getRowArray();
        $now = date('Y-m-d H:i:s');
        if ($row === null) {
            $this->db->table('attendance')->insert(['employee_id' => $employee['id'], 'att_date' => $today, 'status' => 'present', 'check_in_at' => $now, 'source' => 'self', 'created_by' => $this->uid()]);

            return 'checked_in';
        }
        if ($row['leave_request_id']) {
            throw new BusinessRuleException('You are on approved leave today.');
        }
        if ($row['check_out_at'] === null && $row['check_in_at'] !== null) {
            $this->db->table('attendance')->where('id', $row['id'])->update(['check_out_at' => $now, 'updated_by' => $this->uid()]);

            return 'checked_out';
        }
        throw new BusinessRuleException('Today’s attendance is already complete. Ask HR to correct it if needed.');
    }

    /**
     * Day counts for an employee between two dates (within employment),
     * used by payroll and the employee 360 view.
     *
     * @return array<string, float|int|list<string>>
     */
    /**
     * Attendance rows (with whether the linked leave is paid) for these
     * employees and dates, in one query.
     *
     * @param list<int> $employeeIds
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $employeeIds, string $from, string $to): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return $this->db->table('attendance a')->select('a.*, lt.is_paid AS leave_paid')
            ->join('leave_requests lr', 'lr.id = a.leave_request_id', 'left')->join('leave_types lt', 'lt.id = lr.leave_type_id', 'left')
            ->whereIn('a.employee_id', $employeeIds)->where('a.att_date >=', $from)->where('a.att_date <=', $to)->get()->getResultArray();
    }

    /**
     * @param list<array<string, mixed>>|null $preloaded this employee's attendance rows for the range (with leave_paid), when the caller loaded many at once
     */
    public function summary(array $employee, string $from, string $to, ?array $preloaded = null): array
    {
        $s    = ['days' => 0, 'outside' => 0, 'present' => 0.0, 'half_day' => 0, 'absent' => 0.0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0,
            'holiday' => 0, 'week_off' => 0, 'worked_holiday' => 0, 'unmarked' => 0, 'unmarked_dates' => [], 'overtime_minutes' => 0, 'working_days' => 0, 'leave_without_request' => 0];
        $rows = array_column($preloaded ?? $this->rows([(int) $employee['id']], $from, $to), null, 'att_date');
        foreach (HrCalendar::dates($from, $to) as $d) {
            $s['days']++;
            if (! EmployeeService::employedOn($employee, $d)) {
                $s['outside']++;

                continue;
            }
            $kind = $this->calendar->dayKind($d);
            if ($kind === null) {
                $s['working_days']++;
            }
            $r = $rows[$d] ?? null;
            if ($r === null) {
                if ($kind !== null) {
                    $s[$kind]++;
                } else {
                    $s['unmarked']++;
                    $s['unmarked_dates'][] = $d;
                }

                continue;
            }
            $s['overtime_minutes'] += (int) $r['overtime_minutes'];
            // Leave without an approved request is treated as unpaid and flagged.
            $paidLeave = $r['leave_request_id'] ? (int) $r['leave_paid'] === 1 : false;
            if ($r['status'] === 'leave' && ! $r['leave_request_id']) {
                $s['leave_without_request']++;
            }
            switch ($r['status']) {
                case 'present':
                    if ($kind !== null) {
                        $s['worked_holiday']++;
                        $s[$kind]++;
                    } else {
                        $s['present']++;
                    }
                    break;
                case 'half_day':
                    $s['half_day']++;
                    $s['present'] += 0.5;
                    if ($r['leave_request_id']) {
                        $s[$paidLeave ? 'paid_leave' : 'unpaid_leave'] += 0.5;
                    } else {
                        $s['absent'] += 0.5;
                    }
                    break;
                case 'leave':
                    $s[$paidLeave ? 'paid_leave' : 'unpaid_leave'] += 1;
                    break;
                case 'holiday':
                case 'week_off':
                    $s[$r['status']]++;
                    break;
                default:
                    $s['absent'] += 1;
            }
        }

        return $s;
    }
}

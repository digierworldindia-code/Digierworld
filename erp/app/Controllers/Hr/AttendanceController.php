<?php

namespace App\Controllers\Hr;

use App\Exceptions\BusinessRuleException;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\HrCalendar;

/** Daily attendance sheet (manual mode) and the monthly register. */
class AttendanceController extends HrBase
{
    public function index()
    {
        $this->authorize(['hr.attendance.mark', 'hr.attendance.view_all']);
        $date = ymd((string) $this->request->getGet('date')) ?? date('Y-m-d');
        $dept = (int) $this->request->getGet('department') ?: null;
        $rows = service('attendance')->daySheet($date, $dept);
        $cal  = service('hrCalendar');

        return $this->render('hr/attendance/day', [
            'pageTitle' => 'Attendance — ' . dmy($date), 'breadcrumbs' => [['HR'], ['Attendance']],
            'date' => $date, 'rows' => $rows, 'department' => $dept, 'departments' => $this->departments(),
            'dayKind' => $cal->dayKind($date), 'holidayName' => $cal->holiday($date), 'statuses' => AttendanceService::STATUSES,
            'locked' => $this->db->table('payroll_runs')->where('is_locked', 1)->where('period_start <=', $date)->where('period_end >=', $date)->countAllResults() > 0,
        ]);
    }

    public function save()
    {
        $this->authorize('hr.attendance.mark');
        $date = ymd((string) $this->request->getPost('date'));
        if ($date === null) {
            return redirect()->back()->with('error', 'Choose a date.');
        }
        try {
            $r = service('attendance')->markDay($date, (array) $this->request->getPost('att'), (string) $this->request->getPost('reason'));
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('hr/attendance?date=' . $date)->with('success', sprintf('Saved: %d new, %d corrected, %d unchanged.', $r['created'], $r['corrected'], $r['unchanged']));
    }

    public function month()
    {
        $this->authorize('hr.attendance.view_all');
        $month = $this->monthParam();
        $from  = $month . '-01';
        $to    = date('Y-m-t', strtotime($from));
        $dates = HrCalendar::dates($from, $to);
        $emps  = $this->db->table('employees')->where('date_of_joining <=', $to)->groupStart()->where('exit_date', null)->orWhere('exit_date >=', $from)->groupEnd()->orderBy('full_name')->get()->getResultArray();
        $marks = [];
        foreach ($this->db->table('attendance')->select('employee_id, att_date, status, corrected')->where('att_date >=', $from)->where('att_date <=', $to)->get()->getResultArray() as $a) {
            $marks[$a['employee_id']][$a['att_date']] = $a;
        }
        $summaries = [];
        $until     = min($to, date('Y-m-d'));
        $byEmp     = [];
        foreach (service('attendance')->rows(array_map(static fn ($e) => (int) $e['id'], $emps), $from, $until) as $row) {
            $byEmp[(int) $row['employee_id']][] = $row;
        }
        foreach ($emps as $e) {
            $summaries[$e['id']] = service('attendance')->summary($e, $from, $until, $byEmp[(int) $e['id']] ?? []);
        }
        $cal = service('hrCalendar');
        $kinds = [];
        foreach ($dates as $d) {
            $kinds[$d] = $cal->dayKind($d);
        }

        return $this->render('hr/attendance/month', [
            'pageTitle' => 'Attendance register — ' . date('F Y', strtotime($from)), 'breadcrumbs' => [['HR'], ['Attendance', 'hr/attendance'], ['Register']],
            'month' => $month, 'dates' => $dates, 'emps' => $emps, 'marks' => $marks, 'summaries' => $summaries, 'kinds' => $kinds,
        ]);
    }
}

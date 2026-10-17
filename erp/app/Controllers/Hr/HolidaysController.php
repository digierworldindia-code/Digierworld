<?php

namespace App\Controllers\Hr;

use App\Exceptions\BusinessRuleException;

/** Company holiday calendar and weekly-off policy. */
class HolidaysController extends HrBase
{
    public function index()
    {
        $this->authorize('hr.holiday.manage');
        $year = (int) ($this->request->getGet('year') ?: date('Y'));

        return $this->render('hr/holidays', [
            'pageTitle' => 'Holidays & weekly offs', 'breadcrumbs' => [['HR'], ['Holidays']], 'year' => $year,
            'holidays' => $this->db->table('holidays')->where('holiday_date >=', $year . '-01-01')->where('holiday_date <=', $year . '-12-31')->orderBy('holiday_date')->get()->getResultArray(),
            'rules' => $this->db->table('weekly_off_rules')->orderBy('is_active', 'DESC')->orderBy('weekday')->get()->getResultArray(),
            'policy' => service('hrCalendar')->describeRules(),
        ]);
    }

    public function save()
    {
        $this->authorize('hr.holiday.manage');
        $date = ymd((string) $this->request->getPost('holiday_date'));
        $name = trim((string) $this->request->getPost('name'));
        if ($date === null || $name === '') {
            return redirect()->back()->withInput()->with('error', 'Enter the date and holiday name.');
        }
        try {
            service('leaves')->assertNotLockedSafe($date);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }
        $this->db->query('INSERT INTO holidays (holiday_date, name, is_optional, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), is_optional = VALUES(is_optional), updated_by = VALUES(created_by)',
            [$date, mb_substr($name, 0, 120), $this->request->getPost('is_optional') ? 1 : 0, $this->userId]);
        service('audit')->log('holiday.saved', 'holiday', null, null, ['date' => $date, 'name' => $name], 'Holiday saved: ' . $name . ' (' . dmy($date) . ')');

        return redirect()->to('hr/holidays?year=' . substr($date, 0, 4))->with('success', 'Holiday saved.');
    }

    public function delete($id)
    {
        $this->authorize('hr.holiday.manage');
        $h = $this->db->table('holidays')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        try {
            service('leaves')->assertNotLockedSafe($h['holiday_date']);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }
        $this->db->table('holidays')->where('id', $h['id'])->delete();
        service('audit')->log('holiday.deleted', 'holiday', (int) $h['id'], $h, null, 'Holiday removed: ' . $h['name']);

        return redirect()->back()->with('success', 'Holiday removed.');
    }

    public function saveRule()
    {
        $this->authorize('hr.holiday.manage');
        $weekday = (int) $this->request->getPost('weekday');
        $weeks   = array_values(array_filter(array_map('intval', (array) $this->request->getPost('weeks')), static fn ($w) => $w >= 1 && $w <= 5));
        $from    = ymd((string) $this->request->getPost('effective_from')) ?? date('Y-m-d');
        if ($weekday < 0 || $weekday > 6) {
            return redirect()->back()->with('error', 'Choose a weekday.');
        }
        $this->db->table('weekly_off_rules')->insert(['weekday' => $weekday, 'week_numbers' => $weeks === [] ? null : implode(',', $weeks), 'effective_from' => $from, 'is_active' => 1, 'created_by' => $this->userId]);
        service('audit')->log('weekly_off.added', 'weekly_off_rule', (int) $this->db->insertID(), null, ['weekday' => $weekday, 'weeks' => $weeks, 'from' => $from], 'Weekly off rule added');

        return redirect()->to('hr/holidays')->with('success', 'Weekly off rule added.');
    }

    public function toggleRule($id)
    {
        $this->authorize('hr.holiday.manage');
        $r = $this->db->table('weekly_off_rules')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $this->db->table('weekly_off_rules')->where('id', $r['id'])->update(['is_active' => $r['is_active'] ? 0 : 1, 'updated_by' => $this->userId]);
        service('audit')->log('weekly_off.toggled', 'weekly_off_rule', (int) $r['id'], ['is_active' => $r['is_active']], ['is_active' => $r['is_active'] ? 0 : 1], 'Weekly off rule ' . ($r['is_active'] ? 'disabled' : 'enabled'));

        return redirect()->to('hr/holidays')->with('success', 'Weekly off rule updated.');
    }
}

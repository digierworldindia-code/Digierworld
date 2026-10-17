<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use App\Services\Finance\LedgerService;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Monthly payroll.
 *
 *  draft/calculated → (re)calculate from attendance, leave, salary structure,
 *  approved incentives, reimbursements, advances and enabled statutory items;
 *  finalize → accrual journal (Dr salary/incentive/employer cost, Cr salary
 *  payable and statutory/advance accounts), lock, payslips;
 *  pay → payment journals (Dr salary payable, Cr bank/cash) — so salary is
 *  expensed once at accrual and payments only settle the liability;
 *  adjust (after lock) → audited correction with its own journal and a new
 *  payslip version. Locked runs cannot be recalculated or edited.
 */
class PayrollService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly LedgerService $ledger,
        private readonly AttendanceService $attendance,
        private readonly SalaryService $salaries,
    ) {
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

    /** @return array<string, mixed> */
    public function run(int $id, bool $forUpdate = false): array
    {
        $row = $forUpdate
            ? $this->db->query('SELECT * FROM payroll_runs WHERE id = ? FOR UPDATE', [$id])->getRowArray()
            : $this->db->table('payroll_runs')->where('id', $id)->get()->getRowArray();

        return $row ?? throw new BusinessRuleException('Payroll run not found.');
    }

    private function assertOpen(array $run): void
    {
        if ((int) $run['is_locked'] === 1) {
            throw new BusinessRuleException('Payroll for ' . $run['period'] . ' is finalized and locked. Use an audited adjustment instead.');
        }
    }

    public function createRun(string $period): int
    {
        service('authz')->authorize('hr.payroll.calculate');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $period > date('Y-m')) {
            throw new BusinessRuleException('Choose a month up to the current month.');
        }
        if ($this->db->table('payroll_runs')->where('period', $period)->countAllResults() > 0) {
            throw new BusinessRuleException('A payroll run for ' . $period . ' already exists.');
        }
        $this->db->table('payroll_runs')->insert(['period' => $period, 'period_start' => $period . '-01', 'period_end' => date('Y-m-t', strtotime($period . '-01')), 'status' => 'draft', 'created_by' => $this->uid()]);
        $id = (int) $this->db->insertID();
        service('audit')->log('payroll.run_created', 'payroll_run', $id, null, ['period' => $period], 'Payroll run created for ' . $period);

        return $id;
    }

    /** Delete an unlocked run and release everything it had picked up. */
    public function deleteRun(int $runId): void
    {
        service('authz')->authorize('hr.payroll.calculate');
        $this->tx(function () use ($runId): void {
            $run = $this->run($runId, true);
            $this->assertOpen($run);
            $items = array_column($this->db->table('payroll_items')->select('id')->where('payroll_run_id', $runId)->get()->getResultArray(), 'id');
            if ($items !== []) {
                $this->db->table('incentive_entries')->whereIn('payroll_item_id', $items)->where('status', 'included')->update(['status' => 'approved', 'payroll_item_id' => null]);
                $this->db->table('payroll_items')->where('payroll_run_id', $runId)->delete();
            }
            $this->db->table('payroll_runs')->where('id', $runId)->delete();
            service('audit')->log('payroll.run_deleted', 'payroll_run', $runId, ['period' => $run['period']], null, 'Draft payroll deleted for ' . $run['period']);
        });
    }

    /** Employees employed for at least one day in the period. @return list<array<string, mixed>> */
    private function employees(string $from, string $to): array
    {
        return $this->db->table('employees')->where('date_of_joining <=', $to)->groupStart()->where('exit_date', null)->orWhere('exit_date >=', $from)->groupEnd()
            ->orderBy('employee_code')->get()->getResultArray();
    }

    /**
     * (Re)calculate every employee line of an unlocked run.
     *
     * @return array{items: int, skipped: list<string>}
     */
    public function calculate(int $runId): array
    {
        service('authz')->authorize('hr.payroll.calculate');

        return $this->tx(function () use ($runId): array {
            $run = $this->run($runId, true);
            $this->assertOpen($run);
            service('hrCalendar')->reset();
            $old = array_column($this->db->table('payroll_items')->select('id, employee_id')->where('payroll_run_id', $runId)->get()->getResultArray(), 'id', 'employee_id');
            if ($old !== []) {
                $this->db->table('incentive_entries')->whereIn('payroll_item_id', array_values($old))->where('status', 'included')->update(['status' => 'approved', 'payroll_item_id' => null]);
                $this->db->table('payroll_item_components')->whereIn('payroll_item_id', array_values($old))->groupStart()->where('ref_type', null)->orWhere('ref_type !=', 'manual')->groupEnd()->delete();
            }
            $stat    = $this->salaries->statutory();
            $skipped = [];
            $seen    = [];
            foreach ($this->employees($run['period_start'], $run['period_end']) as $emp) {
                $ss = $this->salaries->effective((int) $emp['id'], $run['period_end']);
                if ($ss === null) {
                    $skipped[] = $emp['full_name'] . ' (no salary structure)';

                    continue;
                }
                $itemId = $old[$emp['id']] ?? null;
                $calc   = $this->calcEmployee($run, $emp, $ss, $stat, $itemId);
                if ($itemId === null) {
                    $this->db->table('payroll_items')->insert($calc['item'] + ['payroll_run_id' => $runId, 'employee_id' => $emp['id'], 'created_by' => $this->uid()]);
                    $itemId = (int) $this->db->insertID();
                } else {
                    $this->db->table('payroll_items')->where('id', $itemId)->update($calc['item'] + ['updated_by' => $this->uid()]);
                }
                foreach ($calc['components'] as $c) {
                    $this->db->table('payroll_item_components')->insert($c + ['payroll_item_id' => $itemId]);
                }
                if ($calc['incentive_ids'] !== []) {
                    $this->db->table('incentive_entries')->whereIn('id', $calc['incentive_ids'])->where('status', 'approved')->where('payroll_item_id', null)
                        ->update(['status' => 'included', 'payroll_item_id' => $itemId]);
                    if ($this->db->affectedRows() !== count($calc['incentive_ids'])) {
                        throw new BusinessRuleException('Incentive entries changed while calculating; please recalculate.');
                    }
                }
                $seen[] = $itemId;
            }
            $stale = array_diff(array_values($old), $seen);
            if ($stale !== []) {
                $this->db->table('payroll_items')->whereIn('id', $stale)->delete();
            }
            $this->totals($runId);
            $this->db->table('payroll_runs')->where('id', $runId)->update(['status' => 'calculated', 'calculated_by' => $this->uid(), 'calculated_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->uid()]);
            service('audit')->log('payroll.calculated', 'payroll_run', $runId, null, ['items' => count($seen), 'skipped' => count($skipped)], 'Payroll calculated for ' . $run['period']);

            return ['items' => count($seen), 'skipped' => $skipped];
        });
    }

    /** @return array{item: array<string, mixed>, components: list<array<string, mixed>>, incentive_ids: list<int>} */
    private function calcEmployee(array $run, array $emp, array $ss, array $stat, ?int $itemId): array
    {
        $from = $run['period_start'];
        $to   = $run['period_end'];
        $a    = $this->attendance->summary($emp, $from, $to);
        $unmarkedPaid = service('settingsStore')->get('hr.payroll_unmarked_policy', 'payable') !== 'unpaid';
        $lop  = $a['absent'] + $a['unpaid_leave'] + ($unmarkedPaid ? 0 : $a['unmarked']);
        $comp = [];
        $add  = static function (string $type, string $code, string $label, string $amount, ?string $refType = null, ?int $refId = null) use (&$comp): void {
            if (! Dec::isZero($amount)) {
                $comp[] = ['component_type' => $type, 'code' => mb_substr($code, 0, 30), 'label' => mb_substr($label, 0, 120), 'amount' => Dec::round($amount), 'ref_type' => $refType, 'ref_id' => $refId];
            }
        };

        // ---- payable days and proration
        if ($ss['salary_type'] === 'daily') {
            $payable   = $a['present'] + $a['paid_leave'];
            $proration = $payable;
        } else {
            switch ($ss['proration_basis']) {
                case 'fixed_30':
                    $proration = 30.0;
                    $payable   = max(0.0, min(30.0, 30 - $a['outside'] - $lop));
                    break;
                case 'working_days':
                    $proration = (float) service('hrCalendar')->workingDays($from, $to);
                    $payable   = max(0.0, $a['working_days'] - $lop);
                    break;
                default:
                    $proration = (float) $a['days'];
                    $payable   = max(0.0, $a['days'] - $a['outside'] - $lop);
            }
        }
        $ratio = $proration > 0 ? Dec::div((string) $payable, (string) $proration, 6) : '0';

        // ---- fixed pay
        $fixed      = '0';
        $allowances = '0';
        $otherDed   = '0';
        $earnComps  = array_values(array_filter($ss['components'], static fn ($c) => $c['component_type'] === 'earning'));
        $basicEarned = null;
        if ($ss['salary_type'] === 'daily') {
            $fixed = Dec::round(Dec::mul($ss['monthly_gross'], (string) $payable, 4));
            $add('earning', 'DAILY', 'Daily wages (' . $payable . ' days × ' . inr($ss['monthly_gross']) . ')', $fixed);
        } elseif ($earnComps === []) {
            $fixed = Dec::round(Dec::mul($ss['monthly_gross'], $ratio, 6));
            $add('earning', 'FIXED', 'Fixed monthly pay', $fixed);
            $basicEarned = $fixed;
        } else {
            foreach ($earnComps as $c) {
                $amt = (int) $c['is_prorated'] === 1 ? Dec::round(Dec::mul($c['amount'], $ratio, 6)) : Dec::round($c['amount']);
                $add('earning', $c['code'], $c['label'] . ((int) $c['is_prorated'] === 1 ? '' : ' (fixed)'), $amt);
                if ((int) $c['is_prorated'] === 1) {
                    $fixed = Dec::add($fixed, $amt);
                } else {
                    $allowances = Dec::add($allowances, $amt);
                }
                if ($c['code'] === 'BASIC') {
                    $basicEarned = $amt;
                }
            }
        }
        foreach (array_filter($ss['components'], static fn ($c) => $c['component_type'] === 'deduction') as $c) {
            $amt = (int) $c['is_prorated'] === 1 ? Dec::round(Dec::mul($c['amount'], $ratio, 6)) : Dec::round($c['amount']);
            $add('deduction', $c['code'], $c['label'], $amt);
            $otherDed = Dec::add($otherDed, $amt);
        }
        $overtime = '0';
        if ($ss['overtime_rate_per_hour'] && $a['overtime_minutes'] > 0) {
            $overtime = Dec::round(Dec::mul($ss['overtime_rate_per_hour'], Dec::div((string) $a['overtime_minutes'], '60', 4), 4));
            $add('earning', 'OT', 'Overtime (' . round($a['overtime_minutes'] / 60, 2) . ' h)', $overtime);
        }

        // ---- manual lines added to this draft run
        if ($itemId !== null) {
            foreach ($this->db->table('payroll_item_components')->where(['payroll_item_id' => $itemId, 'ref_type' => 'manual'])->get()->getResultArray() as $m) {
                if ($m['component_type'] === 'earning') {
                    $allowances = Dec::add($allowances, $m['amount']);
                } elseif ($m['component_type'] === 'deduction') {
                    $otherDed = Dec::add($otherDed, $m['amount']);
                }
            }
        }

        // ---- approved incentives not yet paid in any payroll (exactly once)
        $incIds = [];
        $incNet = '0';
        $entries = $this->db->query("SELECT ie.*, p.carry_forward, p.name AS policy FROM incentive_entries ie JOIN incentive_policies p ON p.id = ie.policy_id
            WHERE ie.employee_id = ? AND ie.status = 'approved' AND ie.payroll_item_id IS NULL AND ie.period <= ? FOR UPDATE", [$emp['id'], $run['period']])->getResultArray();
        foreach ($entries as $ie) {
            if ($ie['period'] < $run['period'] && (int) $ie['carry_forward'] !== 1) {
                continue;
            }
            $incIds[] = (int) $ie['id'];
            $incNet   = Dec::add($incNet, $ie['amount']);
            $add('earning', $ie['source_type'] === 'clawback' ? 'INC-CLAW' : 'INC', ($ie['source_type'] === 'clawback' ? 'Incentive clawback — ' : 'Incentive — ') . $ie['policy'] . ' (' . $ie['period'] . ')', $ie['amount'], 'incentive_entry', (int) $ie['id']);
        }
        $incentive = Dec::isNegative($incNet) ? '0.00' : Dec::round($incNet);
        $clawback  = Dec::isNegative($incNet) ? Dec::abs($incNet) : '0';
        if (Dec::isPositive($clawback)) {
            $otherDed = Dec::add($otherDed, $clawback);
        }

        // ---- employee-paid expenses awaiting reimbursement
        $reimb = '0';
        if (service('settingsStore')->get('hr.payroll_include_reimbursements', true)) {
            foreach ($this->db->table('expenses')->where(['paid_through' => 'employee_reimbursable', 'paid_by_employee_id' => $emp['id'], 'approval_status' => 'approved', 'reimbursement_status' => 'pending'])
                ->where('payment_status !=', 'paid')->where('deleted_at', null)->where('expense_date <=', $to)->get()->getResultArray() as $x) {
                $due = Dec::sub($x['total_amount'], $x['paid_amount']);
                if (Dec::isPositive($due)) {
                    $reimb = Dec::add($reimb, $due);
                    $add('earning', 'REIMB', 'Reimbursement ' . $x['expense_number'], $due, 'expense', (int) $x['id']);
                }
            }
        }

        $gross = Dec::add($fixed, $allowances, $overtime, $incentive, $reimb);

        // ---- statutory (only when enabled, configured and applicable)
        $statDed = '0';
        $on = static fn ($code) => (int) ($stat[$code]['enabled'] ?? 0) === 1;
        if ((int) $ss['pf_applicable'] === 1 && $on('PF')) {
            $wage = $basicEarned ?? $fixed;
            if ($stat['PF']['wage_ceiling'] !== null) {
                $wage = Dec::min($wage, $stat['PF']['wage_ceiling']);
            }
            $ee = Dec::round(Dec::pct($wage, $stat['PF']['employee_rate'] ?? '0'));
            $er = Dec::round(Dec::pct($wage, $stat['PF']['employer_rate'] ?? '0'));
            $add('deduction', 'PF', 'Provident Fund (employee)', $ee);
            $add('employer', 'PF-ER', 'Provident Fund (employer)', $er);
            $statDed = Dec::add($statDed, $ee);
        }
        if ((int) $ss['esi_applicable'] === 1 && $on('ESI') && ($stat['ESI']['wage_ceiling'] === null || Dec::cmp($gross, $stat['ESI']['wage_ceiling']) <= 0)) {
            $ee = Dec::round(Dec::pct($gross, $stat['ESI']['employee_rate'] ?? '0'));
            $er = Dec::round(Dec::pct($gross, $stat['ESI']['employer_rate'] ?? '0'));
            $add('deduction', 'ESI', 'ESI (employee)', $ee);
            $add('employer', 'ESI-ER', 'ESI (employer)', $er);
            $statDed = Dec::add($statDed, $ee);
        }
        if ((int) $ss['pt_applicable'] === 1 && $on('PT') && $stat['PT']['fixed_amount'] !== null) {
            $add('deduction', 'PT', 'Professional Tax', $stat['PT']['fixed_amount']);
            $statDed = Dec::add($statDed, $stat['PT']['fixed_amount']);
        }
        if ($on('TDS') && Dec::isPositive($ss['tds_monthly'])) {
            $add('deduction', 'TDS', 'TDS on salary', $ss['tds_monthly']);
            $statDed = Dec::add($statDed, $ss['tds_monthly']);
        }
        if ($on('LWF') && $stat['LWF']['fixed_amount'] !== null) {
            $add('deduction', 'LWF', 'Labour Welfare Fund', $stat['LWF']['fixed_amount']);
            $statDed = Dec::add($statDed, $stat['LWF']['fixed_amount']);
        }

        // ---- salary advance recovery
        $recovery = '0';
        foreach ($this->db->table('employee_advances')->where(['employee_id' => $emp['id'], 'status' => 'open'])->where('advance_date <=', $to)->orderBy('advance_date')->get()->getResultArray() as $adv) {
            $left = Dec::sub($adv['amount'], $adv['recovered_amount']);
            $amt  = Dec::min($adv['monthly_recovery'], $left);
            if (Dec::isPositive($amt)) {
                $recovery = Dec::add($recovery, $amt);
                $add('deduction', 'ADV', 'Advance recovery (' . dmy($adv['advance_date']) . ')', $amt, 'employee_advance', (int) $adv['id']);
            }
        }

        $ded = Dec::add($statDed, $recovery, $otherDed);
        $net = Dec::sub($gross, $ded);

        return [
            'item' => [
                'salary_structure_id' => $ss['id'], 'days_in_period' => $a['days'], 'proration_days' => $proration, 'payable_days' => $payable,
                'present_days' => $a['present'], 'paid_leave_days' => $a['paid_leave'], 'unpaid_leave_days' => $a['unpaid_leave'], 'absent_days' => $a['absent'],
                'holiday_days' => $a['holiday'], 'week_off_days' => $a['week_off'], 'unmarked_days' => $a['unmarked'],
                'base_monthly' => $ss['monthly_gross'], 'fixed_earned' => Dec::round($fixed), 'allowances' => Dec::round($allowances), 'overtime_amount' => Dec::round($overtime),
                'incentive_amount' => $incentive, 'reimbursement_amount' => Dec::round($reimb), 'gross_pay' => Dec::round($gross),
                'statutory_deductions' => Dec::round($statDed), 'recovery_deductions' => Dec::round($recovery), 'other_deductions' => Dec::round($otherDed),
                'total_deductions' => Dec::round($ded), 'net_pay' => Dec::round($net), 'amount_paid' => '0.00', 'balance' => Dec::round($net), 'payment_status' => 'unpaid',
            ],
            'components' => $comp, 'incentive_ids' => $incIds,
        ];
    }

    private function totals(int $runId): void
    {
        $t = $this->db->query('SELECT COALESCE(SUM(gross_pay),0) g, COALESCE(SUM(total_deductions),0) d, COALESCE(SUM(net_pay),0) n, COALESCE(SUM(amount_paid),0) p FROM payroll_items WHERE payroll_run_id = ?', [$runId])->getRowArray();
        $this->db->table('payroll_runs')->where('id', $runId)->update(['total_gross' => $t['g'], 'total_deductions' => $t['d'], 'total_net' => $t['n'], 'total_paid' => $t['p']]);
    }

    /** Issues HR should look at before finalizing. @return list<array{level: string, text: string}> */
    public function warnings(int $runId): array
    {
        $run = $this->run($runId);
        $out = [];
        if ($run['status'] === 'draft') {
            return [['level' => 'info', 'text' => 'Not calculated yet.']];
        }
        foreach ($this->db->table('payroll_items i')->select('i.unmarked_days, i.net_pay, e.full_name')->join('employees e', 'e.id = i.employee_id')->where('i.payroll_run_id', $runId)->get()->getResultArray() as $i) {
            if ((float) $i['unmarked_days'] > 0) {
                $out[] = ['level' => 'warn', 'text' => $i['full_name'] . ': ' . (float) $i['unmarked_days'] . ' working day(s) with no attendance — counted as ' . (service('settingsStore')->get('hr.payroll_unmarked_policy', 'payable') === 'unpaid' ? 'unpaid' : 'payable') . '.'];
            }
            if (Dec::isNegative($i['net_pay'])) {
                $out[] = ['level' => 'block', 'text' => $i['full_name'] . ': deductions exceed earnings (net ' . inr($i['net_pay']) . '). Reduce recoveries before finalizing.'];
            }
        }
        $missing = $this->db->query("SELECT e.full_name FROM employees e WHERE e.date_of_joining <= ? AND (e.exit_date IS NULL OR e.exit_date >= ?)
            AND NOT EXISTS (SELECT 1 FROM salary_structures s WHERE s.employee_id = e.id AND s.effective_from <= ?)", [$run['period_end'], $run['period_start'], $run['period_end']])->getResultArray();
        foreach ($missing as $m) {
            $out[] = ['level' => 'warn', 'text' => $m['full_name'] . ' has no salary structure and is not in this payroll.'];
        }
        if ($run['calculated_at'] !== null && $this->staleSince($run) !== null) {
            $out[] = ['level' => 'block', 'text' => 'Attendance, leave, salary or incentives changed after the last calculation (' . $this->staleSince($run) . '). Recalculate before finalizing.'];
        }
        $off = array_keys(array_filter($this->salaries->statutory(), static fn ($s) => (int) $s['enabled'] !== 1));
        if ($off !== []) {
            $out[] = ['level' => 'info', 'text' => 'Statutory items not enabled: ' . implode(', ', $off) . ' (enable only after HR/accountant review).'];
        }

        return $out;
    }

    /** Name of the data that changed after calculation, or null. */
    private function staleSince(array $run): ?string
    {
        $at = $run['calculated_at'];
        $checks = [
            'attendance' => $this->db->query('SELECT COUNT(*) n FROM attendance WHERE att_date BETWEEN ? AND ? AND GREATEST(created_at, COALESCE(updated_at, created_at)) > ?', [$run['period_start'], $run['period_end'], $at]),
            'leave' => $this->db->query("SELECT COUNT(*) n FROM leave_requests WHERE from_date <= ? AND to_date >= ? AND decided_at > ?", [$run['period_end'], $run['period_start'], $at]),
            'salary' => $this->db->query('SELECT COUNT(*) n FROM salary_structures WHERE effective_from <= ? AND created_at > ?', [$run['period_end'], $at]),
            'incentives' => $this->db->query("SELECT COUNT(*) n FROM incentive_entries WHERE status = 'approved' AND payroll_item_id IS NULL AND period <= ? AND approved_at > ?", [$run['period'], $at]),
        ];
        foreach ($checks as $k => $q) {
            if ((int) ($q->getRow()->n ?? 0) > 0) {
                return $k;
            }
        }

        return null;
    }

    /** Add a one-off earning/deduction to a draft line (e.g. bonus), then recalculate. */
    public function addManualLine(int $itemId, string $type, string $label, string $amount): void
    {
        service('authz')->authorize('hr.payroll.calculate');
        $item = $this->db->table('payroll_items')->where('id', $itemId)->get()->getRowArray() ?? throw new BusinessRuleException('Payroll line not found.');
        $run  = $this->run((int) $item['payroll_run_id']);
        $this->assertOpen($run);
        $amount = str_replace(',', '', trim($amount));
        if (! in_array($type, ['earning', 'deduction'], true) || trim($label) === '' || ! is_numeric($amount) || ! Dec::isPositive($amount)) {
            throw new BusinessRuleException('Enter a label and a positive amount.');
        }
        $this->db->table('payroll_item_components')->insert(['payroll_item_id' => $itemId, 'component_type' => $type, 'code' => $type === 'earning' ? 'MAN-E' : 'MAN-D',
            'label' => mb_substr(trim($label), 0, 120), 'amount' => Dec::round($amount), 'ref_type' => 'manual', 'ref_id' => $this->uid()]);
        service('audit')->log('payroll.manual_line', 'payroll_item', $itemId, null, ['type' => $type, 'label' => $label, 'amount' => $amount], 'Manual payroll line added');
        $this->calculate((int) $run['id']);
    }

    public function removeManualLine(int $componentId): void
    {
        service('authz')->authorize('hr.payroll.calculate');
        $c    = $this->db->table('payroll_item_components')->where(['id' => $componentId, 'ref_type' => 'manual'])->get()->getRowArray() ?? throw new BusinessRuleException('Line not found.');
        $item = $this->db->table('payroll_items')->where('id', $c['payroll_item_id'])->get()->getRowArray();
        $run  = $this->run((int) $item['payroll_run_id']);
        $this->assertOpen($run);
        $this->db->table('payroll_item_components')->where('id', $componentId)->delete();
        service('audit')->log('payroll.manual_line_removed', 'payroll_item', (int) $item['id'], $c, null, 'Manual payroll line removed');
        $this->calculate((int) $run['id']);
    }

    /** Lock the month: accrual journal, advance recoveries, reimbursements settled, payslips. */
    public function finalize(int $runId): void
    {
        service('authz')->authorize('hr.payroll.finalize');
        $this->tx(function () use ($runId): void {
            $run = $this->run($runId, true);
            $this->assertOpen($run);
            if ($run['status'] !== 'calculated') {
                throw new BusinessRuleException('Calculate the payroll before finalizing.');
            }
            $blocks = array_filter($this->warnings($runId), static fn ($w) => $w['level'] === 'block');
            if ($blocks !== []) {
                throw new BusinessRuleException(reset($blocks)['text']);
            }
            $items = $this->db->table('payroll_items i')->select('i.*, e.full_name, e.employee_code')->join('employees e', 'e.id = i.employee_id')->where('i.payroll_run_id', $runId)->get()->getResultArray();
            if ($items === []) {
                throw new BusinessRuleException('There are no employees in this payroll.');
            }
            $acc = fn ($s) => $this->ledger->account($s);
            $sum = ['salary' => '0', 'incentive' => '0', 'reimb' => '0', 'employer' => '0', 'claw' => '0', 'other' => '0'];
            $credits = [];
            $lines   = [];
            foreach ($items as $i) {
                $comps = $this->db->table('payroll_item_components')->where('payroll_item_id', $i['id'])->get()->getResultArray();
                $sum['salary'] = Dec::add($sum['salary'], $i['fixed_earned'], $i['allowances'], $i['overtime_amount']);
                $sum['incentive'] = Dec::add($sum['incentive'], $i['incentive_amount']);
                $sum['reimb'] = Dec::add($sum['reimb'], $i['reimbursement_amount']);
                foreach ($comps as $c) {
                    $map = ['PF' => 'pf_payable', 'PF-ER' => 'pf_payable', 'ESI' => 'esi_payable', 'ESI-ER' => 'esi_payable', 'PT' => 'pt_payable', 'TDS' => 'tds_payable', 'LWF' => 'lwf_payable'];
                    if (isset($map[$c['code']])) {
                        $credits[$map[$c['code']]] = Dec::add($credits[$map[$c['code']]] ?? '0', $c['amount']);
                        if ($c['component_type'] === 'employer') {
                            $sum['employer'] = Dec::add($sum['employer'], $c['amount']);
                        }
                    } elseif ($c['code'] === 'ADV') {
                        $lines[] = ['account' => $acc('employee_advance'), 'credit' => $c['amount'], 'employee_id' => (int) $i['employee_id'], 'memo' => 'Advance recovery ' . $i['employee_code']];
                        $this->db->query("UPDATE employee_advances SET recovered_amount = recovered_amount + ?, status = IF(recovered_amount + ? >= amount, 'closed', 'open'), updated_by = ? WHERE id = ?",
                            [$c['amount'], $c['amount'], $this->uid(), $c['ref_id']]);
                    } elseif ($c['component_type'] === 'deduction') {
                        $sum['other'] = Dec::add($sum['other'], $c['amount']);
                    } elseif ($c['code'] === 'REIMB') {
                        $this->db->table('expenses')->where('id', $c['ref_id'])->update(['reimbursement_status' => 'reimbursed', 'payment_status' => 'paid', 'paid_amount' => $this->db->table('expenses')->select('total_amount')->where('id', $c['ref_id'])->get()->getRow()->total_amount,
                            'notes' => mb_substr('Reimbursed through payroll ' . $run['period'], 0, 500), 'updated_by' => $this->uid()]);
                    }
                }
                // net incentive clawback beyond the month's incentives is a deduction credited back to incentive expense
                $incSum = Dec::add('0', ...array_column(array_filter($comps, static fn ($c) => in_array($c['code'], ['INC', 'INC-CLAW'], true)), 'amount'));
                if (Dec::isNegative($incSum)) {
                    $sum['claw'] = Dec::add($sum['claw'], Dec::abs($incSum));
                }
                $lines[] = ['account' => $acc('salary_payable'), 'credit' => $i['net_pay'], 'employee_id' => (int) $i['employee_id'], 'memo' => 'Net pay ' . $i['employee_code']];
            }
            $je = array_merge([
                ['account' => $acc('salary_expense'), 'debit' => Dec::sub($sum['salary'], $sum['other']), 'memo' => 'Salaries ' . $run['period'] . ' (net of other deductions)'],
                ['account' => $acc('incentive_expense'), 'debit' => Dec::sub($sum['incentive'], $sum['claw']), 'memo' => 'Sales incentives ' . $run['period']],
                ['account' => $acc('reimbursement_payable'), 'debit' => $sum['reimb'], 'memo' => 'Reimbursements settled through payroll'],
                ['account' => $acc('employer_statutory'), 'debit' => $sum['employer'], 'memo' => 'Employer statutory contributions'],
            ], array_map(fn ($k, $v) => ['account' => $acc($k), 'credit' => $v, 'memo' => strtoupper(str_replace('_payable', '', $k)) . ' payable'], array_keys($credits), $credits), $lines);
            $jeId = $this->ledger->post($run['period_end'], 'payroll_run', $runId, 'Payroll accrual ' . $run['period'], $je, 'PAYROLL-' . $run['period']);
            $this->db->table('payroll_runs')->where('id', $runId)->update(['status' => 'finalized', 'is_locked' => 1, 'finalized_by' => $this->uid(), 'finalized_at' => date('Y-m-d H:i:s'), 'journal_entry_id' => $jeId, 'updated_by' => $this->uid()]);
            service('audit')->log('payroll.finalized', 'payroll_run', $runId, ['status' => $run['status']], ['status' => 'finalized', 'journal_entry_id' => $jeId, 'total_net' => $run['total_net']], 'Payroll finalized and locked for ' . $run['period']);
        });
        foreach ($this->db->table('payroll_items i')->select('i.id, e.user_id')->join('employees e', 'e.id = i.employee_id')->where('i.payroll_run_id', $runId)->get()->getResultArray() as $i) {
            service('payslips')->generate((int) $i['id']);
            if ($i['user_id']) {
                $run = $this->run($runId);
                service('notifier')->notify((int) $i['user_id'], 'hr', 'Payslip for ' . date('F Y', strtotime($run['period'] . '-01')) . ' is ready', null, site_url('me/payslips'), 'payroll_item', (int) $i['id'], 'payslip:' . $i['id'] . ':1');
            }
        }
    }

    /**
     * Record salary payment(s) from a bank/cash account. Each payment posts
     * Dr Salary Payable / Cr bank — the expense was already booked at accrual.
     *
     * @param list<int>|null $itemIds null = every unpaid line
     */
    public function pay(int $runId, ?array $itemIds, array $in): int
    {
        service('authz')->authorize('hr.payroll.pay');
        $date = ymd((string) ($in['payment_date'] ?? '')) ?? date('Y-m-d');
        $mode = in_array($in['mode'] ?? '', ['cash', 'upi', 'bank_transfer', 'neft', 'rtgs', 'imps', 'cheque', 'other'], true) ? $in['mode'] : 'bank_transfer';
        $bank = $this->db->table('bank_cash_accounts')->where(['id' => (int) ($in['bank_cash_account_id'] ?? 0), 'is_active' => 1])->get()->getRowArray()
            ?? throw new BusinessRuleException('Choose the bank or cash account the salary is paid from.');
        $single = $itemIds !== null && count($itemIds) === 1 && trim((string) ($in['amount'] ?? '')) !== '' ? Dec::round(str_replace(',', '', (string) $in['amount'])) : null;

        return $this->tx(function () use ($runId, $itemIds, $date, $mode, $bank, $single, $in): int {
            $run = $this->run($runId, true);
            if ((int) $run['is_locked'] !== 1) {
                throw new BusinessRuleException('Finalize the payroll before paying salaries.');
            }
            if ($date < $run['period_start']) {
                throw new BusinessRuleException('Payment date is before the payroll month.');
            }
            $b = $this->db->table('payroll_items i')->select('i.*, e.employee_code, e.full_name')->join('employees e', 'e.id = i.employee_id')->where('i.payroll_run_id', $runId)->where('i.balance >', 0);
            if ($itemIds !== null) {
                $b->whereIn('i.id', $itemIds ?: [0]);
            }
            $count = 0;
            foreach ($b->get()->getResultArray() as $i) {
                $amt = $single ?? $i['balance'];
                if (! Dec::isPositive($amt) || Dec::cmp($amt, $i['balance']) > 0) {
                    throw new BusinessRuleException('Payment for ' . $i['full_name'] . ' must be between ₹0.01 and the balance ' . inr($i['balance']) . '.');
                }
                $je = $this->ledger->post($date, 'payroll_payment', (int) $i['id'], 'Salary ' . $run['period'] . ' — ' . $i['employee_code'], [
                    ['account' => $this->ledger->account('salary_payable'), 'debit' => $amt, 'employee_id' => (int) $i['employee_id']],
                    ['account' => $this->ledger->bankLedger((int) $bank['id']), 'credit' => $amt, 'bank_cash_account_id' => (int) $bank['id']],
                ], mb_substr(trim((string) ($in['reference_no'] ?? '')), 0, 80) ?: null);
                $this->db->table('payroll_payments')->insert([
                    'payroll_run_id' => $runId, 'payroll_item_id' => $i['id'], 'payment_date' => $date, 'amount' => $amt, 'mode' => $mode, 'bank_cash_account_id' => $bank['id'],
                    'reference_no' => mb_substr(trim((string) ($in['reference_no'] ?? '')), 0, 80) ?: null, 'journal_entry_id' => $je, 'created_by' => $this->uid(),
                ]);
                $paid = Dec::add($i['amount_paid'], $amt);
                $bal  = Dec::sub($i['net_pay'], $paid);
                $this->db->table('payroll_items')->where('id', $i['id'])->update([
                    'amount_paid' => $paid, 'balance' => $bal, 'payment_status' => Dec::isPositive($bal) ? 'partial' : 'paid', 'last_payment_date' => $date, 'updated_by' => $this->uid(),
                ]);
                if (! Dec::isPositive($bal)) {
                    $this->db->table('incentive_entries')->where(['payroll_item_id' => $i['id'], 'status' => 'included'])->update(['status' => 'paid']);
                }
                $count++;
            }
            if ($count === 0) {
                throw new BusinessRuleException('Nothing to pay — all selected lines are already paid.');
            }
            $this->totals($runId);
            $left = (int) $this->db->table('payroll_items')->where('payroll_run_id', $runId)->where('balance >', 0)->countAllResults();
            $this->db->table('payroll_runs')->where('id', $runId)->update(['status' => $left === 0 ? 'paid' : 'partially_paid']);
            service('audit')->log('payroll.paid', 'payroll_run', $runId, null, ['lines' => $count, 'date' => $date, 'account' => $bank['display_name'] ?? $bank['id']], 'Salary payment recorded for ' . $run['period']);

            return $count;
        });
    }

    /** Post-lock correction with reason: its own journal, updated line, new payslip version. */
    public function adjust(int $itemId, string $type, string $label, string $amount, string $reason): int
    {
        service('authz')->authorize('hr.payroll.adjust');
        $amount = str_replace(',', '', trim($amount));
        if (! in_array($type, ['earning', 'deduction'], true) || trim($label) === '' || ! is_numeric($amount) || ! Dec::isPositive($amount)) {
            throw new BusinessRuleException('Enter the adjustment type, a label and a positive amount.');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new BusinessRuleException('Explain the correction (at least 10 characters); it is kept in the audit log.');
        }
        $adjId = $this->tx(function () use ($itemId, $type, $label, $amount, $reason): int {
            $item = $this->db->query('SELECT * FROM payroll_items WHERE id = ? FOR UPDATE', [$itemId])->getRowArray() ?? throw new BusinessRuleException('Payroll line not found.');
            $run  = $this->run((int) $item['payroll_run_id'], true);
            if ((int) $run['is_locked'] !== 1) {
                throw new BusinessRuleException('This payroll is not finalized; edit and recalculate it instead.');
            }
            $amount = Dec::round($amount);
            if ($type === 'deduction' && Dec::cmp($amount, $item['balance']) > 0) {
                throw new BusinessRuleException('The deduction is more than the unpaid balance (' . inr($item['balance']) . '). Record the recovery as an employee advance instead.');
            }
            $date = max(date('Y-m-d'), $run['period_end']);
            $je   = $this->ledger->post($date, 'payroll_adjustment', $itemId, 'Payroll correction ' . $run['period'] . ': ' . $label, $type === 'earning'
                ? [['account' => $this->ledger->account('salary_expense'), 'debit' => $amount], ['account' => $this->ledger->account('salary_payable'), 'credit' => $amount, 'employee_id' => (int) $item['employee_id']]]
                : [['account' => $this->ledger->account('salary_payable'), 'debit' => $amount, 'employee_id' => (int) $item['employee_id']], ['account' => $this->ledger->account('salary_expense'), 'credit' => $amount]]);
            $this->db->table('payroll_adjustments')->insert([
                'payroll_run_id' => $run['id'], 'payroll_item_id' => $itemId, 'adjustment_type' => $type, 'label' => mb_substr(trim($label), 0, 120),
                'amount' => $amount, 'reason' => mb_substr(trim($reason), 0, 1000), 'journal_entry_id' => $je, 'created_by' => $this->uid(),
            ]);
            $adj = (int) $this->db->insertID();
            $this->db->table('payroll_item_components')->insert(['payroll_item_id' => $itemId, 'component_type' => $type, 'code' => 'ADJ', 'label' => 'Correction: ' . mb_substr(trim($label), 0, 100), 'amount' => $amount, 'ref_type' => 'payroll_adjustment', 'ref_id' => $adj]);
            $before = array_intersect_key($item, array_flip(['gross_pay', 'total_deductions', 'net_pay', 'balance']));
            $gross  = $type === 'earning' ? Dec::add($item['gross_pay'], $amount) : $item['gross_pay'];
            $ded    = $type === 'deduction' ? Dec::add($item['total_deductions'], $amount) : $item['total_deductions'];
            $other  = $type === 'deduction' ? Dec::add($item['other_deductions'], $amount) : $item['other_deductions'];
            $allow  = $type === 'earning' ? Dec::add($item['allowances'], $amount) : $item['allowances'];
            $net    = Dec::sub($gross, $ded);
            $bal    = Dec::sub($net, $item['amount_paid']);
            $after  = ['allowances' => $allow, 'gross_pay' => $gross, 'other_deductions' => $other, 'total_deductions' => $ded, 'net_pay' => $net, 'balance' => $bal,
                'payment_status' => Dec::isPositive($bal) ? (Dec::isPositive($item['amount_paid']) ? 'partial' : 'unpaid') : 'paid', 'adjustment_note' => mb_substr(trim($reason), 0, 500), 'updated_by' => $this->uid()];
            $this->db->table('payroll_items')->where('id', $itemId)->update($after);
            $this->totals((int) $run['id']);
            $left = (int) $this->db->table('payroll_items')->where('payroll_run_id', $run['id'])->where('balance >', 0)->countAllResults();
            $paidAny = (int) $this->db->table('payroll_payments')->where('payroll_run_id', $run['id'])->countAllResults();
            $this->db->table('payroll_runs')->where('id', $run['id'])->update(['status' => $paidAny === 0 ? 'finalized' : ($left === 0 ? 'paid' : 'partially_paid')]);
            service('audit')->log('payroll.adjusted', 'payroll_item', $itemId, $before, array_intersect_key($after, $before) + ['type' => $type, 'amount' => $amount], 'Locked payroll ' . $run['period'] . ' corrected: ' . $label, ['reason' => trim($reason), 'journal_entry_id' => $je]);

            return $adj;
        });
        service('payslips')->generate($itemId);

        return $adjId;
    }

    // ---- salary advances -------------------------------------------------------

    /** Pay an advance to an employee: Dr Employee Advances / Cr bank; recovered through payroll. */
    public function giveAdvance(int $employeeId, array $in): int
    {
        service('authz')->authorize('hr.payroll.pay');
        $amount = str_replace(',', '', trim((string) ($in['amount'] ?? '')));
        $rec    = str_replace(',', '', trim((string) ($in['monthly_recovery'] ?? '')));
        $date   = ymd((string) ($in['advance_date'] ?? '')) ?? date('Y-m-d');
        $bank   = $this->db->table('bank_cash_accounts')->where(['id' => (int) ($in['bank_cash_account_id'] ?? 0), 'is_active' => 1])->get()->getRowArray();
        if (! is_numeric($amount) || ! Dec::isPositive($amount) || ! is_numeric($rec) || ! Dec::isPositive($rec) || $bank === null) {
            throw new BusinessRuleException('Enter the advance amount, the monthly recovery and the account it is paid from.');
        }

        return $this->tx(function () use ($employeeId, $amount, $rec, $date, $bank, $in): int {
            $emp = $this->db->table('employees')->where('id', $employeeId)->get()->getRowArray() ?? throw new BusinessRuleException('Employee not found.');
            $this->db->table('employee_advances')->insert([
                'employee_id' => $employeeId, 'advance_date' => $date, 'amount' => Dec::round($amount), 'monthly_recovery' => Dec::round(Dec::min($rec, $amount)),
                'status' => 'open', 'bank_cash_account_id' => $bank['id'], 'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500) ?: null, 'created_by' => $this->uid(),
            ]);
            $id = (int) $this->db->insertID();
            $je = $this->ledger->post($date, 'employee_advance', $id, 'Salary advance — ' . $emp['employee_code'], [
                ['account' => $this->ledger->account('employee_advance'), 'debit' => Dec::round($amount), 'employee_id' => $employeeId],
                ['account' => $this->ledger->bankLedger((int) $bank['id']), 'credit' => Dec::round($amount), 'bank_cash_account_id' => (int) $bank['id']],
            ]);
            $this->db->table('employee_advances')->where('id', $id)->update(['journal_entry_id' => $je]);
            service('audit')->log('payroll.advance', 'employee', $employeeId, null, ['amount' => $amount, 'monthly_recovery' => $rec], 'Salary advance given');

            return $id;
        });
    }
}

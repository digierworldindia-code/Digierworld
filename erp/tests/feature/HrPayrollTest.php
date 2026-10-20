<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use App\Services\Hr\HrCalendar;
use Tests\Support\DgrTestCase;

/**
 * Spec §13, §14, §19: paid/unpaid leave with configured proration,
 * incentives exactly once, accrual vs payment, locked payroll rejects
 * changes, authorised corrections are audited, employees see only their own
 * records. Each test uses its own payroll month (runs are unique per month).
 */
final class HrPayrollTest extends DgrTestCase
{
    private function employee(string $name, string $doj, string $gross, string $basis = 'calendar_days', ?int $userId = null): int
    {
        $id = service('employees')->create(['full_name' => $name, 'date_of_joining' => $doj, 'incentive_eligible' => '1', 'user_id' => $userId ? (string) $userId : '']);
        service('salaries')->save($id, ['effective_from' => $doj, 'monthly_gross' => $gross, 'proration_basis' => $basis,
            'components' => [['code' => 'BASIC', 'label' => 'Basic', 'component_type' => 'earning', 'amount' => Dec::div($gross, '2'), 'is_prorated' => '1'],
                ['code' => 'HRA', 'label' => 'HRA', 'component_type' => 'earning', 'amount' => Dec::sub($gross, Dec::div($gross, '2')), 'is_prorated' => '1']]]);

        return $id;
    }

    /** Mark every working day of the month present except the given dates. */
    private function markMonth(int $empId, string $period, array $skip = []): void
    {
        $cal = service('hrCalendar');
        foreach (HrCalendar::dates($period . '-01', date('Y-m-t', strtotime($period . '-01'))) as $d) {
            if (! in_array($d, $skip, true) && $cal->dayKind($d) === null) {
                service('attendance')->markDay($d, [$empId => ['status' => 'present']]);
            }
        }
    }

    private function leave(int $empId, string $code, string $from, string $to): int
    {
        $type = db_connect()->table('leave_types')->where('code', $code)->get()->getRow()->id;
        $id   = service('leaves')->request($empId, ['leave_type_id' => (string) $type, 'from_date' => $from, 'to_date' => $to, 'reason' => 'Family function']);
        service('leaves')->decide($id, 'approved');

        return $id;
    }

    private function item(int $runId, int $empId): array
    {
        return db_connect()->table('payroll_items')->where(['payroll_run_id' => $runId, 'employee_id' => $empId])->get()->getRowArray();
    }

    private function policy(): int
    {
        return service('incentives')->savePolicy(['name' => 'Telecaller 10% of collections (net of GST)', 'scope_type' => 'all', 'basis' => 'collections_net_of_tax', 'calc_type' => 'percent',
            'value' => '10', 'cutoff_day' => '31', 'requires_approval' => '1', 'clawback_on_refund' => '1', 'carry_forward' => '1', 'is_active' => '1', 'effective_from' => '2025-01-01']);
    }

    public function testPaidAndUnpaidLeaveProrationAndIncentivesIncludedExactlyOnce(): void
    {
        $this->loginAs($this->makeUser('hr_manager'));
        $emp = $this->employee('Ravi Leave', '2025-04-01', '31000');
        $this->leave($emp, 'CL', '2025-08-05', '2025-08-06');    // paid, 2 working days
        $this->leave($emp, 'LWP', '2025-08-07', '2025-08-07');   // unpaid, 1 day
        $this->markMonth($emp, '2025-08', ['2025-08-05', '2025-08-06', '2025-08-07']);
        $bal = array_column(service('leaves')->balances($emp, '2025-08-05'), null, 'code');
        $this->assertSame('2.0', $bal['CL']['used']);

        $entry = service('incentives')->addManual(['employee_id' => (string) $emp, 'policy_id' => (string) $this->policy(), 'period' => '2025-08', 'amount' => '1000', 'notes' => 'Q2 collections bonus']);
        service('incentives')->decide($entry, 'approved');

        $run = service('payroll')->createRun('2025-08');
        service('payroll')->calculate($run);
        $i = $this->item($run, $emp);
        $this->assertSame('31.0', $i['days_in_period']);
        $this->assertSame('30.0', $i['payable_days'], '31 calendar days − 1 unpaid leave day');
        $this->assertSame('2.0', $i['paid_leave_days']);
        $this->assertSame('1.0', $i['unpaid_leave_days']);
        $this->assertSame('30000.00', $i['fixed_earned'], '31,000 × 30/31');
        $this->assertSame('1000.00', $i['incentive_amount']);
        $this->assertSame('31000.00', $i['gross_pay']);

        // recalculating never doubles the incentive
        service('payroll')->calculate($run);
        $i = $this->item($run, $emp);
        $this->assertSame('1000.00', $i['incentive_amount']);
        $e = db_connect()->table('incentive_entries')->where('id', $entry)->get()->getRowArray();
        $this->assertSame('included', $e['status']);
        $this->assertSame((int) $i['id'], (int) $e['payroll_item_id']);

        // the next month's payroll does not pick it up again
        $next = service('payroll')->createRun('2025-09');
        service('payroll')->calculate($next);
        $this->assertSame('0.00', $this->item($next, $emp)['incentive_amount']);

        // same structure with "fixed 30-day month" proration: 30 − 1 LOP = 29/30
        $emp30 = $this->employee('Meena Fixed30', '2025-04-01', '30000', 'fixed_30');
        $this->leave($emp30, 'LWP', '2025-08-11', '2025-08-11');
        service('payroll')->calculate($run);
        $j = $this->item($run, $emp30);
        $this->assertSame('29.0', $j['payable_days']);
        $this->assertSame('29000.00', $j['fixed_earned']);
    }

    public function testAccrualAndPaymentAreNotDoubleCountedAndLockedPeriodRejectsChanges(): void
    {
        $this->setupCompany();
        $hr  = $this->makeUser('hr_manager');
        $this->loginAs($hr);
        $emp = $this->employee('Sunil Locked', '2025-04-01', '20000');
        $this->markMonth($emp, '2025-10');
        $run = service('payroll')->createRun('2025-10');
        service('payroll')->calculate($run);
        $expenseBefore = $this->balance('salary_expense');
        $payableBefore = $this->balance('salary_payable');
        service('payroll')->finalize($run);
        $r = db_connect()->table('payroll_runs')->where('id', $run)->get()->getRowArray();
        $this->assertSame('1', (string) $r['is_locked']);
        $net = $this->item($run, $emp)['net_pay'];
        $this->assertSame('20000.00', $net);
        $this->assertSame(Dec::add($expenseBefore, $r['total_gross']), $this->balance('salary_expense'), 'expense booked once, at accrual');
        $this->assertSame(1, db_connect()->table('payslips')->where('payroll_item_id', $this->item($run, $emp)['id'])->countAllResults());

        // paying settles the liability; the expense does not move again
        $bank = $this->makeBankAccount('Salary A/c');
        $bankBefore = $this->bankBalance($bank);
        service('payroll')->pay($run, [(int) $this->item($run, $emp)['id']], ['payment_date' => '2025-10-31', 'bank_cash_account_id' => (string) $bank, 'mode' => 'neft']);
        $this->assertSame(Dec::add($expenseBefore, $r['total_gross']), $this->balance('salary_expense'));
        $this->assertSame(Dec::sub($bankBefore, $net), $this->bankBalance($bank));
        $this->assertSame('paid', $this->item($run, $emp)['payment_status']);

        // locked: no recalculation, no attendance or salary edits for the month
        foreach ([
            static fn () => service('payroll')->calculate($run),
            static fn () => service('attendance')->markDay('2025-10-15', [$emp => ['status' => 'absent']], 'trying to change history'),
            static fn () => service('salaries')->save($emp, ['effective_from' => '2025-10-01', 'monthly_gross' => '25000']),
            static fn () => service('payroll')->addManualLine((int) service('payroll')->run($run)['id'] ? (int) db_connect()->table('payroll_items')->where('payroll_run_id', $run)->get()->getRow()->id : 0, 'earning', 'Bonus', '100'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Locked payroll must reject the change');
            } catch (BusinessRuleException $e) {
                $this->assertMatchesRegularExpression('/lock/i', $e->getMessage());
            }
        }

        // unauthorised correction over HTTP is refused …
        $itemId = (int) $this->item($run, $emp)['id'];
        $this->loginAs($this->makeUser('hr_recruiter'));
        $this->assertSame(403, $this->statusOf(fn () => $this->postForm('hr/payroll/items/' . $itemId . '/adjust', ['adjustment_type' => 'earning', 'label' => 'x', 'amount' => '5000', 'reason' => 'not allowed to do this'])));
        $this->assertSame('20000.00', $this->item($run, $emp)['net_pay']);

        // … an authorised one is recorded with its own journal, audit entry and payslip v2
        $this->loginAs($this->makeUser('payroll_manager'));
        $res = $this->postForm('hr/payroll/items/' . $itemId . '/adjust', ['adjustment_type' => 'earning', 'label' => 'Missed overtime', 'amount' => '750', 'reason' => 'Overtime on 28 Oct was approved late']);
        $res->assertRedirect();
        $i = $this->item($run, $emp);
        $this->assertSame('20750.00', $i['net_pay']);
        $this->assertSame('750.00', $i['balance']);
        $adj = db_connect()->table('payroll_adjustments')->where('payroll_item_id', $itemId)->get()->getRowArray();
        $this->assertNotNull($adj['journal_entry_id']);
        $log = db_connect()->table('audit_logs')->where(['action' => 'payroll.adjusted', 'entity_id' => (string) $itemId])->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertStringContainsString('approved late', (string) $log['meta']);
        $this->assertSame(2, db_connect()->table('payslips')->where('payroll_item_id', $itemId)->countAllResults());
    }

    public function testEmployeeSeesOnlyOwnPayslipAndNoPayrollScreens(): void
    {
        $hr = $this->makeUser('hr_manager');
        $u1 = $this->makeUser('employee', 'Self Service One');
        $u2 = $this->makeUser('employee', 'Self Service Two');
        $this->loginAs($hr);
        $e1 = $this->employee('Self Service One', '2025-04-01', '18000', 'calendar_days', (int) $u1->id);
        $e2 = $this->employee('Self Service Two', '2025-04-01', '19000', 'calendar_days', (int) $u2->id);
        $run = service('payroll')->createRun('2025-11');
        service('payroll')->calculate($run);
        service('payroll')->finalize($run);
        $f1 = (int) db_connect()->table('payslips')->where('payroll_item_id', $this->item($run, $e1)['id'])->get()->getRow()->stored_file_id;
        $f2 = (int) db_connect()->table('payslips')->where('payroll_item_id', $this->item($run, $e2)['id'])->get()->getRow()->stored_file_id;

        $this->loginAs($u1);
        $this->get('me/payslips')->assertSee('November 2025');
        $this->assertSame(200, $this->statusOf(fn () => $this->get('files/' . $f1)));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('files/' . $f2)), 'another employee\'s payslip');
        // PDFs missing on disk (uploads not restored): a clean 404, never a server error, and permission is still checked first
        foreach ([$f1, $f2] as $fid) {
            unlink(service('files')->path(service('files')->find($fid)));
        }
        $this->assertSame(404, $this->statusOf(fn () => $this->get('files/' . $f1)), 'own payslip whose PDF is missing');
        $this->assertSame(403, $this->statusOf(fn () => $this->get('files/' . $f2)), 'another employee\'s missing payslip');
        foreach (['hr/payroll', 'hr/payroll/' . $run, 'hr/employees/' . $e2, 'hr/attendance', 'finance/receipts'] as $uri) {
            $this->assertSame(403, $this->statusOf(fn () => $this->get($uri)), $uri);
        }
        $this->get('me/attendance')->assertOK();
        $this->assertSame(0, db_connect()->table('leave_requests')->where('employee_id', $e2)->countAllResults());
    }

    public function testCollectionsIncentiveIsProposedOnceAndReversedWhenReceiptIsVoided(): void
    {
        $this->setupCompany();
        $owner = $this->makeUser('super_admin');
        $tc    = $this->makeUser('telecaller', 'Incentive Caller');
        $this->loginAs($owner);
        $emp = $this->employee('Incentive Caller', '2025-04-01', '15000', 'calendar_days', (int) $tc->id);
        $policy = $this->policy();
        $lead = service('leads')->create(['company_name' => 'Commission Dental', 'mobile' => '7' . random_int(100000000, 999999999), 'assigned_to' => (string) $tc->id]);
        $conv = service('leads')->convert($lead, ['attributed_user_id' => (string) $tc->id, 'state_code' => '08']);
        [$inv] = $this->issue('tax_invoice', (int) $conv['contact_id'], [$this->line('10000')], 'cgst_sgst', '08');
        $rid = service('receipts')->create(['contact_id' => (int) $conv['contact_id'], 'receipt_date' => date('Y-m-d'), 'amount' => '11800', 'mode' => 'cash',
            'bank_cash_account_id' => $this->cashAccountId(), 'allocations' => [['document_id' => $inv, 'amount' => '11800']]]);

        $first = service('incentives')->generate(date('Y-m'));
        $rows  = db_connect()->table('incentive_entries')->where(['employee_id' => $emp, 'policy_id' => $policy])->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('10000.00', $rows[0]['basis_amount'], 'collections net of GST: 11,800 × 10,000/11,800');
        $this->assertSame('1000.00', $rows[0]['amount']);
        $this->assertSame('proposed', $rows[0]['status'], 'HR approval required');
        $this->assertGreaterThanOrEqual(1, $first['created']);

        service('incentives')->generate(date('Y-m'));
        $this->assertSame(1, db_connect()->table('incentive_entries')->where(['employee_id' => $emp, 'policy_id' => $policy])->countAllResults(), 're-running never duplicates');

        service('receipts')->void($rid, 'Payment reversed by bank');
        service('incentives')->generate(date('Y-m'));
        $this->assertSame('rejected', db_connect()->table('incentive_entries')->where('id', $rows[0]['id'])->get()->getRow()->status, 'voided receipt cancels the proposal');
    }

    public function testPayrollAiIsOffByDefaultAndReadOnlyForAllowlistedEmployeesWhenEnabled(): void
    {
        db_connect()->table('ai_capabilities')->like('code', 'ai.payroll.', 'after')->update(['enabled' => 0, 'share_with_provider' => 0, 'monthly_cost_ceiling' => '0.00', 'scope_config' => null]);
        $owner = $this->makeUser('super_admin');
        $this->loginAs($owner);
        $a = $this->employee('AI Scope Allowed', '2025-04-01', '21000');
        $b = $this->employee('AI Scope Other', '2025-04-01', '22000');
        service('employees')->saveBank($a, ['bank_name' => 'Test Bank', 'account_number' => '998877665544', 'ifsc' => 'HDFC0001234']);
        $run = service('payroll')->createRun('2025-12');
        service('payroll')->calculate($run);
        service('payroll')->finalize($run);
        [$plain] = service('aiGateway')->issueToken(['ai.payroll.read_approved_summary'], ['employee_ids' => [$a], 'periods' => ['2025-12']], 'Monthly summary', 1, null);
        auth('session')->logout();
        $h = ['Authorization' => 'Bearer ' . $plain];

        $off = $this->withHeaders($h)->get('api/ai/payroll/summary?period=2025-12');
        $this->assertSame(403, $off->response()->getStatusCode(), 'payroll AI is OFF by default');
        $this->assertStringContainsString('capability_disabled', $off->response()->getBody());

        db_connect()->table('ai_capabilities')->where('code', 'ai.payroll.read_approved_summary')->update(['enabled' => 1, 'share_with_provider' => 1, 'monthly_cost_ceiling' => '1.00']);
        $on = $this->withHeaders($h)->get('api/ai/payroll/summary?period=2025-12');
        $on->assertStatus(200);
        $data = json_decode($on->response()->getBody(), true);
        $this->assertTrue($data['read_only']);
        $this->assertCount(1, $data['items'], 'only the allowlisted employee');
        $codeA = db_connect()->table('employees')->where('id', $a)->get()->getRow()->employee_code;
        $this->assertSame($codeA, $data['items'][0]['employee_code']);
        $this->assertSame(['employee_code', 'payable_days', 'gross_pay', 'total_deductions', 'net_pay'], array_keys($data['items'][0]));
        $body = $on->response()->getBody();
        foreach (['998877665544', '5544', 'AI Scope', 'HDFC', 'monthly_gross', 'basic'] as $x) {
            $this->assertStringNotContainsString($x, $body);
        }

        // other periods, other capabilities, writes and finance/payroll screens stay closed
        $this->assertSame(403, $this->withHeaders($h)->get('api/ai/payroll/summary?period=2025-11')->response()->getStatusCode());
        $this->assertSame(403, $this->withHeaders($h)->get('api/ai/payroll/period-summary?period=2025-12')->response()->getStatusCode());
        $this->assertSame(404, $this->withHeaders($h)->post('api/ai/payroll/summary?period=2025-12', ['net_pay' => '99999'])->response()->getStatusCode());
        $this->assertSame(404, $this->withHeaders($h)->post('api/ai/payroll/finalize')->response()->getStatusCode());
        $this->assertSame(404, $this->withHeaders($h)->get('api/ai/employees/' . $a . '/bank')->response()->getStatusCode());
        try {
            $res = $this->withHeaders($h)->post('hr/payroll/' . $run . '/pay', ['bank_cash_account_id' => '1']);
            $this->assertTrue($res->isRedirect(), 'no session = no payroll action');
        } catch (\CodeIgniter\Security\Exceptions\SecurityException) {
            // refused by CSRF before reaching the session check (403 in production)
        }
        $this->assertSame('21000.00', $this->item($run, $a)['net_pay']);
        $this->assertSame('0.00', $this->item($run, $a)['amount_paid']);
    }
}

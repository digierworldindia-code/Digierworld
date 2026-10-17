<?php

namespace Tests\Feature;

use App\Libraries\Dec;
use Tests\Support\DgrTestCase;

/** Expenses: GST & ITC per accountant rule, separation of duties, payment, scope and recurring reminders. */
final class ExpensesUiTest extends DgrTestCase
{
    private function category(string $name): int
    {
        return (int) db_connect()->table('expense_categories')->where('name', $name)->get()->getRow()->id;
    }

    private function vendor(string $state = '08'): int
    {
        return $this->makeContact(['name' => 'Vendor ' . bin2hex(random_bytes(2)), 'is_customer' => 0, 'is_vendor' => 1, 'gstin' => $this->gstin($state, 'CCCCC' . random_int(1000, 9999) . 'C'), 'state_code' => $state]);
    }

    private function idFrom(\CodeIgniter\Test\TestResponse $res): int
    {
        $res->assertRedirect();
        $this->assertMatchesRegularExpression('#finance/expenses/(\d+)$#', (string) $res->getRedirectUrl(), 'Got ' . $res->getRedirectUrl() . ' ' . json_encode(session('error')) . json_encode(session('errors')));
        preg_match('#finance/expenses/(\d+)$#', (string) $res->getRedirectUrl(), $m);

        return (int) $m[1];
    }

    public function testRecordApproveWithSeparationOfDutiesAndPay(): void
    {
        $this->setupCompany('08');
        $bank = $this->makeBankAccount('Expense Bank');
        $clerk = $this->makeUser('accountant', 'Clerk Accountant');
        $this->loginAs($clerk);
        $vendor = $this->vendor('08');
        $inputBefore = Dec::add($this->balance('input_cgst'), $this->balance('input_sgst'));
        $payBefore   = $this->balance('payable', $vendor);

        $this->get('finance/expenses/new')->assertSee('Record expense');
        $id = $this->idFrom($this->postForm('finance/expenses', [
            'expense_date' => date('Y-m-d'), 'category_id' => (string) $this->category('Hosting & Domains'), 'vendor_contact_id' => (string) $vendor,
            'vendor_invoice_no' => 'HOST/1001', 'description' => 'Annual VPS hosting', 'sac_hsn' => '998315',
            'taxable_amount' => '2000', 'gst_type' => 'cgst_sgst', 'gst_rate' => '18', 'paid_through' => 'unpaid',
        ]));
        $e = db_connect()->table('expenses')->where('id', $id)->get()->getRowArray();
        $this->assertSame('180.00', $e['cgst_amount']);
        $this->assertSame('180.00', $e['sgst_amount']);
        $this->assertSame('2360.00', $e['total_amount']);
        $this->assertSame('360.00', $e['itc_eligible_amount'], 'Business input with vendor GSTIN → full ITC per rule');
        $this->assertSame('pending', $e['approval_status']);
        $this->assertNull($e['journal_entry_id'], 'Nothing is posted before approval');

        // same vendor invoice again is a duplicate
        $this->postForm('finance/expenses', ['expense_date' => date('Y-m-d'), 'category_id' => (string) $this->category('Hosting & Domains'), 'vendor_contact_id' => (string) $vendor,
            'vendor_invoice_no' => 'HOST/1001', 'description' => 'dup', 'taxable_amount' => '2000', 'gst_type' => 'cgst_sgst', 'gst_rate' => '18'])->assertRedirect();
        $this->assertSame(1, db_connect()->table('expenses')->where(['vendor_contact_id' => $vendor, 'vendor_invoice_no' => 'HOST/1001'])->countAllResults());

        // the recorder cannot approve their own expense
        $page = $this->get('finance/expenses/' . $id);
        $page->assertSee('Separation of duties');
        $this->postForm('finance/expenses/' . $id . '/approve')->assertRedirect();
        $this->assertSame('pending', db_connect()->table('expenses')->where('id', $id)->get()->getRow()->approval_status);

        // a second accountant approves → accrual posted with eligible ITC
        $approver = $this->makeUser('accountant', 'Approver Accountant');
        $this->loginAs($approver);
        $this->postForm('finance/expenses/' . $id . '/approve')->assertRedirectTo(site_url('finance/expenses/' . $id));
        $e = db_connect()->table('expenses')->where('id', $id)->get()->getRowArray();
        $this->assertSame('approved', $e['approval_status']);
        $this->assertSame(Dec::add($inputBefore, '360'), Dec::add($this->balance('input_cgst'), $this->balance('input_sgst')));
        $this->assertSame(Dec::sub($payBefore, '2360'), $this->balance('payable', $vendor));

        // pay from bank
        $this->postForm('finance/expenses/' . $id . '/pay', ['amount' => '2360', 'payment_date' => date('Y-m-d'), 'mode' => 'neft', 'bank_cash_account_id' => (string) $bank, 'reference_no' => 'N123'])->assertRedirect();
        $e = db_connect()->table('expenses')->where('id', $id)->get()->getRowArray();
        $this->assertSame('paid', $e['payment_status']);
        $this->assertSame($payBefore, $this->balance('payable', $vendor));
        $this->assertSame('-2360.00', $this->bankBalance($bank));

        // overpayment refused
        $this->postForm('finance/expenses/' . $id . '/pay', ['amount' => '1', 'bank_cash_account_id' => (string) $bank])->assertRedirect();
        $this->assertSame(1, db_connect()->table('expense_payments')->where('expense_id', $id)->countAllResults());

        // approved expenses are locked
        $this->get('finance/expenses/' . $id . '/edit')->assertRedirect();

        $this->get('finance/expenses')->assertSee($e['expense_number']);
        $x = (string) $this->get('finance/expenses/export?format=csv')->response()->getBody();
        $this->assertStringContainsString('HOST/1001', $x);
    }

    public function testBlockedCreditAndMissingGstinGiveNoItc(): void
    {
        $this->setupCompany('08');
        $this->loginAs($this->makeUser('super_admin'));
        $vendor = $this->vendor('08');

        // Staff welfare / food → category default is the Sec 17(5) blocked rule
        $food = $this->idFrom($this->postForm('finance/expenses', ['expense_date' => date('Y-m-d'), 'category_id' => (string) $this->category('Staff Welfare / Food'), 'vendor_contact_id' => (string) $vendor,
            'description' => 'Team lunch', 'taxable_amount' => '1000', 'gst_type' => 'cgst_sgst', 'gst_rate' => '5']));
        $e = db_connect()->table('expenses')->where('id', $food)->get()->getRowArray();
        $this->assertSame('0.00', $e['itc_eligible_amount']);
        $this->assertSame('50.00', $e['itc_ineligible_amount']);

        // no vendor GSTIN → no ITC even for an eligible category
        $cash = $this->idFrom($this->postForm('finance/expenses', ['expense_date' => date('Y-m-d'), 'category_id' => (string) $this->category('Office Expenses & Supplies'),
            'description' => 'Stationery (local shop)', 'taxable_amount' => '500', 'gst_type' => 'cgst_sgst', 'gst_rate' => '12']));
        $this->assertSame('0.00', db_connect()->table('expenses')->where('id', $cash)->get()->getRow()->itc_eligible_amount);

        // super admin may approve own (logged); ineligible GST becomes part of the cost
        $before = $this->balance('input_cgst');
        $this->postForm('finance/expenses/' . $food . '/approve')->assertRedirect();
        $this->assertSame($before, $this->balance('input_cgst'), 'Blocked credit posts no input tax');
    }

    public function testRecorderSeesOnlyOwnExpensesAndRecurringReminder(): void
    {
        $this->setupCompany('08');
        $this->loginAs($this->makeUser('super_admin'));
        $other = $this->idFrom($this->postForm('finance/expenses', ['expense_date' => date('Y-m-d'), 'category_id' => (string) $this->category('Miscellaneous'),
            'description' => 'Owner misc ' . uniqid(), 'taxable_amount' => '100', 'gst_type' => 'none']));

        // a role with view_own + create only
        $roleId = (int) db_connect()->table('roles')->where('code', 'employee')->get()->getRow()->id;
        foreach (['finance.expense.view_own', 'finance.expense.create'] as $p) {
            $pid = (int) db_connect()->table('permissions')->where('perm_key', $p)->get()->getRow()->id;
            if (db_connect()->table('role_permissions')->where(['role_id' => $roleId, 'permission_id' => $pid])->countAllResults() === 0) {
                db_connect()->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
            }
        }
        $emp = $this->makeUser('employee', 'Field Staff');
        $this->loginAs($emp);
        $mine = $this->idFrom($this->postForm('finance/expenses', ['expense_date' => date('Y-m-d'), 'category_id' => (string) $this->category('Travel & Conveyance'),
            'description' => 'Auto fare to client', 'taxable_amount' => '250', 'gst_type' => 'none', 'paid_through' => 'employee_reimbursable']));
        $this->get('finance/expenses/' . $mine)->assertSee('Auto fare to client');
        $this->assertSame(404, $this->statusOf(fn () => $this->get('finance/expenses/' . $other)));
        $this->assertSame(403, $this->statusOf(fn () => $this->postForm('finance/expenses/' . $mine . '/approve')));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('finance/expenses/export?format=csv')));

        // recurring reminders: accountant sets one up; the list flags it when due
        $this->loginAs($this->makeUser('accountant'));
        $this->postForm('finance/expenses/recurring', ['name' => 'Office rent', 'category_id' => (string) $this->category('Office Rent'), 'amount' => '25000',
            'frequency' => 'monthly', 'next_due_date' => date('Y-m-d', strtotime('+2 days')), 'reminder_days_before' => '5'])->assertRedirect();
        $rid = (int) db_connect()->table('recurring_expenses')->where('name', 'Office rent')->orderBy('id', 'DESC')->get()->getRow()->id;
        $this->get('finance/expenses/recurring')->assertSee('Record bill');
        $this->get('finance/expenses/new?recurring=' . $rid)->assertSeeInField('description', 'Office rent');
        $id = $this->idFrom($this->postForm('finance/expenses', ['recurring_expense_id' => (string) $rid, 'expense_date' => date('Y-m-d'), 'category_id' => (string) $this->category('Office Rent'),
            'description' => 'Office rent ' . date('M Y'), 'taxable_amount' => '25000', 'gst_type' => 'none']));
        $this->assertGreaterThan(0, $id);
        $this->assertSame(date('Y-m-d', strtotime(date('Y-m-d', strtotime('+2 days')) . ' +1 months')), db_connect()->table('recurring_expenses')->where('id', $rid)->get()->getRow()->next_due_date);
    }
}

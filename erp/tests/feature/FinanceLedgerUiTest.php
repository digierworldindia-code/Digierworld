<?php

namespace Tests\Feature;

use App\Libraries\Dec;
use Tests\Support\DgrTestCase;

/** Bank/cash statements, cash deposits (not income), journal, trial balance, manual JV. */
final class FinanceLedgerUiTest extends DgrTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompany('08');
        $this->loginAs($this->makeUser('super_admin', 'Test Owner'));
    }

    public function testCashDepositMovesMoneyWithoutIncomeAndStatementsRunningBalance(): void
    {
        $bank = $this->makeBankAccount('Demo Current A/c');
        $cash = $this->cashAccountId();
        $c    = $this->makeContact();
        [$inv] = $this->issue('cash_memo', $c, [$this->line('10000')], 'cgst_sgst', '08', [], ['paid_at_counter_account' => $cash, 'paid_mode' => 'cash']);
        $sales = $this->balance('sales');
        $cashBefore = $this->bankBalance($cash);

        // cash of 11,800 cannot over-deposit 20,000
        $this->postForm('finance/transfers', ['from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '20000', 'transfer_date' => date('Y-m-d')])->assertRedirect();
        $this->assertSame(0, db_connect()->table('money_transfers')->where('amount', '20000.00')->countAllResults());

        $res = $this->postForm('finance/transfers', ['from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '11800', 'transfer_date' => date('Y-m-d'), 'reference_no' => 'SLIP-1']);
        $res->assertRedirect();
        $t = db_connect()->table('money_transfers')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('cash_deposit', $t['transfer_kind']);
        $this->assertSame(Dec::sub($cashBefore, '11800'), $this->bankBalance($cash));
        $this->assertSame('11800.00', $this->bankBalance($bank));
        $this->assertSame($sales, $this->balance('sales'), 'A deposit is not income');

        $this->get('finance/transfers')->assertSee($t['transfer_number']);
        $this->get('finance/transfers/' . $t['id'])->assertSee('SLIP-1');
        $this->get('finance/accounts')->assertSee('Demo Current A/c');
        $st = $this->get('finance/accounts/' . $bank);
        $st->assertSee('Closing balance');
        $st->assertSee('11,800.00');
        $x = (string) $this->get('finance/accounts/' . $bank . '/export?format=csv')->response()->getBody();
        $this->assertStringContainsString('Opening balance', $x);

        // void reverses both sides
        $this->postForm('finance/transfers/' . $t['id'] . '/void', ['reason' => 'Deposit slip bounced back'])->assertRedirect();
        $this->assertSame('0.00', $this->bankBalance($bank));
        $this->assertSame($cashBefore, $this->bankBalance($cash));
    }

    public function testOpeningBalanceIsJournalisedAndTrialBalanceTallies(): void
    {
        $bank = $this->makeBankAccount('Opening Test A/c');
        db_connect()->table('bank_cash_accounts')->where('id', $bank)->update(['opening_balance' => '50000.00', 'opening_date' => date('Y-m-01')]);
        db_connect()->transBegin();
        service('ledger')->syncOpeningBalance($bank);
        db_connect()->transCommit();
        $this->assertSame('50000.00', $this->bankBalance($bank));
        $eqBefore = Dec::add($this->balance('opening_equity'), '50000');
        $this->assertSame(Dec::sub($eqBefore, '50000'), $this->balance('opening_equity'));

        // changing it reverses the previous opening entry (no double count)
        db_connect()->table('bank_cash_accounts')->where('id', $bank)->update(['opening_balance' => '45000.00']);
        db_connect()->transBegin();
        service('ledger')->syncOpeningBalance($bank);
        db_connect()->transCommit();
        $this->assertSame('45000.00', $this->bankBalance($bank));
        $this->assertSame(Dec::sub($eqBefore, '45000'), $this->balance('opening_equity'));
        $this->get('finance/ledger/trial-balance')->assertSee('Debits equal credits');
    }

    public function testTrialBalanceAndManualJournal(): void
    {
        $c = $this->makeContact();
        $this->issue('tax_invoice', $c, [$this->line('10000')], 'cgst_sgst', '08');

        $tb = $this->get('finance/ledger/trial-balance');
        $tb->assertSee('Debits equal credits');
        $tb->assertSee('11,800.00');

        $capital = (int) db_connect()->table('ledger_accounts')->where('sub_type', 'capital')->get()->getRow()->id;
        $cashLedger = (int) db_connect()->table('bank_cash_accounts')->where('code', 'CASH')->get()->getRow()->ledger_account_id;

        $cashBefore = $this->bankBalance($this->cashAccountId());
        // unbalanced journal is rejected
        $this->postForm('finance/ledger/journal', ['entry_date' => date('Y-m-d'), 'narration' => 'Capital introduced by owner', 'lines' => [
            ['account' => (string) $cashLedger, 'debit' => '5000'], ['account' => (string) $capital, 'credit' => '4000'],
        ]])->assertRedirect();
        $this->assertSame(0, db_connect()->table('journal_entries')->where('source_type', 'manual')->where('narration', 'Capital introduced by owner')->countAllResults());

        $res = $this->postForm('finance/ledger/journal', ['entry_date' => date('Y-m-d'), 'narration' => 'Capital introduced by owner', 'lines' => [
            ['account' => (string) $cashLedger, 'debit' => '5000'], ['account' => (string) $capital, 'credit' => '5000'],
        ]]);
        $res->assertRedirect();
        $je = db_connect()->table('journal_entries')->where('source_type', 'manual')->where('narration', 'Capital introduced by owner')->get()->getRowArray();
        $this->assertSame('5000.00', $je['total_debit']);
        // bank/cash line is tagged so it shows on the cash statement
        $this->assertSame(1, db_connect()->table('journal_lines')->where(['journal_entry_id' => $je['id'], 'bank_cash_account_id' => $this->cashAccountId()])->countAllResults());
        $this->assertSame(Dec::add($cashBefore, '5000'), $this->bankBalance($this->cashAccountId()));

        $this->get('finance/ledger')->assertSee($je['entry_no']);
        $this->get('finance/ledger/entries/' . $je['id'])->assertSee('Capital introduced by owner');
        $this->get('finance/ledger/accounts')->assertSee('Capital');
        $this->get('finance/ledger/accounts/' . $capital)->assertSee('Closing balance');

        // reverse the manual journal
        $this->postForm('finance/ledger/entries/' . $je['id'] . '/reverse', ['reason' => 'Posted in error'])->assertRedirect();
        $this->assertSame($cashBefore, $this->bankBalance($this->cashAccountId()));

        // system entries cannot be reversed from the journal screen
        $sale = db_connect()->table('journal_entries')->where('source_type', 'billing_document')->get()->getRowArray();
        $this->postForm('finance/ledger/entries/' . $sale['id'] . '/reverse', ['reason' => 'Trying to undo a sale'])->assertRedirect();
        $this->assertNull(db_connect()->table('journal_entries')->where('id', $sale['id'])->get()->getRow()->reversed_by_id);

        $this->get('finance/ledger/trial-balance')->assertSee('Debits equal credits');
    }

    public function testLedgerScreensNeedLedgerPermission(): void
    {
        $this->loginAs($this->makeUser('billing_operator'));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('finance/ledger')));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('finance/accounts')));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('finance/transfers/new')));
    }
}

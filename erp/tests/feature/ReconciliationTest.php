<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/** Statement import (mapping preview → commit), duplicate skipping, matching and booking. */
final class ReconciliationTest extends DgrTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompany('08');
        $this->loginAs($this->makeUser('super_admin', 'Test Owner'));
    }

    private function storeCsv(string $csv, int $accountId): int
    {
        return service('files')->storeContents($csv, 'statement.csv', 'text/csv', 'bank_statement', 'bank_cash_account', $accountId);
    }

    public function testImportPreviewCommitDedupeMatchAndBook(): void
    {
        $bank = $this->makeBankAccount('Recon Bank');
        $c    = $this->makeContact(['name' => 'Acme Health Recon']);
        [$inv] = $this->issue('tax_invoice', $c, [$this->line('10000')], 'cgst_sgst', '08');
        $d = date('Y-m-d');
        $dmy = date('d/m/Y');
        $receipt = service('receipts')->create(['contact_id' => $c, 'receipt_date' => $d, 'amount' => '11800', 'mode' => 'upi', 'bank_cash_account_id' => $bank,
            'reference_no' => '628812345678', 'allocations' => [['document_id' => $inv, 'amount' => '11800']]]);
        $cash = $this->cashAccountId();
        db_connect()->transBegin();
        service('ledger')->post($d, 'manual', null, 'Test cash float', [['account' => service('ledger')->bankLedger($cash), 'debit' => '5000', 'bank_cash_account_id' => $cash], ['account' => service('ledger')->account('capital'), 'credit' => '5000']]);
        db_connect()->transCommit();
        $transfer = service('transfers')->create(['from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '5000', 'transfer_date' => $d]);

        $csv = "DEMO BANK LTD\nAccount No: XXXX9012\n\nDate,Narration,Chq./Ref.No.,Value Dt,Withdrawal Amt.,Deposit Amt.,Closing Balance\n"
            . "{$dmy},UPI/CR/628812345678/ACME HEALTH,UPI-628812345678,{$dmy},,\"11,800.00\",\"11,800.00\"\n"
            . "{$dmy},CASH DEPOSIT BHIWADI,,{$dmy},,\"5,000.00\",\"16,800.00\"\n"
            . "{$dmy},SMS CHARGES,,{$dmy},17.70,,\"16,782.30\"\n"
            . "*** End of statement ***\n";
        $fid = $this->storeCsv($csv, $bank);

        $map = $this->get('finance/reconciliation/map?file=' . $fid . '&account=' . $bank);
        $map->assertSee('3 transactions found');
        $map->assertSee('SMS CHARGES');

        $commit = ['file' => (string) $fid, 'account_id' => (string) $bank, 'header' => '2', 'map_txn_date' => '0', 'map_value_date' => '3', 'map_description' => '1',
            'map_reference' => '2', 'map_debit' => '4', 'map_credit' => '5', 'map_amount' => '', 'map_drcr' => '', 'map_balance' => '6'];
        $this->postForm('finance/reconciliation/import/commit', $commit)->assertRedirect();
        $this->assertSame(3, db_connect()->table('bank_import_lines')->where('bank_cash_account_id', $bank)->countAllResults());

        // importing the same file again adds nothing
        $this->postForm('finance/reconciliation/import/commit', $commit)->assertRedirect();
        $this->assertSame(3, db_connect()->table('bank_import_lines')->where('bank_cash_account_id', $bank)->countAllResults());
        $this->assertSame(3, (int) db_connect()->table('bank_imports')->where('bank_cash_account_id', $bank)->orderBy('id', 'DESC')->get()->getRow()->rows_duplicate);

        $lines = db_connect()->table('bank_import_lines')->where('bank_cash_account_id', $bank)->orderBy('id')->get()->getResultArray();
        // the UPI line suggests the receipt with a strong score (amount + date + UTR)
        $sug = service('reconciliation')->suggestions($lines[0]);
        $this->assertSame('receipt', $sug[0]['type']);
        $this->assertSame($receipt, $sug[0]['id']);
        $this->assertGreaterThanOrEqual(85, $sug[0]['score']);

        $page = $this->get('finance/reconciliation?account=' . $bank);
        $page->assertSee('UPI/CR/628812345678/ACME HEALTH');
        $page->assertSee('Confirm match');

        // wrong total is refused
        $this->postForm('finance/reconciliation/lines/' . $lines[1]['id'] . '/confirm', ['targets' => ['receipt:' . $receipt]])->assertRedirect();
        $this->assertSame('unmatched', db_connect()->table('bank_import_lines')->where('id', $lines[1]['id'])->get()->getRow()->match_status);

        $this->postForm('finance/reconciliation/lines/' . $lines[0]['id'] . '/confirm', ['targets' => ['receipt:' . $receipt]])->assertRedirect();
        $this->postForm('finance/reconciliation/lines/' . $lines[1]['id'] . '/confirm', ['targets' => ['transfer_in:' . $transfer]])->assertRedirect();
        $this->assertSame('matched', db_connect()->table('payment_receipts')->where('id', $receipt)->get()->getRow()->reconciliation_status);
        $this->assertSame('matched', db_connect()->table('money_transfers')->where('id', $transfer)->get()->getRow()->reconciliation_status);

        // a reconciled receipt cannot be voided until unmatched
        try {
            service('receipts')->void($receipt, 'test void attempt');
            $this->fail('Reconciled receipt must not be voidable');
        } catch (\App\Exceptions\BusinessRuleException) {
        }

        // bank charges booked straight from the statement
        $charges = (int) db_connect()->table('ledger_accounts')->where('code', '5300')->get()->getRow()->id;
        $this->postForm('finance/reconciliation/lines/' . $lines[2]['id'] . '/book', ['ledger_account_id' => (string) $charges, 'narration' => 'SMS alert charges'])->assertRedirect();
        $this->assertSame('matched', db_connect()->table('bank_import_lines')->where('id', $lines[2]['id'])->get()->getRow()->match_status);

        // books now agree with the statement closing balance
        $sum = service('reconciliation')->summary($bank);
        $this->assertSame('16782.30', $sum['statement_balance']);
        $this->assertSame('16782.30', $sum['book_balance']);
        $this->assertSame('0.00', $sum['difference']);
        $this->assertSame(0, $sum['unmatched_lines']);

        // unmatch restores both sides
        $this->postForm('finance/reconciliation/lines/' . $lines[0]['id'] . '/unmatch')->assertRedirect();
        $this->assertSame('unmatched', db_connect()->table('payment_receipts')->where('id', $receipt)->get()->getRow()->reconciliation_status);
        $this->assertSame('unmatched', db_connect()->table('bank_import_lines')->where('id', $lines[0]['id'])->get()->getRow()->match_status);
    }

    public function testReconciliationNeedsPermission(): void
    {
        $this->loginAs($this->makeUser('billing_operator'));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('finance/reconciliation')));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('finance/reconciliation/import')));
    }
}

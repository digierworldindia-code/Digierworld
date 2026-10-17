<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use Tests\Support\DgrTestCase;

/**
 * Specification §18 / §27 accounting and billing acceptance tests,
 * executed against the real MySQL schema and services.
 */
final class BillingAccountingTest extends DgrTestCase
{
    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompany('08');
        $owner = $this->makeUser('super_admin', 'Test Owner');
        $this->owner = (int) $owner->id;
        $this->loginAs($owner);
    }

    public function testGstSequence121Then122AndCashMemoSeriesIsIndependent(): void
    {
        $this->setNextNumber('tax_invoice', 121);
        $this->setNextNumber('cash_memo', 1);
        $c = $this->makeContact();
        $fy = fy_for_date(date('Y-m-d'))['short_code'];

        // drafts created in between never consume numbers
        $draftA = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $c, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'lines' => [$this->line()]]);
        $draftB = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $c, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'lines' => [$this->line()]]);
        $this->assertNull(service('billing')->find($draftA)['document_number']);

        [, $inv1] = $this->issue('tax_invoice', $c, [$this->line()], 'cgst_sgst', '08');
        [, $cm1]  = $this->issue('cash_memo', $c, [$this->line('500')], 'cgst_sgst', '08');
        [, $cm2]  = $this->issue('cash_memo', $c, [$this->line('700')], 'cgst_sgst', '08');
        [, $inv2] = $this->issue('tax_invoice', $c, [$this->line('2000')], 'cgst_sgst', '08');

        $this->assertSame("GST-{$fy}-121", $inv1);
        $this->assertSame("CM-{$fy}-0001", $cm1);
        $this->assertSame("CM-{$fy}-0002", $cm2);
        $this->assertSame("GST-{$fy}-122", $inv2, 'Cash memos must not consume the GST invoice sequence');
        $this->assertLessThanOrEqual(16, strlen($inv1), 'GST Rule 46: max 16 characters');
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9/\-]+$#', $inv1);

        // the drafts can still be finalized later and get the NEXT numbers
        $this->assertSame("GST-{$fy}-123", service('billing')->finalize($draftA)['document_number']);
        $this->assertSame("GST-{$fy}-124", service('billing')->finalize($draftB)['document_number']);
    }

    public function testCashMemoIsTaxedAndReportedLikeAnyTaxableSupply(): void
    {
        $c = $this->makeContact();
        [$id] = $this->issue('cash_memo', $c, [$this->line('10000', '18')], 'cgst_sgst', '08');
        $doc = service('billing')->find($id);
        $this->assertSame('900.00', $doc['cgst_total']);
        $this->assertSame('900.00', $doc['sgst_total']);
        $this->assertSame('0.00', $doc['igst_total']);
        $this->assertSame('11800.00', $doc['grand_total']);

        // it is in the output-tax ledger and in the GST KPIs (not hidden by the series)
        $je = db_connect()->table('journal_lines')->where('journal_entry_id', $doc['journal_entry_id'])->get()->getResultArray();
        $credits = array_sum(array_map(static fn ($l) => (float) $l['credit'], $je));
        $this->assertEqualsWithDelta(11800.0, $credits, 0.001);
        $k = (new \App\Services\Dashboard\DashboardService(db_connect(), service('authz')))->financeKpis(date('Y-m-01'), date('Y-m-t'));
        $this->assertGreaterThanOrEqual(10000.0, (float) $k['taxable_sales']);
        $this->assertGreaterThanOrEqual(900.0, (float) $k['cgst']);
    }

    public function testCashReceiptAgainstInvoiceDoesNotCreateSecondSaleAndDepositIsNotIncome(): void
    {
        $c = $this->makeContact();
        [$inv] = $this->issue('tax_invoice', $c, [$this->line('10000')], 'cgst_sgst', '08');
        $salesBefore = $this->balance('sales');
        $outputBefore = Dec::add($this->balance('output_cgst'), $this->balance('output_sgst'));
        $cash = $this->cashAccountId();
        $cashBefore = $this->bankBalance($cash);

        // ₹5,000 cash against the invoice
        service('receipts')->create(['contact_id' => $c, 'receipt_date' => date('Y-m-d'), 'amount' => '5000', 'mode' => 'cash', 'bank_cash_account_id' => $cash,
            'allocations' => [['document_id' => $inv, 'amount' => '5000']]]);
        $doc = service('billing')->find($inv);
        $this->assertSame('6800.00', $doc['balance_due'], 'outstanding reduces by the receipt');
        $this->assertSame('partial', $doc['payment_status']);
        $this->assertSame($salesBefore, $this->balance('sales'), 'sales must NOT rise again on receipt');
        $this->assertSame(Dec::add($cashBefore, '5000'), $this->bankBalance($cash));

        // deposit that cash into the bank: cash ↓, bank ↑, no revenue, no GST
        $bank = $this->makeBankAccount('Test Savings A/c');
        service('transfers')->create(['from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '5000', 'transfer_date' => date('Y-m-d')]);
        $this->assertSame($cashBefore, $this->bankBalance($cash), 'cash in hand falls by the deposit');
        $this->assertSame('5000.00', $this->bankBalance($bank), 'bank rises by the deposit');
        $this->assertSame($salesBefore, $this->balance('sales'), 'deposit is not income');
        $this->assertSame($outputBefore, Dec::add($this->balance('output_cgst'), $this->balance('output_sgst')), 'deposit creates no GST');

        // exactly one receipt and one transfer exist for this money
        $this->assertSame(1, db_connect()->table('payment_receipts')->where(['contact_id' => $c, 'status' => 'active'])->countAllResults());
        $this->assertSame(1, db_connect()->table('money_transfers')->where(['to_account_id' => $bank, 'status' => 'active'])->countAllResults());
    }

    public function testPartialThenUpiSettlesInvoiceFully(): void
    {
        $c    = $this->makeContact();
        $bank = $this->makeBankAccount('UPI Collection A/c');
        [$inv] = $this->issue('tax_invoice', $c, [$this->line('10000')], 'cgst_sgst', '08');
        service('receipts')->create(['contact_id' => $c, 'receipt_date' => date('Y-m-d'), 'amount' => '4000', 'mode' => 'cash', 'bank_cash_account_id' => $this->cashAccountId(), 'allocations' => [['document_id' => $inv, 'amount' => '4000']]]);
        service('receipts')->create(['contact_id' => $c, 'receipt_date' => date('Y-m-d'), 'amount' => '7800', 'mode' => 'upi', 'reference_no' => 'UTR123456', 'bank_cash_account_id' => $bank, 'allocations' => [['document_id' => $inv, 'amount' => '7800']]]);
        $doc = service('billing')->find($inv);
        $this->assertSame('paid', $doc['payment_status']);
        $this->assertSame('0.00', $doc['balance_due']);
        $this->assertSame('11800.00', $doc['amount_paid']);
        $this->assertSame('0.00', $this->balance('receivable', $c), 'customer receivable fully cleared');
        $this->assertSame(2, db_connect()->table('payment_allocations')->where('document_id', $inv)->countAllResults());
    }

    public function testOverpaymentBecomesAdvanceAndIsAppliedLater(): void
    {
        $c = $this->makeContact();
        [$inv1] = $this->issue('tax_invoice', $c, [$this->line('1000')], 'cgst_sgst', '08');
        $rid = service('receipts')->create(['contact_id' => $c, 'receipt_date' => date('Y-m-d'), 'amount' => '5000', 'mode' => 'cash', 'bank_cash_account_id' => $this->cashAccountId(), 'allocations' => [['document_id' => $inv1, 'amount' => '1180']]]);
        $r = db_connect()->table('payment_receipts')->where('id', $rid)->get()->getRowArray();
        $this->assertSame('3820.00', $r['unapplied_amount']);
        $this->assertSame('-3820.00', $this->balance('customer_advance', $c), 'advance held as liability, not revenue');
        [$inv2] = $this->issue('tax_invoice', $c, [$this->line('2000')], 'cgst_sgst', '08');
        service('receipts')->applyAdvance($rid, [['document_id' => $inv2, 'amount' => '2360']]);
        $this->assertSame('paid', service('billing')->find($inv2)['payment_status']);
        $this->assertSame('-1460.00', $this->balance('customer_advance', $c));
    }

    public function testDuplicateReceiptIsDetected(): void
    {
        $c = $this->makeContact();
        $in = ['contact_id' => $c, 'receipt_date' => date('Y-m-d'), 'amount' => '999', 'mode' => 'cash', 'bank_cash_account_id' => $this->cashAccountId()];
        service('receipts')->create($in);
        $this->expectException(BusinessRuleException::class);
        service('receipts')->create($in);
    }

    public function testCreditNoteReducesBalanceWithoutTouchingTheInvoice(): void
    {
        $c = $this->makeContact(['gstin' => $this->gstin('07', 'CCCCC2222C'), 'state_code' => '07', 'gst_registration_type' => 'registered']);
        [$inv, $no] = $this->issue('tax_invoice', $c, [$this->line('10000')], 'igst', '07');
        $snapshotBefore = db_connect()->table('billing_document_snapshots')->where('document_id', $inv)->get()->getRow()->content_hash;
        $salesBefore = $this->balance('sales_returns');

        $cn = service('billing')->duplicate($inv, 'credit_note');
        service('billing')->saveDraft(['contact_id' => $c, 'place_of_supply_state_code' => '07', 'gst_type' => 'igst', 'original_document_id' => $inv,
            'lines' => [$this->line('2000')], 'lock_version' => service('billing')->find($cn)['lock_version']], $cn);
        $res = service('billing')->finalize($cn, ['reason_code' => 'post_sale_discount', 'reason_text' => 'Agreed discount']);
        $this->assertStringStartsWith('CN-', $res['document_number']);

        $doc = service('billing')->find($inv);
        $this->assertSame('finalized', $doc['status'], 'original invoice stays finalized and intact');
        $this->assertSame($no, $doc['document_number']);
        $this->assertSame('2360.00', $doc['amount_credited']);
        $this->assertSame('9440.00', $doc['balance_due']);
        $this->assertSame($snapshotBefore, db_connect()->table('billing_document_snapshots')->where('document_id', $inv)->get()->getRow()->content_hash);
        $this->assertSame(Dec::add($salesBefore, '2000'), $this->balance('sales_returns'));
        // GST is reversed only for the credited value (IGST 18% of 2,000 = 360)
        $this->assertSame('360.00', service('billing')->find($cn)['igst_total']);
    }

    public function testFinalizedDocumentsCannotBeEditedOrDeleted(): void
    {
        $c = $this->makeContact();
        [$id] = $this->issue('tax_invoice', $c, [$this->line()], 'cgst_sgst', '08');
        try {
            service('billing')->saveDraft(['contact_id' => $c, 'lines' => [$this->line('1')]], $id);
            $this->fail('edit of a finalized invoice must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Only drafts', $e->getMessage());
        }
        $this->expectException(BusinessRuleException::class);
        service('billing')->deleteDraft($id);
    }

    public function testImpossibleTaxTypeAndMissingPlaceOfSupplyBlockFinalization(): void
    {
        $c  = $this->makeContact(); // Rajasthan customer, supplier also Rajasthan
        $id = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $c, 'place_of_supply_state_code' => '08', 'gst_type' => 'igst', 'lines' => [$this->line()]]);
        $this->assertSame('blocked', service('billing')->find($id)['tax_review_status']);
        try {
            service('billing')->finalize($id);
            $this->fail('IGST on an intra-state supply must be blocked');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not legally possible', $e->getMessage());
        }
        $id2 = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $c, 'place_of_supply_state_code' => '', 'gst_type' => 'cgst_sgst', 'lines' => [$this->line()]]);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Place of supply is required');
        service('billing')->finalize($id2);
    }

    public function testChangingCustomerRecomputesSuggestedTaxType(): void
    {
        $local = $this->makeContact();
        $delhi = $this->makeContact(['gstin' => $this->gstin('07', 'DDDCD3333D'), 'state_code' => '07', 'gst_registration_type' => 'registered']);
        $calc1 = service('billing')->compute(['doc_type' => 'tax_invoice', 'contact_id' => $local, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst'], [$this->line()]);
        $calc2 = service('billing')->compute(['doc_type' => 'tax_invoice', 'contact_id' => $delhi, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst'], [$this->line()]);
        $this->assertSame('cgst_sgst', $calc1['advice']['suggested_type']);
        $this->assertSame('igst', $calc2['advice']['suggested_type']);
        $this->assertSame('07', $calc2['advice']['suggested_pos']);
        $this->assertSame('review', $calc2['advice']['status'], 'POS differing from the registered recipient state needs review');
    }

    public function testReviewRequiresAuthorisedReason(): void
    {
        $delhi = $this->makeContact(['gstin' => $this->gstin('07', 'EEEFE4444E'), 'state_code' => '07', 'gst_registration_type' => 'registered']);
        $id = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $delhi, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'lines' => [$this->line()]]);
        try {
            service('billing')->finalize($id);
            $this->fail('review status must require a reason');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }
        $res = service('billing')->finalize($id, ['tax_review_reason' => 'Service performed at client site in Bhiwadi; accountant confirmed POS 08']);
        $this->assertNotEmpty($res['document_number']);
        $this->assertSame('resolved', service('billing')->find($id)['tax_review_status']);
    }

    public function testMonthlyRegistersReconcileWithoutDoubleCounting(): void
    {
        $from = date('Y-m-01');
        $to   = date('Y-m-t');
        $db   = db_connect();
        $k0   = (new \App\Services\Dashboard\DashboardService($db, service('authz')))->financeKpis($from, $to);
        $c = $this->makeContact();
        [$inv] = $this->issue('tax_invoice', $c, [$this->line('10000')], 'cgst_sgst', '08');
        [$cm]  = $this->issue('cash_memo', $c, [$this->line('5000')], 'cgst_sgst', '08', [], ['paid_at_counter_account' => $this->cashAccountId(), 'paid_mode' => 'cash']);
        service('receipts')->create(['contact_id' => $c, 'receipt_date' => date('Y-m-d'), 'amount' => '11800', 'mode' => 'cash', 'bank_cash_account_id' => $this->cashAccountId(), 'allocations' => [['document_id' => $inv, 'amount' => '11800']]]);
        $bank = $this->makeBankAccount('Recon Bank');
        service('transfers')->create(['from_account_id' => $this->cashAccountId(), 'to_account_id' => $bank, 'amount' => '17700', 'transfer_date' => date('Y-m-d')]);
        $k1 = (new \App\Services\Dashboard\DashboardService($db, service('authz')))->financeKpis($from, $to);

        $this->assertSame('11800.00', Dec::sub($k1['invoiced'], $k0['invoiced']), 'invoiced counts the tax invoice once');
        $this->assertSame('5900.00', Dec::sub($k1['cash_memos'], $k0['cash_memos']), 'cash memo counted in its own register');
        $this->assertSame('15000.00', Dec::sub($k1['taxable_sales'], $k0['taxable_sales']), 'taxable sales = invoice + cash memo, once each');
        $this->assertSame('17700.00', Dec::sub($k1['collected'], $k0['collected']), 'collections = receipts only, not the deposit');
        $this->assertSame('2700.00', Dec::sub($k1['output_tax'], $k0['output_tax']));
        $this->assertSame('17700.00', Dec::sub($k1['cash_deposited'], $k0['cash_deposited']), 'deposit shown separately as a transfer');
        $this->assertSame(Dec::sub($k1['outstanding'], $k0['outstanding']), '0.00', 'both documents fully paid');
        // accrual vs cash perspectives differ only by timing, never by double count
        $this->assertSame(Dec::add(Dec::sub($k1['invoiced'], $k0['invoiced']), Dec::sub($k1['cash_memos'], $k0['cash_memos'])), Dec::sub($k1['collected'], $k0['collected']));
        // the trial balance of every entry is balanced
        $tb = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->getRowArray();
        $this->assertSame(Dec::round($tb['d']), Dec::round($tb['c']));
    }

    public function testConcurrentFinalizationNeverDuplicatesNumbers(): void
    {
        $c   = $this->makeContact();
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $c, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'lines' => [$this->line((string) (1000 + $i))]]);
        }
        $startAt = (int) (microtime(true) * 1000) + 1500;
        $procs   = [];
        foreach ($ids as $id) {
            $procs[] = proc_open([PHP_BINARY, 'tests/_support/bin/finalize_worker.php', (string) $id, (string) $this->owner, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
            $outs[]  = $pipes;
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $results[] = json_decode((string) stream_get_contents($outs[$i][1]), true) ?? ['ok' => false, 'error' => stream_get_contents($outs[$i][2])];
            proc_close($p);
        }
        $numbers = array_column(array_filter($results, static fn ($r) => $r['ok'] ?? false), 'number');
        $this->assertCount(6, $numbers, 'all concurrent finalizations succeed: ' . json_encode($results));
        $this->assertCount(6, array_unique($numbers), 'no duplicate statutory numbers under concurrency');
        $seq = array_map(static fn ($n) => (int) substr($n, strrpos($n, '-') + 1), $numbers);
        sort($seq);
        $this->assertSame(range($seq[0], $seq[0] + 5), $seq, 'numbers are consecutive with no gaps');
    }
}

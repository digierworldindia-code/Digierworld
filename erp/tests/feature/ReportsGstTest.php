<?php

namespace Tests\Feature;

use App\Libraries\Dec;
use App\Services\Reports\GstReturnService;
use App\Services\Reports\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DgrTestCase;

/** Registers, GST working papers and their reconciliation with the ledger. */
final class ReportsGstTest extends DgrTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompany('08');
        $this->loginAs($this->makeUser('super_admin', 'Test Owner'));
    }

    /** Movement (Cr − Dr) of a ledger in the period. */
    private function credits(string $subType, string $from, string $to): string
    {
        $r = db_connect()->query('SELECT COALESCE(SUM(jl.credit - jl.debit),0) n FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE jl.ledger_account_id = ? AND je.entry_date BETWEEN ? AND ?', [service('ledger')->account($subType), $from, $to])->getRow()->n;

        return Dec::round($r);
    }

    private function xlsxSheets(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $book = IOFactory::load($tmp);
        @unlink($tmp);
        $out = [];
        foreach ($book->getWorksheetIterator() as $ws) {
            $out[$ws->getTitle()] = $ws->toArray(null, false, false, false);
        }

        return $out;
    }

    public function testRegistersGstWorkbooksAndLedgerAgree(): void
    {
        $from = date('Y-m-01');
        $to   = date('Y-m-t');
        $gst  = new GstReturnService(db_connect());
        $before = ['out' => $gst->outputByHead($from, $to), 'itc' => $gst->itcByHead($from, $to)];

        $b2bClient = $this->makeContact(['name' => 'B2B Rajasthan', 'gstin' => $this->gstin('08', 'DDDCD1111D'), 'state_code' => '08']);
        $smallUr   = $this->makeContact(['name' => 'Small UR Delhi', 'state_code' => '07']);
        $bigUr     = $this->makeContact(['name' => 'Big UR Haryana', 'state_code' => '06']);
        [$inv1, $n1] = $this->issue('tax_invoice', $b2bClient, [$this->line('10000')], 'cgst_sgst', '08');
        [, $n2]      = $this->issue('tax_invoice', $smallUr, [$this->line('5000')], 'igst', '07');
        [, $n3]      = $this->issue('tax_invoice', $bigUr, [$this->line('150000')], 'igst', '06');
        [, $cm]      = $this->issue('cash_memo', $b2bClient, [$this->line('1000')], 'cgst_sgst', '08');
        $cnId = service('billing')->duplicate($inv1, 'credit_note');
        service('billing')->saveDraft(['contact_id' => $b2bClient, 'original_document_id' => $inv1, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst',
            'lock_version' => service('billing')->find($cnId)['lock_version'], 'lines' => [$this->line('2000')]], $cnId);
        $cnNo = service('billing')->finalize($cnId, ['reason_code' => 'post_sale_discount', 'reason_text' => 'Volume discount'])['document_number'];

        // expense with ITC (IGST from an out-of-state vendor)
        $vendor = $this->makeContact(['name' => 'Cloud Vendor', 'is_customer' => 0, 'is_vendor' => 1, 'gstin' => $this->gstin('29', 'EEECE2222E'), 'state_code' => '29']);
        $exp = service('expenses')->create(['expense_date' => date('Y-m-d'), 'category_id' => (int) db_connect()->table('expense_categories')->where('name', 'Hosting & Domains')->get()->getRow()->id,
            'vendor_contact_id' => $vendor, 'description' => 'Cloud hosting', 'taxable_amount' => '1000', 'gst_type' => 'igst', 'gst_rate' => '18', 'allow_duplicate' => true]);
        service('expenses')->approve($exp);

        // ---- output by head moved exactly by these documents, and equals the ledger movement
        $out = $gst->outputByHead($from, $to);
        $this->assertSame(Dec::add($before['out']['cgst'], '900', '90', '-180'), $out['cgst']);
        $this->assertSame(Dec::add($before['out']['igst'], '900', '27000'), $out['igst']);
        $this->assertSame($this->credits('output_cgst', $from, $to), $out['cgst'], 'GST report must equal the output CGST ledger');
        $this->assertSame($this->credits('output_igst', $from, $to), $out['igst'], 'GST report must equal the output IGST ledger');
        $itc = $gst->itcByHead($from, $to);
        $this->assertSame(Dec::add($before['itc']['igst'], '180'), $itc['igst']);

        // ---- registers
        $reports = new ReportService(db_connect(), service('authz'));
        $sales = $reports->run('sales_register', $from, $to);
        $nums  = array_column($sales['rows'], 'document_number');
        $this->assertContains($n1, $nums);
        $this->assertNotContains($cm, $nums, 'Cash memos have their own register');
        $combined = $reports->run('combined_register', $from, $to);
        $cnRow = array_values(array_filter($combined['rows'], static fn ($r) => $r['document_number'] === $cnNo))[0];
        $this->assertSame('-2360.00', $cnRow['grand_total'], 'Credit notes are negative in the combined register');
        // combined register total equals the receivable movement from billing documents (sales − credit notes)
        $regTotal = Dec::add('0', ...array_column($combined['rows'], 'grand_total'));
        $ledger   = Dec::round(db_connect()->query("SELECT COALESCE(SUM(jl.debit - jl.credit),0) n FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE jl.ledger_account_id = ? AND je.source_type = 'billing_document' AND je.entry_date BETWEEN ? AND ? AND je.reversal_of_id IS NULL AND je.reversed_by_id IS NULL",
            [service('ledger')->account('receivable'), $from, $to])->getRow()->n);
        $this->assertSame($ledger, $regTotal, 'Combined register total must equal billed receivables in the ledger');

        // ---- GSTR-1 classification
        $sheets = [];
        foreach ($gst->gstr1Sheets($from, $to) as $s) {
            $sheets[$s['title']] = $s['rows'];
        }
        $b2b = array_values(array_filter($sheets['b2b'], static fn ($r) => $r['number'] === $n1));
        $this->assertCount(1, $b2b);
        $this->assertSame('10000.00', Dec::round($b2b[0]['taxable']));
        $this->assertSame('08-Rajasthan', $b2b[0]['pos']);
        $this->assertNotEmpty(array_filter($sheets['b2b'], static fn ($r) => $r['number'] === $cm), 'Cash memo to a GSTIN holder is a B2B supply');
        $this->assertNotEmpty(array_filter($sheets['b2cl'], static fn ($r) => $r['number'] === $n3), 'Inter-state B2C above ₹1 lakh → B2CL');
        $this->assertEmpty(array_filter($sheets['b2cl'], static fn ($r) => $r['number'] === $n2));
        $this->assertNotEmpty(array_filter($sheets['b2cs'], static fn ($r) => str_starts_with($r['pos'], '07-')), 'Small inter-state B2C → B2CS by place of supply');
        $cdnr = array_values(array_filter($sheets['cdnr'], static fn ($r) => $r['number'] === $cnNo));
        $this->assertSame('C', $cdnr[0]['note_type']);
        $this->assertSame('2000.00', Dec::round($cdnr[0]['taxable']));
        $docs = array_column($sheets['docs'], null, 'nature');
        $this->assertArrayHasKey('Invoices for outward supply', $docs);
        $this->assertArrayHasKey('Credit Note', $docs);

        // ---- GSTR-3B: 3.1(a) tax equals output by head; table 4 has the IGST credit
        $s3b = [];
        foreach ($gst->gstr3bSheets($from, $to) as $s) {
            $s3b[$s['title']] = $s['rows'];
        }
        $this->assertSame($out['cgst'], Dec::round($s3b['3.1 Outward supplies'][0]['cgst']));
        $this->assertSame($out['igst'], Dec::round($s3b['3.1 Outward supplies'][0]['igst']));
        $this->assertSame($itc['igst'], Dec::round($s3b['4 Eligible ITC'][2]['igst']));

        // ---- screens and downloads
        $this->get('reports')->assertSee('Tax invoice register');
        foreach (array_keys(ReportService::REPORTS) as $code) {
            $res = $this->get('reports/' . $code . '?period=this_month');
            $res->assertOK();
        }
        $this->get('reports/combined_register?period=this_month')->assertSee($cnNo);
        $x = (string) $this->get('reports/sales_register?period=this_month&format=xlsx')->response()->getBody();
        $this->assertStringStartsWith('PK', $x);
        $this->get('reports/gst?month=' . date('Y-m'))->assertSee('Cash payable');
        $book = $this->xlsxSheets((string) $this->get('reports/gst/gstr1?month=' . date('Y-m'))->response()->getBody());
        foreach (['About', 'b2b', 'b2cl', 'b2cs', 'cdnr', 'cdnur', 'exp', 'exemp', 'hsn(b2b)', 'hsn(b2c)', 'docs', 'at (review)'] as $sheet) {
            $this->assertArrayHasKey($sheet, $book, "GSTR-1 workbook sheet {$sheet}");
        }
        $flat = json_encode($book['b2b']);
        $this->assertStringContainsString($n1, $flat);
        $book3b = $this->xlsxSheets((string) $this->get('reports/gst/gstr3b?month=' . date('Y-m'))->response()->getBody());
        $this->assertArrayHasKey('6.1 Payment (indicative)', $book3b);
        $this->get('reports/digest')->assertSee('Needs attention');
        $this->assertGreaterThan(0, db_connect()->table('report_exports')->where('report_code', 'gstr1_prep')->countAllResults(), 'Downloads are recorded');
    }

    public function testProfitAndLossAndCashFlowExcludeTransfers(): void
    {
        $from = date('Y-m-01');
        $to   = date('Y-m-t');
        $reports = new ReportService(db_connect(), service('authz'));
        $pl0 = $reports->run('profit_loss', $from, $to);
        $cf0 = $reports->run('cash_flow', $from, $to);
        $net = static fn ($pl) => end($pl['rows'])['amount'];
        $cfNet = static fn ($cf) => Dec::add('0', ...array_column($cf['rows'], 'net'));

        $bank = $this->makeBankAccount('PL Bank');
        $c = $this->makeContact();
        [$inv] = $this->issue('tax_invoice', $c, [$this->line('10000')], 'cgst_sgst', '08');
        service('receipts')->create(['contact_id' => $c, 'receipt_date' => date('Y-m-d'), 'amount' => '11800', 'mode' => 'cash', 'bank_cash_account_id' => $this->cashAccountId(), 'allocations' => [['document_id' => $inv, 'amount' => '11800']], 'allow_duplicate' => true]);
        service('transfers')->create(['from_account_id' => $this->cashAccountId(), 'to_account_id' => $bank, 'amount' => '11800', 'transfer_date' => date('Y-m-d')]);

        $pl1 = $reports->run('profit_loss', $from, $to);
        $this->assertSame(Dec::add($net($pl0), '10000'), $net($pl1), 'Profit rises by the taxable value only (GST is not income)');
        $cf1 = $reports->run('cash_flow', $from, $to);
        $this->assertSame(Dec::add($cfNet($cf0), '11800'), $cfNet($cf1), 'The receipt counts once; the cash→bank deposit is not cash flow');
    }

    public function testReportsArePermissionGated(): void
    {
        $this->loginAs($this->makeUser('billing_operator'));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('reports')));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('reports/sales_register')));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('reports/gst/gstr1')));

        // an accountant without the export permission can view but not export
        $acct = $this->makeUser('accountant');
        $uid  = (int) $acct->id;
        $pid  = (int) db_connect()->table('permissions')->where('perm_key', 'reports.export')->get()->getRow()->id;
        db_connect()->table('user_permission_overrides')->insert(['user_id' => $uid, 'permission_id' => $pid, 'effect' => 'deny', 'reason' => 'test: no exports', 'created_by' => $uid]);
        $this->loginAs($acct);
        $this->get('reports/sales_register')->assertOK();
        $this->assertSame(403, $this->statusOf(fn () => $this->get('reports/sales_register?format=xlsx')));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('reports/gst/gstr3b')));
    }
}

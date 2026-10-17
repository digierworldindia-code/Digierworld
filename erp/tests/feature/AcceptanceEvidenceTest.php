<?php

namespace Tests\Feature;

use App\Libraries\Dec;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use Tests\Support\DgrTestCase;

/**
 * Spec §27 end-to-end evidence. Drives the real flows, asserts the printed
 * results and — when DGR_EVIDENCE_DIR is set — saves every generated PDF
 * and XLSX so they can be inspected (see docs/07-TEST-REPORT.md).
 */
final class AcceptanceEvidenceTest extends DgrTestCase
{
    private const CIF = 'CIF998877';

    private function save(string $name, string $bytes): void
    {
        $dir = getenv('DGR_EVIDENCE_DIR');
        if ($dir) {
            is_dir($dir) || mkdir($dir, 0775, true);
            file_put_contents(rtrim($dir, '/') . '/' . $name, $bytes);
        }
    }

    private function pdfText(string $bytes): string
    {
        return (new Parser())->parseContent($bytes)->getText();
    }

    /** All cell values of an XLSX as one string. */
    private function xlsxText(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $out = '';
        foreach (IOFactory::load($tmp)->getAllSheets() as $ws) {
            foreach ($ws->toArray(null, false, false, false) as $row) {
                $out .= implode('|', array_map('strval', $row)) . "\n";
            }
        }
        @unlink($tmp);

        return $out;
    }

    private function png(string $label, int $w = 360, int $h = 120): string
    {
        // transparent background, like a scanned signature/stamp cleaned up for printing
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagestring($im, 5, 12, (int) ($h / 2) - 8, $label, imagecolorallocate($im, 20, 20, 120));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function brand(string $type, string $bytes, int $ownerId): void
    {
        $fid = service('files')->storeContents($bytes, $type . '.png', 'image/png', $type === 'logo' ? 'logo' : $type);
        db_connect()->table('branding_assets')->where('asset_type', $type)->update(['is_active' => 0]);
        db_connect()->table('branding_assets')->insert([
            'asset_type' => $type, 'version' => 1, 'stored_file_id' => $fid, 'position_preset' => $type === 'logo' ? 'top_left' : 'bottom_right',
            'display_width_mm' => $type === 'logo' ? 40 : 30, 'enabled_doc_types' => json_encode(['tax_invoice', 'cash_memo', 'bill_of_supply']), 'is_active' => 1, 'created_by' => $ownerId,
        ]);
    }

    public function testOneTimeSetupBrandedInvoicesSequencesCashDepositAndNoCifAnywhere(): void
    {
        $db    = db_connect();
        $owner = $this->makeUser('super_admin', 'Evidence Owner');
        $this->loginAs($owner);

        // one-time setup: identity, logo (the official file, unchanged), signature, stamp, two banks with a default
        $this->setupCompany('08');
        $official = ROOTPATH . '../assets/images/logo.png';
        $logo     = is_file($official) ? (string) file_get_contents($official) : $this->png('DIGIE R WORLD');
        $this->brand('logo', $logo, (int) $owner->id);
        $this->brand('signature', $this->png('Authorised Signatory (TEST)'), (int) $owner->id);
        $this->brand('stamp', $this->png('COMPANY STAMP (TEST)', 200, 200), (int) $owner->id);
        $db->table('signature_policies')->insert(['role_id' => $db->table('roles')->where('code', 'super_admin')->get()->getRow()->id, 'doc_type' => 'tax_invoice', 'allowed' => 1, 'approved_by' => $owner->id, 'approved_at' => date('Y-m-d H:i:s')]);
        $bankA = $this->makeBankAccount('Evidence Current A/c');
        $bankB = $this->makeBankAccount('Evidence Savings A/c');
        $db->table('bank_cash_accounts')->update(['is_default_on_invoice' => 0]);
        $db->table('bank_cash_accounts')->where('id', $bankB)->update(['is_default_on_invoice' => 1]);

        // statutory numbering: tax invoice 121 → cash memos → 122
        $this->setNextNumber('tax_invoice', 121);
        $this->setNextNumber('cash_memo', 1);
        $intra = $this->makeContact(['name' => 'Evidence Jaipur Clinic', 'gstin' => $this->gstin('08', 'EEECE1234E'), 'state_code' => '08', 'gst_registration_type' => 'registered']);
        $inter = $this->makeContact(['name' => 'Evidence Bengaluru Labs', 'gstin' => $this->gstin('29', 'FFFCF5678F'), 'state_code' => '29', 'gst_registration_type' => 'registered']);
        [$inv1, $no1] = $this->issue('tax_invoice', $intra, [$this->line('10000')], 'cgst_sgst', '08', [], ['apply_signature' => true]);
        [$cm1, $cmNo1] = $this->issue('cash_memo', $intra, [$this->line('10000')], 'cgst_sgst', '08');
        [$cm2, $cmNo2] = $this->issue('cash_memo', $intra, [$this->line('2000')], 'cgst_sgst', '08');
        [$inv2, $no2] = $this->issue('tax_invoice', $inter, [$this->line('10000')], 'igst', '29');
        $this->assertStringEndsWith('121', $no1);
        $this->assertStringEndsWith('122', $no2, 'cash memos do not consume tax invoice numbers');
        $this->assertStringEndsWith('0001', $cmNo1);
        $this->assertStringEndsWith('0002', $cmNo2);

        // printed results
        [$pdf1] = service('documentPdf')->render($inv1);
        $this->save('01-tax-invoice-121-intra-state-cgst-sgst.pdf', $pdf1);
        $t1 = $this->pdfText($pdf1);
        foreach (['TAX INVOICE', $no1, 'CGST', 'SGST', '900.00', '11,800.00', '998361', 'Evidence Jaipur Clinic', 'Rajasthan'] as $s) {
            $this->assertStringContainsString($s, $t1, $s);
        }
        $this->assertStringNotContainsString('IGST', $t1);
        $this->assertStringContainsString('123456789012', $t1, 'selected default bank printed for payment');
        $snap = $db->table('billing_document_snapshots')->where('document_id', $inv1)->get()->getRowArray();
        $branding = json_decode($snap['branding'], true);
        $this->assertNotNull($branding['logo']);
        $this->assertSame(hash('sha256', $logo), $branding['logo']['sha256'], 'logo bytes stored unchanged');
        $this->assertNotNull($branding['signature'], 'signature applied (permitted by policy)');
        $this->assertNotNull($branding['stamp']);
        $this->assertSame($bankB, (int) json_decode($snap['bank'], true)['account_id']);

        [$pdf2] = service('documentPdf')->render($inv2);
        $this->save('02-tax-invoice-122-inter-state-igst.pdf', $pdf2);
        $t2 = $this->pdfText($pdf2);
        $this->assertStringContainsString('IGST', $t2);
        $this->assertStringContainsString('1,800.00', $t2);
        $this->assertStringContainsString('11,800.00', $t2);
        $this->assertStringNotContainsString('CGST', $t2);
        $this->assertStringNotContainsString('SGST', $t2);
        $this->assertNull(json_decode($db->table('billing_document_snapshots')->where('document_id', $inv2)->get()->getRow()->branding, true)['signature'] ?? null, 'no signature unless requested and permitted');

        [$pdfCm] = service('documentPdf')->render($cm1);
        $this->save('03-cash-memo-cm-0001.pdf', $pdfCm);
        $tc = $this->pdfText($pdfCm);
        $this->assertStringContainsString('900.00', $tc);
        $this->assertStringContainsString('11,800.00', $tc);

        // a billing operator cannot apply the signature or finalize
        $op = $this->makeUser('billing_operator', 'Evidence Operator');
        $this->loginAs($op);
        $draft = service('billing')->saveDraft(['doc_type' => 'tax_invoice', 'contact_id' => $intra, 'issue_date' => date('Y-m-d'), 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'lines' => [$this->line('5000')]]);
        $this->assertSame(403, $this->statusOf(fn () => $this->postForm('billing/documents/' . $draft . '/finalize', ['apply_signature' => '1'])));
        $this->assertSame('draft', $db->table('billing_documents')->where('id', $draft)->get()->getRow()->status);
        foreach (['settings/banks', 'reports/gst', 'reports/sales_register', 'finance/receipts'] as $u) {
            $this->assertSame(403, $this->statusOf(fn () => $this->get($u)), $u);
        }

        // cash received for CM-0001, deposited into the bank: one receipt, one transfer, no extra income
        $this->loginAs($owner);
        $sales = $this->balance('sales');
        $cash  = $this->cashAccountId();
        $rid   = service('receipts')->create(['contact_id' => $intra, 'receipt_date' => date('Y-m-d'), 'amount' => '11800', 'mode' => 'cash', 'bank_cash_account_id' => $cash, 'allocations' => [['document_id' => $cm1, 'amount' => '11800']]]);
        service('transfers')->create(['from_account_id' => $cash, 'to_account_id' => $bankA, 'amount' => '11800', 'transfer_date' => date('Y-m-d')]);
        $this->assertSame($sales, $this->balance('sales'), 'receipt and deposit are not income');
        $this->assertSame('11800.00', $this->bankBalance($bankA));
        $this->save('04-receipt-voucher.pdf', (string) $this->get('finance/receipts/' . $rid . '/pdf')->response()->getBody());

        // historical final invoice unchanged after the company edits its settings
        $db->table('company_settings')->where('id', 1)->update(['legal_name' => 'RENAMED COMPANY', 'reg_address_line1' => 'New Address']);
        [$again] = service('documentPdf')->render($inv1);
        $ta = $this->pdfText($again);
        $this->assertStringContainsString('TEST COMPANY (DEMO)', $ta);
        $this->assertStringNotContainsString('RENAMED COMPANY', $ta);
        $db->table('company_settings')->where('id', 1)->update(['legal_name' => 'TEST COMPANY (DEMO)', 'reg_address_line1' => 'Test Address']);

        // genuine XLSX exports; CIF in none of them
        $month = date('Y-m');
        $files = [
            '05-sales-register.xlsx' => 'reports/sales_register?period=this_month&format=xlsx',
            '06-cash-memo-register.xlsx' => 'reports/cash_memo_register?period=this_month&format=xlsx',
            '07-gstr1-preparation.xlsx' => 'reports/gst/gstr1?month=' . $month,
            '08-gstr3b-preparation.xlsx' => 'reports/gst/gstr3b?month=' . $month,
            '09-collections.xlsx' => 'reports/collections?period=this_month&format=xlsx',
            '10-bank-statement-current.xlsx' => 'finance/accounts/' . $bankA . '/export?format=xlsx',
            '11-receipts.xlsx' => 'finance/receipts/export?format=xlsx',
        ];
        $all = $t1 . $t2 . $tc;
        foreach ($files as $name => $uri) {
            $res = $this->get($uri);
            $res->assertOK();
            $bytes = (string) $res->response()->getBody();
            $this->assertStringStartsWith('PK', $bytes, $name . ' is a real XLSX');
            $this->save($name, $bytes);
            $all .= $this->xlsxText($bytes);
        }
        $this->assertStringContainsString($no1, $all);
        $this->assertStringContainsString($cmNo1, $all);
        $this->assertStringNotContainsString(self::CIF, $all, 'CIF never appears in any PDF or XLSX');
        $this->assertStringNotContainsString(self::CIF, (string) $this->get('settings/banks')->response()->getBody(), 'CIF masked even for the owner until revealed');
    }

    public function testHrAndCrmOutputsPayslipSalaryRegisterApplicantsAndLeads(): void
    {
        $db    = db_connect();
        $owner = $this->makeUser('super_admin', 'Evidence HR Owner');
        $this->loginAs($owner);
        $this->setupCompany('08');

        // employee with salary, bank details and one unpaid leave day in the month
        $emp = service('employees')->create(['full_name' => 'Evidence Employee', 'date_of_joining' => '2025-04-01', 'incentive_eligible' => '1']);
        service('salaries')->save($emp, ['effective_from' => '2025-04-01', 'monthly_gross' => '31000', 'proration_basis' => 'calendar_days', 'components' => [
            ['code' => 'BASIC', 'label' => 'Basic', 'component_type' => 'earning', 'amount' => '15500', 'is_prorated' => '1'],
            ['code' => 'HRA', 'label' => 'House rent allowance', 'component_type' => 'earning', 'amount' => '15500', 'is_prorated' => '1']]]);
        service('employees')->saveBank($emp, ['bank_name' => 'Evidence Bank', 'account_number' => '556677889900', 'ifsc' => 'HDFC0004321']);
        $lwp = $db->table('leave_types')->where('code', 'LWP')->get()->getRow()->id;
        $lr  = service('leaves')->request($emp, ['leave_type_id' => (string) $lwp, 'from_date' => '2026-01-14', 'to_date' => '2026-01-14']);
        service('leaves')->decide($lr, 'approved');
        $run = service('payroll')->createRun('2026-01');
        service('payroll')->calculate($run);
        service('payroll')->finalize($run);
        $item = $db->table('payroll_items')->where(['payroll_run_id' => $run, 'employee_id' => $emp])->get()->getRowArray();
        $this->assertSame('30000.00', $item['net_pay'], '31,000 × 30/31 after one unpaid day');
        $file = (int) $db->table('payslips')->where('payroll_item_id', $item['id'])->get()->getRow()->stored_file_id;
        $slip = (string) service('files')->contents($file);
        $this->save('12-payslip.pdf', $slip);
        $ts = $this->pdfText($slip);
        foreach (['PAYSLIP', 'January 2026', 'Evidence Employee', '30,000.00', '••••9900'] as $x) {
            $this->assertStringContainsString($x, $ts, $x);
        }
        $this->assertStringNotContainsString('556677889900', $ts, 'full bank account never printed on payslips');

        $reg = $this->get('hr/payroll/' . $run . '/register');
        $reg->assertOK();
        $regBytes = (string) $reg->response()->getBody();
        $this->save('13-salary-register.xlsx', $regBytes);
        $regText = $this->xlsxText($regBytes);
        $this->assertStringContainsString('Evidence Employee', $regText);
        $this->assertStringNotContainsString('556677889900', $regText);

        // applicants export carries no contact details or salary expectations
        $job  = service('recruitment')->createVacancy(['title' => 'Evidence Designer', 'status' => 'open', 'summary' => 'Design social media creatives for agency clients.', 'must_have' => "Canva or Photoshop\nPortfolio of social posts"]);
        $cand = service('recruitment')->createCandidate(['job_opening_id' => (string) $job, 'full_name' => 'Evidence Applicant', 'phone' => '9811122233', 'email' => 'evidence.applicant@example.com', 'expected_salary' => '42424', 'consent_given' => '1']);
        $ap = $this->get('hr/candidates/export');
        $ap->assertOK();
        $apBytes = (string) $ap->response()->getBody();
        $this->save('14-applicants.xlsx', $apBytes);
        $apText = $this->xlsxText($apBytes);
        $this->assertStringContainsString('Evidence Applicant', $apText);
        foreach (['42424', '9811122233', 'evidence.applicant@example.com'] as $x) {
            $this->assertStringNotContainsString($x, $apText, $x);
        }

        // CRM workbook (leads, calls, follow-ups, visits)
        $lead = service('leads')->create(['company_name' => 'Evidence Bakery', 'mobile' => '7' . random_int(100000000, 999999999)]);
        service('leads')->logCall($lead, ['outcome' => 'interested', 'notes' => 'Wants Instagram ads', 'next_action_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);
        $crm = $this->get('crm/export');
        $crm->assertOK();
        $crmBytes = (string) $crm->response()->getBody();
        $this->save('15-crm-workbook.xlsx', $crmBytes);
        $this->assertStringContainsString('Evidence Bakery', $this->xlsxText($crmBytes));
    }
}

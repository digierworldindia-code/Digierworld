<?php

namespace App\Services\Hr;

use App\Libraries\AmountInWords;
use App\Services\Files\FileStorage;
use App\Services\Pdf\PdfRenderer;
use CodeIgniter\Database\BaseConnection;

/**
 * Payslip PDFs. Each generation is a new stored version (corrections never
 * overwrite an issued payslip). Bank account numbers are printed masked.
 */
class PayslipService
{
    public function __construct(private readonly BaseConnection $db, private readonly PdfRenderer $pdf, private readonly FileStorage $files)
    {
    }

    /** @return array<string, mixed> everything the payslip template needs */
    public function data(int $itemId): array
    {
        $item = $this->db->table('payroll_items')->where('id', $itemId)->get()->getRowArray();
        $run  = $this->db->table('payroll_runs')->where('id', $item['payroll_run_id'])->get()->getRowArray();
        $emp  = $this->db->table('employees e')->select('e.employee_code, e.full_name, e.date_of_joining, e.work_location, d.name AS department, g.name AS designation')
            ->join('departments d', 'd.id = e.department_id', 'left')->join('designations g', 'g.id = e.designation_id', 'left')->where('e.id', $item['employee_id'])->get()->getRowArray();
        $bank = $this->db->table('employee_bank_details')->select('bank_name, account_last4, ifsc')->where('employee_id', $item['employee_id'])->get()->getRowArray();
        $comps = $this->db->table('payroll_item_components')->where('payroll_item_id', $itemId)->orderBy('id')->get()->getResultArray();
        $logo  = $this->db->table('branding_assets')->where(['asset_type' => 'logo', 'is_active' => 1])->get()->getRowArray();

        return [
            'item' => $item, 'run' => $run, 'emp' => $emp, 'bank' => $bank,
            'earnings' => array_values(array_filter($comps, static fn ($c) => $c['component_type'] === 'earning')),
            'deductions' => array_values(array_filter($comps, static fn ($c) => $c['component_type'] === 'deduction')),
            'employer' => array_values(array_filter($comps, static fn ($c) => $c['component_type'] === 'employer')),
            'payments' => $this->db->table('payroll_payments')->where('payroll_item_id', $itemId)->orderBy('payment_date')->get()->getResultArray(),
            'supplier' => (new \App\Services\Billing\SnapshotBuilder($this->db))->supplier(),
            'logo' => $logo ? $this->files->dataUri((int) $logo['stored_file_id']) : null,
            'words' => AmountInWords::inr($item['net_pay']),
        ];
    }

    public function render(int $itemId, int $version = 1): string
    {
        return $this->pdf->renderView('pdf/payslip', $this->data($itemId) + ['version' => $version], 'A4', 'portrait', 'Computer-generated payslip — no signature required');
    }

    /** Generate and store the next payslip version. Returns stored_files.id. */
    public function generate(int $itemId): int
    {
        $version = (int) ($this->db->table('payslips')->selectMax('version', 'v')->where('payroll_item_id', $itemId)->get()->getRow()->v ?? 0) + 1;
        $d       = $this->data($itemId);
        $bytes   = $this->render($itemId, $version);
        $name    = 'Payslip_' . $d['emp']['employee_code'] . '_' . $d['run']['period'] . ($version > 1 ? '_v' . $version : '') . '.pdf';
        $fileId  = $this->files->storeContents($bytes, $name, 'application/pdf', 'payslip', 'payroll_item', $itemId);
        $this->db->table('payslips')->insert(['payroll_item_id' => $itemId, 'version' => $version, 'stored_file_id' => $fileId, 'generated_by' => service('authz')->currentUserId()]);

        return $fileId;
    }
}

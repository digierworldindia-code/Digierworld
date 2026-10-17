<?php

namespace App\Services\Files;

use App\Services\Security\Authorization;
use CodeIgniter\Database\BaseConnection;

/**
 * Decides whether the current user may download a stored private file.
 * Deny-by-default: unknown categories are never downloadable.
 */
class FileAccessPolicy
{
    public function __construct(private readonly BaseConnection $db, private readonly Authorization $authz)
    {
    }

    /** @param array<string, mixed> $file */
    public function canDownload(array $file): bool
    {
        $uid = $this->authz->currentUserId();
        if ($uid === null) {
            return false;
        }
        $id = (int) ($file['entity_id'] ?? 0);

        return match ($file['category']) {
            'logo'          => true,
            'signature', 'stamp', 'sign_stamp', 'letterhead', 'upi_qr' => $this->authz->can('settings.branding.edit'),
            'contact'       => $this->authz->can('contacts.view_all'),
            'expense'       => $this->authz->can('finance.expense.view_all')
                || ($this->authz->can('finance.expense.view_own') && $this->ownsExpense($id, $uid)),
            'bank_import', 'bank_statement' => $this->authz->canAny(['finance.bank.import', 'finance.bank.reconcile']),
            'report_export' => $this->ownsExport((int) $file['id'], $uid),
            'billing_pdf'   => service('billingAccess')->canViewDocumentId($id),
            'jd'            => $this->authz->can('hr.vacancy.view'),
            'cv', 'candidate' => service('candidateAccess')->canViewCandidateId($id),
            'interview'     => $this->authz->can('hr.interview.manage'),
            'employee'      => $this->authz->can('hr.employee.private_docs'),
            'payslip'       => $this->authz->can('hr.payslip.view_all') || $this->ownsPayslipFile((int) $file['id'], $uid),
            default         => false,
        };
    }

    private function ownsExpense(int $expenseId, int $uid): bool
    {
        return $this->db->table('expenses')->where(['id' => $expenseId, 'created_by' => $uid])->countAllResults() > 0;
    }

    private function ownsExport(int $fileId, int $uid): bool
    {
        return $this->db->table('report_exports')->where(['stored_file_id' => $fileId, 'user_id' => $uid])->countAllResults() > 0;
    }

    private function ownsPayslipFile(int $fileId, int $uid): bool
    {
        if (! $this->authz->can('self.payslip.view')) {
            return false;
        }

        return $this->db->table('payslips ps')
            ->join('payroll_items pi', 'pi.id = ps.payroll_item_id')
            ->join('employees e', 'e.id = pi.employee_id')
            ->join('payroll_runs pr', 'pr.id = pi.payroll_run_id')
            ->where('ps.stored_file_id', $fileId)->where('e.user_id', $uid)->where('pr.is_locked', 1)
            ->countAllResults() > 0;
    }
}

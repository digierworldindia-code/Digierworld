<?php

namespace App\Controllers;

/**
 * Global search. Each provider applies the SAME permission + record scope as
 * its module list screen, so search never reveals records (or counts) a user
 * could not otherwise open.
 */
class SearchController extends BaseController
{
    public function index()
    {
        $q = trim((string) $this->request->getGet('q'));

        return $this->render('search/index', [
            'pageTitle'   => 'Search',
            'breadcrumbs' => [['Search']],
            'q'           => $q,
            'results'     => mb_strlen($q) >= 2 ? $this->search($q, 25) : [],
        ]);
    }

    public function suggest()
    {
        $q = trim((string) $this->request->getGet('q'));

        return $this->json(['items' => mb_strlen($q) >= 2 ? $this->search($q, 5) : []]);
    }

    /** @return list<array{type: string, label: string, sub: string, url: string}> */
    private function search(string $q, int $limit): array
    {
        $out  = [];
        $like = mb_substr($q, 0, 60); // Query Builder like() escapes wildcards itself

        if ($this->can('contacts.view_all')) {
            $rows = $this->db->table('contacts')->select('id, name, contact_code, phone, gstin')
                ->where('deleted_at', null)
                ->groupStart()->like('name', $like)->orLike('contact_code', $like)->orLike('phone_normalized', preg_replace('/\D/', '', $q) ?: '~')->orLike('gstin', strtoupper($like))->groupEnd()
                ->limit($limit)->get()->getResultArray();
            foreach ($rows as $r) {
                $out[] = ['type' => 'Client', 'label' => $r['name'], 'sub' => $r['contact_code'], 'url' => site_url('contacts/' . $r['id'])];
            }
        }

        $access = service('billingAccess');
        if ($access->visibleTypes() !== []) {
            $b = $this->db->table('billing_documents d')->select('d.id, d.doc_type, d.document_number, d.draft_ref, d.bill_to_name, d.grand_total, d.status');
            $access->applyScope($b, 'd');
            $rows = $b->groupStart()->like('d.document_number', $like)->orLike('d.draft_ref', $like)->orLike('d.bill_to_name', $like)->groupEnd()
                ->orderBy('d.id', 'DESC')->limit($limit)->get()->getResultArray();
            foreach ($rows as $r) {
                $out[] = ['type' => doc_type_label($r['doc_type']), 'label' => $r['document_number'] ?? $r['draft_ref'], 'sub' => $r['bill_to_name'] . ' · ' . ucfirst($r['status']), 'url' => site_url('billing/documents/' . $r['id'])];
            }
        }

        if ($this->can('finance.receipt.view')) {
            $rows = $this->db->table('payment_receipts r')->select('r.id, r.receipt_number, r.amount, c.name')
                ->join('contacts c', 'c.id = r.contact_id')
                ->groupStart()->like('r.receipt_number', $like)->orLike('r.reference_no', $like)->groupEnd()
                ->limit($limit)->get()->getResultArray();
            foreach ($rows as $r) {
                $out[] = ['type' => 'Receipt', 'label' => $r['receipt_number'], 'sub' => $r['name'], 'url' => site_url('finance/receipts/' . $r['id'])];
            }
        }

        $leadAccess = service('leadAccess');
        if ($leadAccess->scope()['mode'] !== 'none') {
            $b = $this->db->table('leads l')->select('l.id, l.lead_code, l.company_name, l.city')->where('l.deleted_at', null);
            $leadAccess->applyScope($b, 'l');
            $rows = $b->groupStart()->like('l.company_name', $like)->orLike('l.lead_code', $like)->orLike('l.mobile_normalized', preg_replace('/\D/', '', $q) ?: '~')->groupEnd()
                ->limit($limit)->get()->getResultArray();
            foreach ($rows as $r) {
                $out[] = ['type' => 'Lead', 'label' => $r['company_name'], 'sub' => $r['lead_code'] . ($r['city'] ? ' · ' . $r['city'] : ''), 'url' => site_url('crm/leads/' . $r['id'])];
            }
        }

        $candAccess = service('candidateAccess');
        if ($candAccess->mode() !== 'none') {
            $b = $this->db->table('candidates c')->select('c.id, c.full_name, c.candidate_code, c.status')->where('c.deleted_at', null);
            $candAccess->applyScope($b, 'c');
            $rows = $b->groupStart()->like('c.full_name', $like)->orLike('c.candidate_code', $like)->orLike('c.email', $like)->groupEnd()
                ->limit($limit)->get()->getResultArray();
            foreach ($rows as $r) {
                $out[] = ['type' => 'Applicant', 'label' => $r['full_name'], 'sub' => $r['candidate_code'], 'url' => site_url('hr/candidates/' . $r['id'])];
            }
        }

        if ($this->can(['hr.employee.view_basic', 'hr.employee.view_all'])) {
            $rows = $this->db->table('employees')->select('id, full_name, employee_code')
                ->groupStart()->like('full_name', $like)->orLike('employee_code', $like)->groupEnd()
                ->limit($limit)->get()->getResultArray();
            foreach ($rows as $r) {
                $out[] = ['type' => 'Employee', 'label' => $r['full_name'], 'sub' => $r['employee_code'], 'url' => site_url('hr/employees/' . $r['id'])];
            }
        }

        if ($this->can('finance.expense.view_all')) {
            $rows = $this->db->table('expenses')->select('id, expense_number, description')->where('deleted_at', null)
                ->groupStart()->like('expense_number', $like)->orLike('description', $like)->orLike('vendor_invoice_no', $like)->groupEnd()
                ->limit($limit)->get()->getResultArray();
            foreach ($rows as $r) {
                $out[] = ['type' => 'Expense', 'label' => $r['expense_number'], 'sub' => mb_strimwidth($r['description'], 0, 50, '…'), 'url' => site_url('finance/expenses/' . $r['id'])];
            }
        }

        return $out;
    }
}

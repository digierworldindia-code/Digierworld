<?php

namespace App\Controllers\Billing;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Services\Billing\RecurringBillingService;

/** Recurring retainers: profiles that generate drafts for review (never auto-finalized). */
class RecurringController extends BaseController
{
    private function svc(): RecurringBillingService
    {
        return new RecurringBillingService($this->db);
    }

    public function index()
    {
        $rows = $this->db->table('recurring_billing_profiles p')
            ->select('p.*, c.name AS client, d.draft_ref, d.document_number, d.status AS last_status, (SELECT COALESCE(SUM(l.qty * l.unit_rate),0) FROM recurring_billing_lines l WHERE l.profile_id = p.id) AS amount', false)
            ->join('contacts c', 'c.id = p.contact_id')->join('billing_documents d', 'd.id = p.last_generated_document_id', 'left')
            ->orderBy('p.is_active', 'DESC')->orderBy('p.next_run_date')->get()->getResultArray();

        return $this->render('billing/recurring/index', ['pageTitle' => 'Recurring retainers', 'breadcrumbs' => [['Billing'], ['Recurring retainers']], 'rows' => $rows]);
    }

    private function form(?array $p)
    {
        return $this->render('billing/recurring/form', [
            'pageTitle' => $p ? $p['name'] : 'New retainer', 'breadcrumbs' => [['Billing'], ['Recurring retainers', 'billing/recurring'], [$p ? $p['name'] : 'New']],
            'p' => $p,
            'lines' => $p ? $this->db->table('recurring_billing_lines')->where('profile_id', $p['id'])->orderBy('line_no')->get()->getResultArray() : [],
            'client' => $p ? $this->db->table('contacts')->select('id, name, state_code')->where('id', $p['contact_id'])->get()->getRowArray() : null,
            'clients' => $this->db->table('contacts')->select('id, name')->where(['is_customer' => 1, 'is_active' => 1, 'deleted_at' => null])->orderBy('name')->get()->getResultArray(),
            'services' => $this->db->table('services s')->select('s.id, s.name, s.sac_code, s.unit, s.default_rate, t.rate AS gst_rate')->join('tax_rates t', 't.id = s.default_tax_rate_id', 'left')
                ->where('s.is_active', 1)->orderBy('s.name')->get()->getResultArray(),
            'states' => $this->db->table('states')->orderBy('name')->get()->getResultArray(),
            'documents' => $p ? $this->db->table('billing_documents')->select('id, draft_ref, document_number, status, service_period_from, service_period_to, grand_total')
                ->where('recurring_profile_id', $p['id'])->orderBy('id', 'DESC')->get()->getResultArray() : [],
        ]);
    }

    public function new()
    {
        return $this->form(null);
    }

    public function create()
    {
        try {
            $id = $this->svc()->save($this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to(site_url('billing/recurring/' . $id))->with('success', 'Retainer saved. Drafts are generated on the due date for you to review and finalize.');
    }

    public function edit($id)
    {
        $p = $this->db->table('recurring_billing_profiles')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();

        return $this->form($p);
    }

    public function update($id)
    {
        $id = $this->idOr404($id);
        $this->db->table('recurring_billing_profiles')->where('id', $id)->countAllResults() > 0 || $this->notFound();

        try {
            $this->svc()->save($this->request->getPost(), $id);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to(site_url('billing/recurring/' . $id))->with('success', 'Retainer updated.');
    }

    public function generate($id)
    {
        $id = $this->idOr404($id);

        try {
            $doc = $this->svc()->generate($id);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('billing/documents/' . $doc))->with('success', 'Draft generated from the retainer. Review it and finalize when ready.');
    }
}

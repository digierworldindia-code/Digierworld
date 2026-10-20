<?php

namespace App\Controllers\Crm;

use App\Exceptions\BusinessRuleException;
use App\Services\Crm\LeadService;

/** Leads: list, create/edit, timeline with rapid call log, stage/assign/DND, follow-ups, conversion. */
class LeadsController extends CrmBase
{
    /** @return array<string, mixed> */
    private function filters(): array
    {
        $g = $this->request->getGet();

        return [
            'q' => trim((string) ($g['q'] ?? '')), 'stage' => (int) ($g['stage'] ?? 0), 'source' => (int) ($g['source'] ?? 0),
            'priority' => in_array($g['priority'] ?? '', ['low', 'medium', 'high', 'hot'], true) ? $g['priority'] : '',
            'assigned' => (int) ($g['assigned'] ?? 0), 'city' => trim((string) ($g['city'] ?? '')), 'industry' => trim((string) ($g['industry'] ?? '')),
            'campaign' => trim((string) ($g['campaign'] ?? '')), 'followup' => in_array($g['followup'] ?? '', ['overdue', 'today', 'none'], true) ? $g['followup'] : '',
            'open' => ($g['open'] ?? '1') === '1',
        ];
    }

    private function query(array $f)
    {
        $b = $this->db->table('leads l')
            ->select('l.id, l.lead_code, l.company_name, l.contact_person, l.mobile, l.city, l.industry, l.priority, l.next_followup_at, l.last_action, l.last_action_at, l.call_attempts, l.connected_calls, l.dnd, l.expected_value, l.assigned_to, s.name AS stage_name, s.code AS stage_code, s.is_won, s.is_lost, s.is_dnd, s.is_closed, src.name AS source_name, p.full_name AS assignee')
            ->join('crm_stages s', 's.id = l.stage_id', 'left')->join('lead_sources src', 'src.id = l.source_id', 'left')->join('user_profiles p', 'p.user_id = l.assigned_to', 'left')
            ->where('l.deleted_at', null);
        service('leadAccess')->applyScope($b, 'l');
        if ($f['q'] !== '') {
            $digits = preg_replace('/\D/', '', $f['q']);
            $b->groupStart()->like('l.company_name', $f['q'])->orLike('l.contact_person', $f['q'])->orLike('l.lead_code', $f['q']);
            if (strlen((string) $digits) >= 4) {
                $b->orLike('l.mobile_normalized', $digits);
            }
            $b->groupEnd();
        }
        foreach (['stage' => 'l.stage_id', 'source' => 'l.source_id', 'assigned' => 'l.assigned_to'] as $k => $col) {
            if ($f[$k] > 0) {
                $b->where($col, $f[$k]);
            }
        }
        foreach (['priority' => 'l.priority', 'city' => 'l.city', 'industry' => 'l.industry', 'campaign' => 'l.campaign'] as $k => $col) {
            if ($f[$k] !== '') {
                $b->where($col, $f[$k]);
            }
        }
        match ($f['followup']) {
            'overdue' => $b->where('l.next_followup_at <', date('Y-m-d H:i:s')),
            'today'   => $b->where('l.next_followup_at >=', date('Y-m-d 00:00:00'))->where('l.next_followup_at <=', date('Y-m-d 23:59:59')),
            'none'    => $b->where('l.next_followup_at', null),
            default   => null,
        };
        if ($f['open'] && $f['stage'] === 0) {
            $b->where('(s.is_closed = 0 OR s.is_closed IS NULL)', null, false);
        }

        return $b;
    }

    public function index()
    {
        $f = $this->filters();
        [$page, $perPage, $offset] = $this->paging(25);

        return $this->render('crm/leads/index', [
            'pageTitle' => 'Leads', 'breadcrumbs' => [['CRM'], ['Leads']],
            'rows' => $this->query($f)->orderBy('l.next_followup_at IS NULL', '', false)->orderBy('l.next_followup_at')->orderBy('l.id', 'DESC')->limit($perPage, $offset)->get()->getResultArray(),
            'total' => $this->query($f)->countAllResults(), 'page' => $page, 'perPage' => $perPage, 'f' => $f,
            'stages' => $this->stages(), 'sources' => $this->db->table('lead_sources')->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
            'users' => $this->scopeUsers(), 'scopeMode' => service('leadAccess')->scope()['mode'],
        ]);
    }

    private function form(?array $lead)
    {
        return $this->render('crm/leads/form', [
            'pageTitle' => $lead ? 'Edit ' . $lead['company_name'] : 'New lead', 'breadcrumbs' => [['CRM'], ['Leads', 'crm/leads'], [$lead ? $lead['lead_code'] : 'New']],
            'lead' => $lead, 'sources' => $this->db->table('lead_sources')->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
            'users' => $this->assignableUsers(), 'canAssign' => $this->can('crm.lead.assign'),
            'services' => array_column($this->db->table('services')->select('name')->where('is_active', 1)->orderBy('name')->get()->getResultArray(), 'name'),
        ]);
    }

    public function new()
    {
        return $this->form(null);
    }

    public function create()
    {
        try {
            $id = service('leads')->create($this->request->getPost(), (bool) $this->request->getPost('allow_duplicate'));
        } catch (BusinessRuleException $e) {
            return $this->ruleError($e);
        }

        return redirect()->to(site_url('crm/leads/' . $id))->with('success', 'Lead saved.');
    }

    public function edit($id)
    {
        return $this->form($this->lead($this->idOr404($id)));
    }

    public function update($id)
    {
        $lead = $this->lead($this->idOr404($id));

        try {
            service('leads')->update((int) $lead['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->ruleError($e);
        }

        return redirect()->to(site_url('crm/leads/' . $lead['id']))->with('success', 'Lead updated.');
    }

    public function show($id)
    {
        $lead = $this->lead($this->idOr404($id));
        $l = $this->db->table('leads l')->select('l.*, s.name AS stage_name, s.code AS stage_code, s.is_won AS stage_won, s.is_lost AS stage_lost, s.is_dnd AS stage_dnd, src.name AS source_name, p.full_name AS assignee, lr.name AS lost_reason, c.name AS client_name')
            ->join('crm_stages s', 's.id = l.stage_id', 'left')->join('lead_sources src', 'src.id = l.source_id', 'left')->join('user_profiles p', 'p.user_id = l.assigned_to', 'left')
            ->join('lost_reasons lr', 'lr.id = l.lost_reason_id', 'left')->join('contacts c', 'c.id = l.contact_id', 'left')->where('l.id', $lead['id'])->get()->getRowArray();

        return $this->render('crm/leads/show', [
            'pageTitle' => $l['company_name'], 'breadcrumbs' => [['CRM'], ['Leads', 'crm/leads'], [$l['lead_code']]],
            'l' => $l,
            'timeline' => $this->db->table('lead_activities a')->select('a.*, p.full_name')->join('user_profiles p', 'p.user_id = a.actor_id', 'left')
                ->where('a.lead_id', $l['id'])->orderBy('a.occurred_at', 'DESC')->orderBy('a.id', 'DESC')->limit(200)->get()->getResultArray(),
            'followups' => $this->db->table('followups f')->select('f.*, p.full_name AS owner')->join('user_profiles p', 'p.user_id = f.owner_id', 'left')
                ->where(['f.lead_id' => $l['id'], 'f.status' => 'pending'])->orderBy('f.due_at')->get()->getResultArray(),
            'visits' => $this->db->table('visits v')->select('v.*, p.full_name AS visitor')->join('user_profiles p', 'p.user_id = v.visitor_id', 'left')
                ->where('v.lead_id', $l['id'])->orderBy('v.scheduled_at', 'DESC')->get()->getResultArray(),
            'meetings' => $this->db->table('meetings')->where('lead_id', $l['id'])->orderBy('meeting_at', 'DESC')->get()->getResultArray(),
            'tasks' => $this->db->table('tasks t')->select('t.*, p.full_name AS assignee')->join('user_profiles p', 'p.user_id = t.assigned_to', 'left')
                ->where(['t.related_type' => 'lead', 't.related_id' => $l['id']])->orderBy('t.status')->orderBy('t.due_at')->get()->getResultArray(),
            'conversion' => $this->db->table('lead_conversion_links k')->select('k.*, d.draft_ref, d.document_number, d.doc_type')->join('billing_documents d', 'd.id = k.document_id', 'left')
                ->where('k.lead_id', $l['id'])->get()->getRowArray(),
            'outcomes' => LeadService::OUTCOMES, 'stages' => $this->stages(),
            'lostReasons' => $this->db->table('lost_reasons')->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
            'users' => $this->assignableUsers(),
            'services' => $this->db->table('services')->select('id, name')->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
            'docTypes' => array_filter(['estimate' => 'billing.estimate.create_draft', 'proforma' => 'billing.estimate.create_draft', 'tax_invoice' => 'billing.invoice.create_draft'], fn ($p) => $this->can($p)),
        ]);
    }

    public function call($id)
    {
        $lead = $this->lead($this->idOr404($id));

        try {
            service('leads')->logCall((int) $lead['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->ruleError($e);
        }
        $next = (string) $this->request->getPost('after');
        if ($next === 'next') {
            // "Save & next": jump to the next due lead in My Day
            $n = $this->db->table('leads l')->select('l.id')->where('l.deleted_at', null)->where('l.assigned_to', $this->userId)->where('l.dnd', 0)->where('l.id !=', $lead['id'])
                ->where('l.next_followup_at <=', date('Y-m-d 23:59:59'))->orderBy('l.next_followup_at')->limit(1)->get()->getRow();
            if ($n) {
                return redirect()->to(site_url('crm/leads/' . $n->id))->with('success', 'Call saved. Next lead due.');
            }
        }

        return redirect()->to(site_url('crm/leads/' . $lead['id']))->with('success', 'Call saved to the timeline.');
    }

    public function stage($id)
    {
        $lead = $this->lead($this->idOr404($id));

        try {
            service('leads')->changeStage((int) $lead['id'], (int) $this->request->getPost('stage_id'), ((int) $this->request->getPost('lost_reason_id')) ?: null, trim((string) $this->request->getPost('note')));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Stage updated.');
    }

    public function assign($id)
    {
        $lead = $this->lead($this->idOr404($id));

        try {
            service('leads')->assign((int) $lead['id'], (int) $this->request->getPost('assigned_to'), trim((string) $this->request->getPost('reason')));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Lead reassigned.');
    }

    public function dnd($id)
    {
        $lead = $this->lead($this->idOr404($id));
        service('leads')->markDnd((int) $lead['id'], trim((string) $this->request->getPost('reason')));

        return redirect()->back()->with('success', 'Marked Do-Not-Call. Outbound calls and follow-ups are now blocked for this lead.');
    }

    public function archive($id)
    {
        $lead = $this->lead($this->idOr404($id));
        service('leads')->archive((int) $lead['id'], trim((string) $this->request->getPost('reason')));

        return redirect()->to(site_url('crm/leads'))->with('success', 'Lead archived.');
    }

    public function followup($id)
    {
        $lead = $this->lead($this->idOr404($id));

        try {
            service('leads')->createFollowup((int) $lead['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Follow-up scheduled.');
    }

    public function convert($id)
    {
        $lead = $this->lead($this->idOr404($id));

        try {
            $res = service('leads')->convert((int) $lead['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        $msg = 'Converted to client.' . ($res['document_id'] ? ' A draft was created for review.' : '') . ' Any incentive is proposed later from invoices/collections and needs HR approval.';

        return redirect()->to(site_url($res['document_id'] ? 'billing/documents/' . $res['document_id'] : 'crm/leads/' . $lead['id']))->with('success', $msg);
    }

    /** JSON lookup for lead pickers (visits, tasks, meetings). */
    public function lookup()
    {
        $q = trim((string) $this->request->getGet('q'));
        $b = $this->db->table('leads l')->select('l.id, l.lead_code, l.company_name, l.city, l.mobile, l.address')->where('l.deleted_at', null);
        service('leadAccess')->applyScope($b, 'l');
        if ($q !== '') {
            $d = preg_replace('/\D/', '', $q);
            $b->groupStart()->like('l.company_name', $q)->orLike('l.lead_code', $q);
            if (strlen((string) $d) >= 4) {
                $b->orLike('l.mobile_normalized', $d);
            }
            $b->groupEnd();
        }

        return $this->json(['items' => $b->orderBy('l.company_name')->limit(15)->get()->getResultArray()]);
    }
}

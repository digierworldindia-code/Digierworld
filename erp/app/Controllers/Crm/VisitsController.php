<?php

namespace App\Controllers\Crm;

use App\Exceptions\BusinessRuleException;

/** Field / owner visits: schedule, own timeline, outcome and client feedback. */
class VisitsController extends CrmBase
{
    private function query()
    {
        $b = $this->db->table('visits v')->select('v.*, l.company_name, l.lead_code, l.assigned_to, c.name AS client_name, p.full_name AS visitor')
            ->join('leads l', 'l.id = v.lead_id', 'left')->join('contacts c', 'c.id = v.contact_id', 'left')->join('user_profiles p', 'p.user_id = v.visitor_id', 'left');
        $scope = service('leadAccess')->scope();
        if ($scope['mode'] !== 'all' && ! $this->can('crm.lead.view_all')) {
            $ids = $scope['user_ids'] ?: [(int) $this->userId];
            $b->groupStart()->whereIn('v.visitor_id', $ids)->orWhereIn('l.assigned_to', $ids)->orWhereIn('v.created_by', $ids)->groupEnd();
        }

        return $b;
    }

    public function index()
    {
        $tab = $this->request->getGet('tab') === 'past' ? 'past' : 'upcoming';
        $b = $this->query();
        $tab === 'past' ? $b->groupStart()->whereIn('v.status', ['completed', 'cancelled'])->orWhere('v.scheduled_at <', date('Y-m-d 00:00:00'))->groupEnd()->orderBy('v.scheduled_at', 'DESC')
            : $b->whereIn('v.status', ['scheduled', 'rescheduled'])->orderBy('v.scheduled_at');

        return $this->render('crm/visits/index', ['pageTitle' => 'Visits', 'breadcrumbs' => [['CRM'], ['Visits']], 'tab' => $tab, 'rows' => $b->limit(200)->get()->getResultArray()]);
    }

    public function new()
    {
        $lead = ($lid = (int) $this->request->getGet('lead')) ? $this->lead($lid) : null;

        return $this->render('crm/visits/form', [
            'pageTitle' => 'Schedule visit', 'breadcrumbs' => [['CRM'], ['Visits', 'crm/visits'], ['Schedule']],
            'lead' => $lead, 'users' => $this->can('crm.visit.assign') ? $this->assignableUsers() : [['id' => (int) $this->userId, 'name' => current_user_name()]],
        ]);
    }

    public function create()
    {
        $in = $this->request->getPost();
        if (! empty($in['lead_id'])) {
            $this->lead((int) $in['lead_id']);
        }

        try {
            $id = service('leads')->scheduleVisit($in);
        } catch (BusinessRuleException $e) {
            return $this->ruleError($e);
        }

        return redirect()->to(site_url('crm/visits/' . $id))->with('success', 'Visit scheduled. The visitor gets a reminder an hour before.');
    }

    public function show($id)
    {
        $v = $this->query()->where('v.id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $timeline = [['at' => $v['created_at'], 'text' => 'Visit scheduled for ' . dmy($v['scheduled_at'], true), 'who' => $this->db->table('user_profiles')->select('full_name')->where('user_id', $v['created_by'])->get()->getRow()->full_name ?? '']];
        foreach ($this->db->table('task_reminders')->where(['entity_type' => 'visit', 'entity_id' => $v['id']])->where('sent_at IS NOT NULL', null, false)->get()->getResultArray() as $r) {
            $timeline[] = ['at' => $r['sent_at'], 'text' => 'Reminder sent', 'who' => ''];
        }
        if ($v['lead_id']) {
            foreach ($this->db->table('lead_activities a')->select('a.*, p.full_name')->join('user_profiles p', 'p.user_id = a.actor_id', 'left')->where(['a.ref_type' => 'visit', 'a.ref_id' => $v['id']])->get()->getResultArray() as $a) {
                $timeline[] = ['at' => $a['occurred_at'], 'text' => $a['title'] . ($a['details'] ? ' — ' . $a['details'] : ''), 'who' => (string) $a['full_name']];
            }
        }
        usort($timeline, static fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $this->render('crm/visits/show', [
            'pageTitle' => 'Visit — ' . ($v['company_name'] ?? $v['client_name'] ?? $v['location']), 'breadcrumbs' => [['CRM'], ['Visits', 'crm/visits'], ['#' . $v['id']]],
            'v' => $v, 'timeline' => $timeline,
            'canUpdate' => $this->can('crm.visit.update_outcome') || (int) $v['visitor_id'] === (int) $this->userId,
        ]);
    }

    public function complete($id)
    {
        $v = $this->query()->where('v.id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        if (! $this->can('crm.visit.update_outcome') && (int) $v['visitor_id'] !== (int) $this->userId) {
            $this->deny();
        }

        try {
            service('leads')->completeVisit((int) $v['id'], $this->request->getPost());
        } catch (BusinessRuleException $e) {
            return $this->ruleError($e);
        }

        return redirect()->to(site_url('crm/visits/' . $v['id']))->with('success', 'Visit outcome recorded.');
    }
}

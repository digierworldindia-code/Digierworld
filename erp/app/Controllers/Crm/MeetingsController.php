<?php

namespace App\Controllers\Crm;

use App\Exceptions\BusinessRuleException;

/** Meetings with leads/clients (in person, phone or video). */
class MeetingsController extends CrmBase
{
    public function index()
    {
        $uid = (int) $this->userId;
        $b = $this->db->table('meetings m')->select('m.*, l.company_name, l.lead_code, p.full_name AS organizer')->join('leads l', 'l.id = m.lead_id', 'left')->join('user_profiles p', 'p.user_id = m.organizer_id', 'left');
        if (! $this->can('crm.lead.view_all')) {
            $ids = service('leadAccess')->scope()['user_ids'] ?: [$uid];
            $b->groupStart()->whereIn('m.organizer_id', $ids)->orWhere("JSON_CONTAINS(m.attendee_ids, '" . $uid . "')", null, false)->orWhereIn('l.assigned_to', $ids)->groupEnd();
        }
        $lead = ($lid = (int) $this->request->getGet('lead')) ? $this->lead($lid) : null;

        return $this->render('crm/meetings', [
            'pageTitle' => 'Meetings', 'breadcrumbs' => [['CRM'], ['Meetings']],
            'rows' => $b->orderBy("m.status = 'scheduled'", 'DESC', false)->orderBy('m.meeting_at')->limit(200)->get()->getResultArray(),
            'lead' => $lead, 'users' => $this->assignableUsers(),
        ]);
    }

    public function create()
    {
        $in = $this->request->getPost();
        if (! empty($in['lead_id'])) {
            $this->lead((int) $in['lead_id']);
        }

        try {
            service('leads')->scheduleMeeting($in);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('crm/meetings'))->with('success', 'Meeting scheduled.');
    }

    public function complete($id)
    {
        $m = $this->db->table('meetings')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $attendees = json_decode((string) $m['attendee_ids'], true) ?: [];
        if ((int) $m['organizer_id'] !== (int) $this->userId && ! in_array((int) $this->userId, array_map('intval', $attendees), true) && ! $this->can('crm.lead.view_all')) {
            $this->notFound();
        }

        try {
            service('leads')->completeMeeting((int) $m['id'], (string) $this->request->getPost('status'), trim((string) $this->request->getPost('outcome')));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Meeting updated.');
    }
}

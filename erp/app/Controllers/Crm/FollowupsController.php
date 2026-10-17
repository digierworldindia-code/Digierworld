<?php

namespace App\Controllers\Crm;

use App\Exceptions\BusinessRuleException;

/** Follow-up board: overdue / today / upcoming / done, within lead scope. */
class FollowupsController extends CrmBase
{
    public function index()
    {
        $tab = in_array($this->request->getGet('tab'), ['overdue', 'today', 'upcoming', 'done'], true) ? $this->request->getGet('tab') : 'overdue';
        $owner = (int) $this->request->getGet('owner');
        $users = $this->scopeUsers();
        $b = fn () => $this->db->table('followups f')->join('leads l', 'l.id = f.lead_id')->where('l.deleted_at', null)
            ->when($owner > 0 && isset($users[$owner]), static fn ($q) => $q->where('f.owner_id', $owner));
        $scoped = function (string $t) use ($b) {
            $q = $b();
            // follow-up owners see their own follow-ups even on leads assigned to someone else
            $scope = service('leadAccess')->scope();
            if ($scope['mode'] !== 'all') {
                $ids = $scope['user_ids'] ?: [0];
                $q->groupStart()->whereIn('l.assigned_to', $ids)->orWhereIn('f.owner_id', $ids)->groupEnd();
            }
            $s = date('Y-m-d 00:00:00');
            $e = date('Y-m-d 23:59:59');
            match ($t) {
                'overdue'  => $q->where('f.status', 'pending')->where('f.due_at <', date('Y-m-d H:i:s')),
                'today'    => $q->where('f.status', 'pending')->where('f.due_at >=', $s)->where('f.due_at <=', $e),
                'upcoming' => $q->where('f.status', 'pending')->where('f.due_at >', $e),
                'done'     => $q->where('f.status', 'done')->where('f.completed_at >=', date('Y-m-d 00:00:00', strtotime('-7 days'))),
            };

            return $q;
        };
        $counts = [];
        foreach (['overdue', 'today', 'upcoming', 'done'] as $t) {
            $counts[$t] = $scoped($t)->countAllResults();
        }

        return $this->render('crm/followups', [
            'pageTitle' => 'Follow-ups', 'breadcrumbs' => [['CRM'], ['Follow-ups']], 'tab' => $tab, 'counts' => $counts, 'owner' => $owner, 'users' => $users,
            'rows' => $scoped($tab)->select('f.*, l.company_name, l.lead_code, l.mobile, l.dnd, p.full_name AS owner_name')->join('user_profiles p', 'p.user_id = f.owner_id', 'left')
                ->orderBy($tab === 'done' ? 'f.completed_at' : 'f.due_at', $tab === 'done' ? 'DESC' : 'ASC')->limit(200)->get()->getResultArray(),
        ]);
    }

    public function complete($id)
    {
        $f = $this->db->table('followups')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $lead = $this->db->table('leads')->where('id', $f['lead_id'])->get()->getRowArray();
        if ($lead === null || (! service('leadAccess')->canView($lead) && (int) $f['owner_id'] !== (int) $this->userId)) {
            $this->notFound();
        }
        $this->authorize('crm.followup.manage');
        $re = trim((string) $this->request->getPost('reschedule_to'));

        try {
            service('leads')->completeFollowup((int) $f['id'], trim((string) $this->request->getPost('outcome')), $re !== '' ? $re : null);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', $re !== '' ? 'Follow-up moved.' : 'Follow-up marked done.');
    }
}

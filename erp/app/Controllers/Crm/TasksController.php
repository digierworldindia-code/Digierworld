<?php

namespace App\Controllers\Crm;

use App\Exceptions\BusinessRuleException;

/** Shared task list/calendar: my tasks, tasks I assigned, all (with permission). */
class TasksController extends CrmBase
{
    public function index()
    {
        $uid  = (int) $this->userId;
        $view = $this->request->getGet('view');
        $view = in_array($view, ['mine', 'assigned', 'all'], true) ? $view : 'mine';
        if ($view === 'all' && ! $this->can('crm.task.view_all')) {
            $view = 'mine';
        }
        $b = $this->db->table('tasks t')->select('t.*, a.full_name AS assignee, b.full_name AS assigner, l.company_name AS lead_name')
            ->join('user_profiles a', 'a.user_id = t.assigned_to', 'left')->join('user_profiles b', 'b.user_id = t.assigned_by', 'left')
            ->join('leads l', "l.id = t.related_id AND t.related_type = 'lead'", 'left');
        match ($view) {
            'mine'     => $b->where('t.assigned_to', $uid),
            'assigned' => $b->where('t.assigned_by', $uid)->where('t.assigned_to !=', $uid),
            default    => null,
        };
        $showDone = $this->request->getGet('done') === '1';
        if (! $showDone) {
            $b->whereIn('t.status', ['pending', 'in_progress']);
        }
        $rows = $b->orderBy('t.due_at IS NULL', '', false)->orderBy('t.due_at')->limit(300)->get()->getResultArray();
        $groups = ['Overdue' => [], 'Today' => [], 'This week' => [], 'Later' => [], 'No due date' => [], 'Completed / cancelled' => []];
        $today = date('Y-m-d');
        $week  = date('Y-m-d', strtotime('+7 days'));
        foreach ($rows as $r) {
            $d = $r['due_at'] ? substr($r['due_at'], 0, 10) : null;
            $g = match (true) {
                in_array($r['status'], ['completed', 'cancelled'], true) => 'Completed / cancelled',
                $d === null => 'No due date',
                $r['due_at'] < date('Y-m-d H:i:s') => 'Overdue',
                $d === $today => 'Today',
                $d <= $week => 'This week',
                default => 'Later',
            };
            $groups[$g][] = $r;
        }
        $lead = ($lid = (int) $this->request->getGet('lead')) ? $this->lead($lid) : null;

        return $this->render('crm/tasks', [
            'pageTitle' => 'Tasks', 'breadcrumbs' => [['CRM'], ['Tasks']], 'view' => $view, 'groups' => $groups, 'showDone' => $showDone, 'lead' => $lead,
            'users' => $this->can('crm.task.assign') ? $this->assignableUsers() : [['id' => $uid, 'name' => current_user_name()]],
        ]);
    }

    public function create()
    {
        $in = $this->request->getPost();
        if (! empty($in['lead_id'])) {
            $this->lead((int) $in['lead_id']);
        }

        try {
            service('leads')->createTask($in);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Task created.');
    }

    public function status($id)
    {
        try {
            service('leads')->updateTaskStatus($this->idOr404($id), (string) $this->request->getPost('status'), trim((string) $this->request->getPost('notes')));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Task updated.');
    }
}

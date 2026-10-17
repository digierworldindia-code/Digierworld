<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
<ul class="nav nav-tabs small">
    <?php foreach (array_filter(['mine' => 'My tasks', 'assigned' => 'Assigned by me', 'all' => can('crm.task.view_all') ? 'All tasks' : null]) as $k => $l): ?>
    <li class="nav-item"><a class="nav-link <?= $view === $k ? 'active' : '' ?>" href="<?= site_url('crm/tasks?view=' . $k . ($showDone ? '&done=1' : '')) ?>"><?= $l ?></a></li>
    <?php endforeach ?>
</ul>
<a class="small align-self-center" href="<?= site_url('crm/tasks?view=' . $view . ($showDone ? '' : '&done=1')) ?>"><?= $showDone ? 'Hide completed' : 'Show completed' ?></a>
</div>
<div class="row g-3">
<div class="col-xl-8">
<?php foreach ($groups as $g => $items): if ($items === []) { continue; } ?>
<div class="table-card mb-3"><div class="card-header bg-white"><strong class="<?= $g === 'Overdue' ? 'text-danger' : '' ?>"><?= $g ?></strong> <span class="text-muted small"><?= count($items) ?></span></div><div class="table-responsive"><table class="table small align-middle mb-0"><tbody>
    <?php foreach ($items as $t): ?>
    <tr><td><span class="fw-semibold"><?= esc($t['title']) ?></span> <?= $t['priority'] === 'high' ? '<span class="badge text-bg-warning">High</span>' : '' ?>
            <?php if ($t['description']): ?><div class="text-muted"><?= esc(mb_strimwidth($t['description'], 0, 140, '…')) ?></div><?php endif ?>
            <div class="text-muted"><?= $view !== 'mine' ? 'For ' . esc((string) $t['assignee']) . ' · ' : '' ?><?= $t['assigned_by'] && (int) $t['assigned_by'] !== (int) $t['assigned_to'] ? 'from ' . esc((string) $t['assigner']) : '' ?><?= $t['lead_name'] ? ' · <a href="' . site_url('crm/leads/' . $t['related_id']) . '">' . esc($t['lead_name']) . '</a>' : '' ?></div>
            <?php if ($t['completion_notes']): ?><div class="small">Note: <?= esc($t['completion_notes']) ?></div><?php endif ?></td>
        <td class="text-nowrap"><?= $t['due_at'] ? dmy($t['due_at'], true) : '—' ?></td><td><?= status_badge($t['due_at'] && $t['due_at'] < date('Y-m-d H:i:s') && in_array($t['status'], ['pending', 'in_progress'], true) ? 'overdue' : $t['status']) ?></td>
        <td class="text-end"><?php if (in_array($t['status'], ['pending', 'in_progress'], true)): ?><form method="post" action="<?= site_url('crm/tasks/' . $t['id'] . '/status') ?>" class="d-flex gap-1"><?= csrf_field() ?>
            <input class="form-control form-control-sm" name="notes" placeholder="Note" aria-label="Note" style="max-width:150px">
            <?php if ($t['status'] === 'pending'): ?><button class="btn btn-sm btn-outline-dark" name="status" value="in_progress">Start</button><?php endif ?>
            <button class="btn btn-sm btn-outline-success" name="status" value="completed">Done</button></form><?php endif ?></td></tr>
    <?php endforeach ?>
</tbody></table></div></div>
<?php endforeach ?>
<?php if (array_sum(array_map('count', $groups)) === 0): ?><div class="empty-state"><i class="bi bi-list-check"></i>No tasks.</div><?php endif ?>
</div>
<div class="col-xl-4" id="new">
<?php if (can('crm.task.create')): ?>
<form method="post" action="<?= site_url('crm/tasks') ?>" class="card"><div class="card-body small">
    <?= csrf_field() ?>
    <div class="card-section-title">New task<?= $lead ? ' for ' . esc($lead['company_name']) : '' ?></div>
    <input type="hidden" name="lead_id" value="<?= $lead ? (int) $lead['id'] : '' ?>">
    <label class="form-label required" for="tk_title">Task</label><input class="form-control mb-2" id="tk_title" name="title" required maxlength="255" placeholder="e.g. Owner to visit — discuss SEO package">
    <label class="form-label" for="tk_desc">Details</label><textarea class="form-control mb-2" id="tk_desc" name="description" rows="2"></textarea>
    <label class="form-label" for="tk_to">Assign to</label><select class="form-select mb-2" id="tk_to" name="assigned_to"><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $u['id'] === (int) current_user_id() ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach ?></select>
    <div class="row g-2 mb-2"><div class="col-7"><label class="form-label" for="tk_due">Due</label><input type="datetime-local" class="form-control" id="tk_due" name="due_at"></div>
        <div class="col-5"><label class="form-label" for="tk_pr">Priority</label><select class="form-select" id="tk_pr" name="priority"><option value="medium">Medium</option><option value="high">High</option><option value="low">Low</option></select></div></div>
    <button class="btn btn-dark w-100" type="submit">Create task</button>
    <div class="form-text">Tasks for others notify them. Telecallers can assign visits/tasks to the owner or sales team without seeing accounts or salaries.</div>
</div></form>
<?php endif ?>
</div>
</div>
<?= $this->endSection() ?>

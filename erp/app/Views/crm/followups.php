<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
<ul class="nav nav-tabs small">
<?php foreach (['overdue' => 'Overdue', 'today' => 'Today', 'upcoming' => 'Upcoming', 'done' => 'Done (7 days)'] as $k => $l): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= site_url('crm/followups?' . http_build_query(['tab' => $k, 'owner' => $owner ?: null])) ?>"><?= $l ?> <span class="badge <?= $k === 'overdue' && $counts[$k] ? 'text-bg-danger' : 'text-bg-light border' ?>"><?= (int) $counts[$k] ?></span></a></li>
<?php endforeach ?>
</ul>
<?php if (count($users) > 1): ?><form method="get"><input type="hidden" name="tab" value="<?= esc($tab) ?>"><select class="form-select form-select-sm" name="owner" data-autosubmit aria-label="Owner"><option value="">Everyone in my scope</option><?php foreach ($users as $id => $n): ?><option value="<?= $id ?>" <?= $owner === (int) $id ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select></form><?php endif ?>
</div>
<div class="table-card"><div class="table-responsive"><table class="table small align-middle">
    <thead><tr><th>Lead</th><th>Follow-up</th><th><?= $tab === 'done' ? 'Completed' : 'Due' ?></th><th>Owner</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): $over = $r['status'] === 'pending' && $r['due_at'] < date('Y-m-d H:i:s'); ?>
    <tr><td><a class="fw-semibold" href="<?= site_url('crm/leads/' . $r['lead_id']) ?>"><?= esc($r['company_name']) ?></a><div class="mono text-muted"><?= esc($r['lead_code']) ?></div></td>
        <td><?= esc($r['title']) ?><?= $r['outcome_notes'] ? '<div class="text-muted">' . esc($r['outcome_notes']) . '</div>' : '' ?></td>
        <td class="text-nowrap <?= $over ? 'text-danger fw-semibold' : '' ?>"><?= dmy($tab === 'done' ? $r['completed_at'] : $r['due_at'], true) ?></td>
        <td class="text-muted"><?= esc((string) $r['owner_name']) ?></td>
        <td class="text-end text-nowrap"><?php if ($r['status'] === 'pending'): ?>
            <?php if ((int) $r['dnd'] !== 1): ?><a class="btn btn-sm btn-dark" href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $r['mobile'])) ?>" aria-label="Call"><i class="bi bi-telephone"></i></a><?php endif ?>
            <a class="btn btn-sm btn-outline-dark" href="<?= site_url('crm/leads/' . $r['lead_id']) ?>">Log call</a>
            <form method="post" action="<?= site_url('crm/followups/' . $r['id'] . '/complete') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success" title="Mark done without a call"><i class="bi bi-check2"></i></button></form>
        <?php endif ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-alarm"></i><?= $tab === 'overdue' ? 'Nothing overdue. Well done.' : 'Nothing here.' ?></div></td></tr><?php endif ?>
</tbody></table></div></div>
<?= $this->endSection() ?>

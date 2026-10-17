<?= $this->extend('layouts/app') ?>
<?php
$pct = static fn (int $a, ?int $t) => $t ? min(100, (int) round($a * 100 / max(1, $t))) : null;
$leadLink = static fn (array $r, string $label) => '<a class="fw-semibold" href="' . site_url('crm/leads/' . ($r['lead_id'] ?? $r['id'])) . '">' . esc($label) . '</a>';
?>
<?= $this->section('pageActions') ?>
<?php if (count($users) > 1): ?>
<form method="get" class="d-inline-block"><select class="form-select form-select-sm" name="user" data-autosubmit aria-label="Show day for"><?php foreach ($users as $id => $n): ?><option value="<?= $id ?>" <?= (int) $id === $uid ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select></form>
<?php endif ?>
<?php if (can('crm.lead.create')): ?><a class="btn btn-gold btn-sm" href="<?= site_url('crm/leads/new') ?>"><i class="bi bi-plus-lg"></i> New lead</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <?php foreach ([['Calls today', $actual['calls'], $target['calls_per_day'] ?? null], ['Connected', $actual['connected'], $target['connected_per_day'] ?? null], ['Follow-ups done', $actual['followups'], $target['followups_per_day'] ?? null]] as [$l, $a, $t]): $p = $pct($a, $t !== null ? (int) $t : null); ?>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label"><?= $l ?></div><div class="kpi-value"><?= (int) $a ?><?= $t ? '<span class="fs-6 text-muted"> / ' . (int) $t . '</span>' : '' ?></div>
        <?php if ($p !== null): ?><div class="progress mt-1" style="height:6px" role="progressbar" aria-valuenow="<?= $p ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= $l ?> vs target"><div class="progress-bar bg-dark" style="width:<?= $p ?>%"></div></div><?php endif ?></div></div>
    <?php endforeach ?>
    <div class="col-6 col-xl-3"><div class="kpi-card <?= $overdue ? 'border-danger' : '' ?>"><div class="kpi-label">Overdue follow-ups</div><div class="kpi-value <?= $overdue ? 'text-danger' : '' ?>"><?= count($overdue) ?></div></div></div>
</div>
<div class="row g-3">
<div class="col-xl-7">
    <div class="table-card mb-3 <?= $overdue ? 'border-danger' : '' ?>"><div class="card-header bg-white"><strong class="<?= $overdue ? 'text-danger' : '' ?>">1. Overdue follow-ups</strong></div><div class="table-responsive"><table class="table small align-middle mb-0"><tbody>
        <?php foreach ($overdue as $r): ?><tr><td><?= $leadLink($r, $r['company_name']) ?><div class="text-muted"><?= esc($r['title']) ?> · <?= esc((string) $r['stage_name']) ?></div></td><td class="text-danger text-nowrap"><?= dmy($r['due_at'], true) ?></td><td class="text-end"><?php if ((int) $r['dnd'] !== 1): ?><a class="btn btn-sm btn-dark" href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $r['mobile'])) ?>"><i class="bi bi-telephone"></i></a><?php endif ?> <a class="btn btn-sm btn-outline-dark" href="<?= site_url('crm/leads/' . $r['lead_id']) ?>">Log</a></td></tr><?php endforeach ?>
        <?php if ($overdue === []): ?><tr><td class="text-muted">Nothing overdue.</td></tr><?php endif ?>
    </tbody></table></div></div>
    <div class="table-card mb-3"><div class="card-header bg-white"><strong>2. Today's calls</strong> <span class="text-muted small">(follow-ups due today)</span></div><div class="table-responsive"><table class="table small align-middle mb-0"><tbody>
        <?php foreach ($dueToday as $r): ?><tr><td><?= $leadLink($r, $r['company_name']) ?><?= $r['priority'] === 'hot' ? ' <span class="badge text-bg-danger">Hot</span>' : '' ?><div class="text-muted"><?= esc($r['title']) ?></div></td><td class="text-nowrap <?= $r['due_at'] < $now ? 'text-danger' : '' ?>"><?= date('h:i A', strtotime($r['due_at'])) ?></td><td class="text-end"><?php if ((int) $r['dnd'] !== 1): ?><a class="btn btn-sm btn-dark" href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $r['mobile'])) ?>"><i class="bi bi-telephone"></i></a><?php endif ?> <a class="btn btn-sm btn-outline-dark" href="<?= site_url('crm/leads/' . $r['lead_id']) ?>">Log</a></td></tr><?php endforeach ?>
        <?php if ($dueToday === []): ?><tr><td class="text-muted">No follow-ups due today.</td></tr><?php endif ?>
    </tbody></table></div></div>
    <div class="table-card mb-3"><div class="card-header bg-white"><strong>3. New leads</strong> <span class="text-muted small">(not yet called)</span></div><div class="table-responsive"><table class="table small align-middle mb-0"><tbody>
        <?php foreach ($newLeads as $r): ?><tr><td><?= $leadLink($r, $r['company_name']) ?><?= $r['priority'] === 'hot' ? ' <span class="badge text-bg-danger">Hot</span>' : '' ?><div class="text-muted"><?= esc((string) $r['city']) ?> · added <?= dmy($r['created_at']) ?></div></td><td class="text-end"><a class="btn btn-sm btn-dark" href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $r['mobile'])) ?>"><i class="bi bi-telephone"></i></a> <a class="btn btn-sm btn-outline-dark" href="<?= site_url('crm/leads/' . $r['id']) ?>">Log</a></td></tr><?php endforeach ?>
        <?php if ($newLeads === []): ?><tr><td class="text-muted">No uncalled leads.</td></tr><?php endif ?>
    </tbody></table></div></div>
</div>
<div class="col-xl-5">
    <div class="table-card mb-3"><div class="card-header bg-white"><strong>4. Meetings &amp; visits today</strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($visits as $v): ?><tr><td><i class="bi bi-geo-alt"></i> <a href="<?= site_url('crm/visits/' . $v['id']) ?>"><?= esc($v['company_name'] ?? $v['location']) ?></a><div class="text-muted"><?= esc((string) $v['purpose']) ?></div></td><td class="text-nowrap"><?= date('h:i A', strtotime($v['scheduled_at'])) ?> <?= status_badge($v['status']) ?></td></tr><?php endforeach ?>
        <?php foreach ($meetings as $m): ?><tr><td><i class="bi bi-calendar-event"></i> <?= esc($m['title']) ?><div class="text-muted"><?= esc((string) $m['company_name']) ?></div></td><td class="text-nowrap"><?= date('h:i A', strtotime($m['meeting_at'])) ?> <?= status_badge($m['status']) ?></td></tr><?php endforeach ?>
        <?php if ($visits === [] && $meetings === []): ?><tr><td class="text-muted">Nothing scheduled.</td></tr><?php endif ?>
    </tbody></table></div>
    <div class="table-card mb-3"><div class="card-header bg-white d-flex justify-content-between"><strong>Tasks</strong><a class="small" href="<?= site_url('crm/tasks') ?>">All tasks</a></div><table class="table small mb-0"><tbody>
        <?php foreach ($tasks as $t): ?><tr><td><?= esc($t['title']) ?></td><td class="text-nowrap <?= $t['due_at'] && $t['due_at'] < $now ? 'text-danger' : '' ?>"><?= $t['due_at'] ? dmy($t['due_at'], true) : '—' ?></td></tr><?php endforeach ?>
        <?php if ($tasks === []): ?><tr><td class="text-muted">No open tasks due.</td></tr><?php endif ?>
    </tbody></table></div>
    <div class="table-card"><div class="card-header bg-white"><strong>5. Completed today</strong> <span class="text-muted small"><?= count($done) ?> call(s)</span></div><table class="table small mb-0"><tbody>
        <?php foreach (array_slice($done, 0, 30) as $c): ?><tr><td><a href="<?= site_url('crm/leads/' . $c['lead_id']) ?>"><?= esc($c['company_name']) ?></a><div class="text-muted"><?= esc($outcomes[$c['outcome']] ?? $c['outcome']) ?></div></td><td class="text-nowrap"><?= date('h:i A', strtotime($c['called_at'])) ?></td></tr><?php endforeach ?>
        <?php if ($done === []): ?><tr><td class="text-muted">No calls logged yet today.</td></tr><?php endif ?>
    </tbody></table></div>
</div>
</div>
<?= $this->endSection() ?>

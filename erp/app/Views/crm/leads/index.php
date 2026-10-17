<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('crm.export')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('crm/export?' . http_build_query(array_filter($f))) ?>"><i class="bi bi-file-earmark-excel"></i> Export</a><?php endif ?>
<?php if (can('crm.lead.import')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('crm/leads/import') ?>"><i class="bi bi-upload"></i> Import</a><?php endif ?>
<?php if (can('crm.lead.create')): ?><a class="btn btn-gold btn-sm" href="<?= site_url('crm/leads/new') ?>"><i class="bi bi-plus-lg"></i> New lead</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-3"><input type="search" name="q" value="<?= esc($f['q']) ?>" class="form-control form-control-sm" placeholder="Name, mobile, lead code" aria-label="Search"></div>
    <div class="col-6 col-md-2"><select name="stage" class="form-select form-select-sm" data-autosubmit aria-label="Stage"><option value="">Open stages</option><?php foreach ($stages as $s): ?><option value="<?= $s['id'] ?>" <?= $f['stage'] === (int) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><select name="followup" class="form-select form-select-sm" data-autosubmit aria-label="Follow-up"><option value="">Any follow-up</option><option value="overdue" <?= $f['followup'] === 'overdue' ? 'selected' : '' ?>>Overdue</option><option value="today" <?= $f['followup'] === 'today' ? 'selected' : '' ?>>Due today</option><option value="none" <?= $f['followup'] === 'none' ? 'selected' : '' ?>>No follow-up set</option></select></div>
    <div class="col-6 col-md-2"><select name="priority" class="form-select form-select-sm" data-autosubmit aria-label="Priority"><option value="">Any priority</option><?php foreach (['hot', 'high', 'medium', 'low'] as $p): ?><option value="<?= $p ?>" <?= $f['priority'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-3"><select name="source" class="form-select form-select-sm" data-autosubmit aria-label="Source"><option value="">Any source</option><?php foreach ($sources as $s): ?><option value="<?= $s['id'] ?>" <?= $f['source'] === (int) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?></select></div>
    <?php if ($scopeMode !== 'assigned'): ?>
    <div class="col-6 col-md-3"><select name="assigned" class="form-select form-select-sm" data-autosubmit aria-label="Assigned to"><option value="">Anyone</option><?php foreach ($users as $uid => $n): ?><option value="<?= $uid ?>" <?= $f['assigned'] === (int) $uid ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select></div>
    <?php endif ?>
    <div class="col-6 col-md-2"><input name="city" value="<?= esc($f['city']) ?>" class="form-control form-control-sm" placeholder="City" aria-label="City"></div>
    <div class="col-6 col-md-2"><input name="industry" value="<?= esc($f['industry']) ?>" class="form-control form-control-sm" placeholder="Industry" aria-label="Industry"></div>
    <div class="col-6 col-md-2"><input name="campaign" value="<?= esc($f['campaign']) ?>" class="form-control form-control-sm" placeholder="Campaign" aria-label="Campaign"></div>
    <div class="col-md-2"><button class="btn btn-dark btn-sm w-100">Filter</button></div>
</form>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Lead</th><th>Mobile</th><th>City / industry</th><th>Stage</th><th>Next follow-up</th><th>Last action</th><th class="num">Calls</th><th>Assigned</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $over = $r['next_followup_at'] && $r['next_followup_at'] < date('Y-m-d H:i:s'); ?>
    <tr>
        <td><a class="fw-semibold" href="<?= site_url('crm/leads/' . $r['id']) ?>"><?= esc($r['company_name']) ?></a><?= $r['priority'] === 'hot' ? ' <span class="badge text-bg-danger">Hot</span>' : ($r['priority'] === 'high' ? ' <span class="badge text-bg-warning">High</span>' : '') ?>
            <div class="text-muted"><span class="mono"><?= esc($r['lead_code']) ?></span><?= $r['contact_person'] ? ' · ' . esc($r['contact_person']) : '' ?><?= $r['source_name'] ? ' · ' . esc($r['source_name']) : '' ?></div></td>
        <td class="mono"><?php if ((int) $r['dnd'] === 1): ?><span class="badge text-bg-dark" title="Do not call">DND</span> <?= esc(mask_tail($r['mobile'], 2)) ?><?php else: ?><a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $r['mobile'])) ?>"><?= esc($r['mobile']) ?></a><?php endif ?></td>
        <td><?= esc((string) $r['city']) ?><div class="text-muted"><?= esc((string) $r['industry']) ?></div></td>
        <td><span class="badge" style="background:<?= esc(preg_match('/^#[0-9a-f]{6}$/i', (string) $r['stage_color']) ? $r['stage_color'] : '#6c757d') ?>"><?= esc((string) $r['stage_name']) ?></span></td>
        <td class="<?= $over ? 'text-danger fw-semibold' : '' ?>"><?= $r['next_followup_at'] ? dmy($r['next_followup_at'], true) : '—' ?><?= $over ? '<div class="small">overdue</div>' : '' ?></td>
        <td><?= esc(mb_strimwidth((string) $r['last_action'], 0, 60, '…')) ?><div class="text-muted"><?= $r['last_action_at'] ? dmy($r['last_action_at'], true) : '' ?></div></td>
        <td class="num"><?= (int) $r['call_attempts'] ?><span class="text-muted">/<?= (int) $r['connected_calls'] ?></span></td>
        <td class="text-muted"><?= esc((string) $r['assignee']) ?></td>
    </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-person-lines-fill"></i>No leads match these filters.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<div class="small text-muted mt-1">Calls column: attempts / connected.</div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>

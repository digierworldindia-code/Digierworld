<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (count($users) > 1): ?><form method="get" class="d-inline-block"><select class="form-select form-select-sm" name="user" data-autosubmit aria-label="Filter by person"><option value="">Everyone</option><?php foreach ($users as $id => $n): ?><option value="<?= $id ?>" <?= (int) service('request')->getGet('user') === (int) $id ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select></form><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="kanban">
<?php foreach ($stages as $s): $items = $cards[(int) $s['id']] ?? []; $sum = array_sum(array_map(static fn ($c) => (float) $c['expected_value'], $items)); ?>
    <div class="kanban-col">
        <div class="d-flex justify-content-between align-items-center mb-2"><strong class="small" style="border-left:4px solid <?= esc(preg_match('/^#[0-9a-f]{6}$/i', (string) $s['color']) ? $s['color'] : '#6c757d') ?>;padding-left:6px"><?= esc($s['name']) ?></strong><span class="badge text-bg-light border"><?= count($items) ?></span></div>
        <?php if ($sum > 0): ?><div class="small text-muted mb-2"><?= inr($sum) ?> expected</div><?php endif ?>
        <?php foreach (array_slice($items, 0, 60) as $c): $over = $c['next_followup_at'] && $c['next_followup_at'] < date('Y-m-d H:i:s'); ?>
        <a class="kanban-card d-block text-decoration-none text-reset" href="<?= site_url('crm/leads/' . $c['id']) ?>">
            <div class="fw-semibold small"><?= esc($c['company_name']) ?><?= $c['priority'] === 'hot' ? ' <span class="badge text-bg-danger">Hot</span>' : '' ?></div>
            <div class="small text-muted"><?= esc((string) $c['assignee']) ?><?= $c['expected_value'] ? ' · ' . inr($c['expected_value']) : '' ?></div>
            <?php if ($c['next_followup_at']): ?><div class="small <?= $over ? 'text-danger' : 'text-muted' ?>"><i class="bi bi-alarm"></i> <?= dmy($c['next_followup_at'], true) ?></div><?php endif ?>
        </a>
        <?php endforeach ?>
        <?php if (count($items) > 60): ?><a class="small" href="<?= site_url('crm/leads?stage=' . $s['id']) ?>">+<?= count($items) - 60 ?> more</a><?php endif ?>
    </div>
<?php endforeach ?>
</div>
<p class="small text-muted mt-2">Open a lead to log a call or change its stage. Won is set only by converting the lead to a client.</p>
<?= $this->endSection() ?>

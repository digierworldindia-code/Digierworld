<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('reports.gst.view')): ?><a class="btn btn-primary btn-sm" href="<?= site_url('reports/gst') ?>"><i class="bi bi-percent"></i> GST returns</a><?php endif ?>
<?php if (can('reports.finance.view')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('reports/digest') ?>"><i class="bi bi-printer"></i> Today's digest</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($groups === []): ?><div class="empty-state"><i class="bi bi-bar-chart-line"></i>You do not have access to any reports.</div><?php endif ?>
<?php foreach ($groups as $g => $items): ?>
<h2 class="card-section-title mt-3"><?= esc(['Sales' => 'Sales & billing', 'Money' => 'Money & profitability', 'GST' => 'GST', 'Control' => 'Control & audit'][$g] ?? $g) ?></h2>
<div class="row g-3 mb-2">
    <?php foreach ($items as $code => $r): ?>
    <div class="col-md-6 col-xl-4"><a class="card h-100 text-decoration-none text-reset" href="<?= site_url('reports/' . $code) ?>"><div class="card-body">
        <div class="fw-semibold"><?= esc($r['title']) ?></div><div class="small text-muted"><?= esc($r['desc']) ?></div>
    </div></a></div>
    <?php endforeach ?>
</div>
<?php endforeach ?>
<?= $this->endSection() ?>

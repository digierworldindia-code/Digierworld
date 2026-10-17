<?= $this->extend('layouts/app') ?>
<?php $D = \App\Libraries\Dec::class; $p = $payable; ?>
<?= $this->section('pageActions') ?>
<?php if (can('reports.export')): ?>
<a class="btn btn-gold btn-sm" href="<?= site_url('reports/gst/gstr1?month=' . urlencode($preset)) ?>"><i class="bi bi-file-earmark-excel"></i> GSTR-1 workbook</a>
<a class="btn btn-gold btn-sm" href="<?= site_url('reports/gst/gstr3b?month=' . urlencode($preset)) ?>"><i class="bi bi-file-earmark-excel"></i> GSTR-3B workbook</a>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form method="get" class="filter-bar row g-2">
    <div class="col-md-4"><select class="form-select form-select-sm" name="month" data-autosubmit aria-label="Return period"><?php foreach ($months as [$v, $l]): ?><option value="<?= esc($v) ?>" <?= $preset === $v ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
    <div class="col-md-8 small text-muted align-self-center">Return period <?= dmy($from) ?> – <?= dmy($to) ?>. Figures come from finalized documents and approved expenses only.</div>
</form>
<?php foreach ($warnings as [$lvl, $msg, $link]): ?>
<div class="alert alert-<?= $lvl === 'warning' ? 'warning' : 'light border' ?> small py-2"><?= esc($msg) ?><?= $link ? ' <a href="' . esc($link) . '">Open</a>' : '' ?></div>
<?php endforeach ?>
<div class="row g-3 mb-3">
    <?php foreach (['igst' => 'IGST', 'cgst' => 'CGST', 'sgst' => 'SGST / UTGST'] as $k => $l): ?>
    <div class="col-md-4"><div class="kpi-card">
        <div class="kpi-label"><?= $l ?></div>
        <div class="d-flex justify-content-between small mt-1"><span>Output tax</span><strong><?= inr($p['output'][$k]) ?></strong></div>
        <div class="d-flex justify-content-between small"><span>Eligible ITC</span><span><?= inr($p['itc'][$k]) ?></span></div>
        <div class="d-flex justify-content-between border-top mt-1 pt-1"><span>Cash payable</span><strong class="fs-5"><?= inr($p['cash'][$k]) ?></strong></div>
        <?php if ((float) $p['carry'][$k] > 0): ?><div class="small text-muted">ITC carried forward <?= inr($p['carry'][$k]) ?></div><?php endif ?>
    </div></div>
    <?php endforeach ?>
</div>
<?php if ($p['used'] !== []): ?>
<div class="card mb-3"><div class="card-body small">
    <div class="card-section-title">Indicative ITC set-off</div>
    <?php foreach ($p['used'] as $u): ?><div><?= esc($u['from']) ?> credit used against <?= esc($u['to']) ?> liability: <strong><?= inr($u['amount']) ?></strong></div><?php endforeach ?>
    <div class="text-muted mt-1">Order per Sections 49/49A/49B and Rule 88A. The GST portal computes the final set-off from your electronic ledgers.</div>
</div></div>
<?php endif ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="table-card mb-3"><div class="card-header bg-white d-flex justify-content-between"><strong>Output tax by type &amp; rate</strong><a class="small" href="<?= site_url('reports/gst_output?' . http_build_query(['period' => 'custom', 'from' => $from, 'to' => $to])) ?>">Open report</a></div><div class="table-responsive">
    <table class="table small align-middle mb-0"><thead><tr><th>GST type</th><th>Treatment</th><th class="num">Rate</th><th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST/UTGST</th><th class="num">IGST</th></tr></thead><tbody>
    <?php foreach ($output as $o): ?><tr><td><?= esc($o['gst_type']) ?></td><td><?= esc($o['treatment']) ?></td><td class="num"><?= esc($D::rateLabel($o['rate'])) ?>%</td><td class="num"><?= inr($o['taxable']) ?></td><td class="num"><?= inr($o['cgst']) ?></td><td class="num"><?= inr($D::add($o['sgst'], $o['utgst'])) ?></td><td class="num"><?= inr($o['igst']) ?></td></tr><?php endforeach ?>
    <?php if ($output === []): ?><tr><td colspan="7" class="text-muted">No finalized supplies in this period.</td></tr><?php endif ?>
    </tbody></table></div></div>
</div>
<div class="col-xl-4">
    <div class="table-card mb-3"><div class="card-header bg-white"><strong>Documents issued (Table 13)</strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($docs as $d): ?><tr><td><?= esc($d['nature']) ?><div class="mono text-muted"><?= esc($d['from']) ?> → <?= esc($d['to']) ?></div></td><td class="num"><?= (int) $d['total'] ?><?= $d['cancelled'] ? '<div class="text-muted">' . (int) $d['cancelled'] . ' cancelled</div>' : '' ?></td></tr><?php endforeach ?>
        <?php if ($docs === []): ?><tr><td class="text-muted">None</td></tr><?php endif ?>
    </tbody></table></div>
    <?php if ($advances !== []): ?>
    <div class="alert alert-warning small"><strong>Advances received (Table 11A):</strong> <?= count($advances) ?> unadjusted advance(s) totalling <?= inr($D::add('0', ...array_column($advances, 'amount'))) ?>. GST may be payable on advances for services — see the "at (review)" sheet in the GSTR-1 workbook and confirm with your accountant.</div>
    <?php endif ?>
</div>
</div>
<p class="small text-muted">These are preparation workbooks for your accountant. DGR Office does not file returns or generate GSTN JSON; e-invoice IRNs are recorded only when obtained from the IRP.</p>
<?= $this->endSection() ?>

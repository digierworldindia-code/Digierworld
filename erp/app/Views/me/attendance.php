<?= $this->extend('layouts/app') ?>
<?php $prev = date('Y-m', strtotime($month . '-01 -1 month')); $next = date('Y-m', strtotime($month . '-01 +1 month')); $pad = (int) date('w', strtotime($month . '-01')); ?>
<?= $this->section('content') ?>
<div class="d-flex gap-2 align-items-center mb-2 small"><a href="<?= site_url('me/attendance?month=' . $prev) ?>">‹ Previous</a><strong><?= date('F Y', strtotime($month . '-01')) ?></strong><?php if ($next <= date('Y-m')): ?><a href="<?= site_url('me/attendance?month=' . $next) ?>">Next ›</a><?php endif ?></div>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="card-body">
    <div class="cal-grid small">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?><div class="cal-head"><?= $d ?></div><?php endforeach ?>
        <?php for ($i = 0; $i < $pad; $i++): ?><div></div><?php endfor ?>
        <?php foreach ($days as $d): $m = $d['mark']; ?>
        <div class="cal-day border rounded p-1 <?= $d['kind'] ? 'bg-light' : '' ?>"><div class="fw-semibold"><?= (int) substr($d['date'], 8) ?></div>
            <?php if ($m): ?><?= status_badge($m['status']) ?><?= $m['check_in_at'] ? '<div class="text-muted">' . date('H:i', strtotime($m['check_in_at'])) . ($m['check_out_at'] ? '–' . date('H:i', strtotime($m['check_out_at'])) : '') . '</div>' : '' ?>
            <?php elseif ($d['holiday']): ?><span class="text-muted"><?= esc($d['holiday']) ?></span><?php elseif ($d['kind'] === 'week_off'): ?><span class="text-muted">Off</span><?php endif ?></div>
        <?php endforeach ?>
    </div>
</div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-body small">
    <div class="card-section-title">Summary</div>
    <dl class="row mb-0">
        <dt class="col-7">Present</dt><dd class="col-5"><?= $summary['present'] ?></dd>
        <dt class="col-7">Paid leave</dt><dd class="col-5"><?= $summary['paid_leave'] ?></dd>
        <dt class="col-7">Unpaid leave</dt><dd class="col-5"><?= $summary['unpaid_leave'] ?></dd>
        <dt class="col-7">Absent</dt><dd class="col-5"><?= $summary['absent'] ?></dd>
        <dt class="col-7">Holidays / weekly offs</dt><dd class="col-5"><?= $summary['holiday'] ?> / <?= $summary['week_off'] ?></dd>
        <dt class="col-7">Not marked yet</dt><dd class="col-5"><?= $summary['unmarked'] ?></dd>
    </dl>
    <p class="form-text mb-0 mt-2">If a day is wrong, ask HR to correct it — corrections are recorded with a reason.</p>
</div></div></div>
</div>
<?= $this->endSection() ?>

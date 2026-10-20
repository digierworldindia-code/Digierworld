<?= $this->extend('layouts/app') ?>
<?php $code = ['present' => 'P', 'absent' => 'A', 'half_day' => '½', 'leave' => 'L', 'holiday' => 'H', 'week_off' => 'W']; $prev = date('Y-m', strtotime($month . '-01 -1 month')); $next = date('Y-m', strtotime($month . '-01 +1 month')); ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-2 small">
    <a href="<?= site_url('hr/attendance/register?month=' . $prev) ?>">‹ <?= date('M Y', strtotime($prev . '-01')) ?></a>
    <strong><?= date('F Y', strtotime($month . '-01')) ?></strong>
    <?php if ($next <= date('Y-m')): ?><a href="<?= site_url('hr/attendance/register?month=' . $next) ?>"><?= date('M Y', strtotime($next . '-01')) ?> ›</a><?php endif ?>
    <span class="text-muted ms-3">P present · A absent · ½ half day · L leave · H holiday · W weekly off · <span class="text-warning">•</span> not marked · <sup>c</sup> corrected</span>
    <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" data-print><i class="bi bi-printer"></i> Print</button>
</div>
<div class="table-card"><div class="table-responsive">
<table class="table table-sm table-bordered small mb-0 att-grid">
    <thead><tr><th class="text-nowrap">Employee</th><?php foreach ($dates as $d): ?><th class="text-center <?= $kinds[$d] ? 'bg-body-tertiary' : '' ?>" title="<?= dmy($d) ?>"><?= (int) substr($d, 8) ?><div class="text-muted fw-normal"><?= substr(date('D', strtotime($d)), 0, 1) ?></div></th><?php endforeach ?>
        <th class="num">P</th><th class="num">Paid L</th><th class="num">LWP/A</th><th class="num">Unmarked</th></tr></thead>
    <tbody>
    <?php foreach ($emps as $e): $s = $summaries[$e['id']]; ?>
    <tr><td class="text-nowrap"><a href="<?= site_url('hr/employees/' . $e['id']) ?>"><?= esc($e['full_name']) ?></a></td>
        <?php foreach ($dates as $d): $m = $marks[$e['id']][$d] ?? null; $emp = \App\Services\Hr\EmployeeService::employedOn($e, $d); ?>
        <td class="text-center <?= $kinds[$d] ? 'bg-body-tertiary' : '' ?>"><?php if (! $emp): ?><span class="text-muted">·</span><?php elseif ($m): ?><span class="<?= $m['status'] === 'absent' ? 'text-danger fw-semibold' : '' ?>"><?= $code[$m['status']] ?></span><?= $m['corrected'] ? '<sup>c</sup>' : '' ?><?php elseif ($kinds[$d]): ?><span class="text-muted"><?= $kinds[$d] === 'holiday' ? 'H' : 'W' ?></span><?php elseif ($d <= date('Y-m-d')): ?><span class="text-warning">•</span><?php endif ?></td>
        <?php endforeach ?>
        <td class="num"><?= $s['present'] ?></td><td class="num"><?= $s['paid_leave'] ?></td><td class="num"><?= $s['unpaid_leave'] + $s['absent'] ?></td><td class="num <?= $s['unmarked'] ? 'text-warning fw-semibold' : '' ?>"><?= $s['unmarked'] ?></td></tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>

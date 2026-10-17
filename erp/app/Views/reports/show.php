<?= $this->extend('layouts/app') ?>
<?php
$fmt = static function ($v, array $c): string {
    if ($v === null || $v === '') {
        return '';
    }
    return match ($c['type'] ?? 'text') {
        'money'   => inr((string) $v),
        'date'    => dmy((string) $v),
        'percent' => esc(\App\Libraries\Dec::rateLabel((string) $v)) . '%',
        'int'     => number_format((int) $v),
        'number'  => esc(rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.')),
        default   => esc((string) $v),
    };
};
$q = ['period' => $preset, 'from' => $from, 'to' => $to];
?>
<?= $this->section('pageActions') ?>
<?php if (can('reports.export')): ?>
<div class="btn-group btn-group-sm"><a class="btn btn-outline-dark" href="<?= site_url('reports/' . $code . '?' . http_build_query($q + ['format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><a class="btn btn-outline-dark" href="<?= site_url('reports/' . $code . '?' . http_build_query($q + ['format' => 'csv'])) ?>">CSV</a></div>
<?php endif ?>
<button class="btn btn-outline-dark btn-sm" type="button" data-print><i class="bi bi-printer"></i> Print</button>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<p class="small text-muted"><?= esc($def['desc']) ?></p>
<?php if (! $noPeriod): ?><?= view('reports/_period', ['from' => $from, 'to' => $to, 'preset' => $preset]) ?><?php endif ?>
<?php if (! empty($data['summary'])): ?>
<div class="row g-3 mb-3">
    <?php foreach ($data['summary'] as [$label, $value, $type]): ?>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label"><?= esc($label) ?></div><div class="kpi-value"><?= $type === 'money' ? inr($value) : esc($value) ?></div></div></div>
    <?php endforeach ?>
</div>
<?php endif ?>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><?php foreach ($data['columns'] as $c): ?><th class="<?= in_array($c['type'] ?? 'text', ['money', 'int', 'number', 'percent'], true) ? 'num' : '' ?>"><?= esc($c['label']) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach (array_slice($data['rows'], 0, 2000) as $r): ?>
    <tr><?php foreach ($data['columns'] as $c): ?><td class="<?= in_array($c['type'] ?? 'text', ['money', 'int', 'number', 'percent'], true) ? 'num' : '' ?>"><?= $fmt($r[$c['key']] ?? null, $c) ?></td><?php endforeach ?></tr>
    <?php endforeach ?>
    <?php if ($data['rows'] === []): ?><tr><td colspan="<?= count($data['columns']) ?>"><div class="empty-state"><i class="bi bi-inbox"></i>Nothing in this period.</div></td></tr><?php endif ?>
    </tbody>
    <?php if (! empty($data['totals']) && $data['rows'] !== []): ?>
    <tfoot><tr class="table-light fw-semibold"><?php foreach ($data['columns'] as $i => $c): ?><td class="<?= in_array($c['type'] ?? 'text', ['money', 'int', 'number', 'percent'], true) ? 'num' : '' ?>"><?php
        if (in_array($c['key'], $data['totals'], true)) {
            $sum = \App\Libraries\Dec::add('0', ...array_map(static fn ($r) => (string) ($r[$c['key']] ?? '0') ?: '0', $data['rows']));
            echo ($c['type'] ?? '') === 'money' ? inr($sum) : esc((string) (int) (float) $sum);
        } elseif ($i === 0) {
            echo 'Total';
        } ?></td><?php endforeach ?></tr></tfoot>
    <?php endif ?>
</table>
</div></div>
<?php if (count($data['rows']) > 2000): ?><div class="alert alert-info small mt-2">Showing the first 2,000 rows. Export to Excel for all <?= number_format(count($data['rows'])) ?> rows.</div><?php endif ?>
<?php foreach ($data['notes'] ?? [] as $n): ?><p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle"></i> <?= esc($n) ?></p><?php endforeach ?>
<?= $this->endSection() ?>

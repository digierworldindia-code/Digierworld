<?php
/**
 * One management report: filters, table, totals, CSV export, print.
 *
 * @var string                              $slug
 * @var array<string, mixed>                $def
 * @var array<string, mixed>                $filters
 * @var array<string, mixed>                $result
 * @var \App\Libraries\Paging|null          $paging
 * @var array<string, array<int, string>>   $lookups
 */
use App\Enums\ReportStatus;

$query  = array_filter(array_intersect_key($filters, array_flip(['date', 'month', 'from', 'to', 'type', 'part', 'machine', 'shift', 'status', 'days'])),
    static fn ($v): bool => $v !== '' && $v !== 0 && $v !== null);
$format = static function (mixed $value, string $type): string {
    if ($value === null || $value === '') {
        return '';
    }

    return match ($type) {
        'date'     => esc(plant_date((string) $value)),
        'datetime' => esc(plant_dt((string) $value)),
        'status'   => status_badge((string) $value),
        'result'   => result_badge((string) $value),
        'percent'  => esc((string) $value) . ' %',
        default    => esc((string) $value),
    };
};
$link = $result['link'] ?? null;
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1><?= esc($def['title']) ?></h1><p class="qms-sub"><?= esc($filters['label']) ?> · <?= esc($def['description']) ?></p></div>
    <div class="d-flex gap-2 d-print-none">
        <a class="btn btn-outline-secondary" href="<?= site_url('reports') ?>"><?= qms_icon('bi-arrow-left') ?> Reports</a>
        <button class="btn btn-outline-primary" type="button" data-print><?= qms_icon('bi-printer') ?> Print</button>
        <?php if (can('report.export')): ?><a class="btn btn-primary" href="<?= site_url('reports/' . $slug . '/export') . '?' . http_build_query($query) ?>"><?= qms_icon('bi-filetype-csv') ?> Export CSV</a><?php endif ?>
    </div>
</div>

<form class="qms-filter row g-2 align-items-end" method="get">
    <?php if ($def['period'] === 'day'): ?>
        <div class="col-6 col-md-2"><label class="form-label" for="r-date">Date</label><input class="form-control" type="date" id="r-date" name="date" value="<?= esc($filters['from'], 'attr') ?>"></div>
    <?php elseif ($def['period'] === 'month'): ?>
        <div class="col-6 col-md-2"><label class="form-label" for="r-month">Month</label><input class="form-control" type="month" id="r-month" name="month" value="<?= esc($filters['month'], 'attr') ?>"></div>
    <?php elseif ($def['period'] === 'range'): ?>
        <div class="col-6 col-md-2"><label class="form-label" for="r-from">From</label><input class="form-control" type="date" id="r-from" name="from" value="<?= esc($filters['from'], 'attr') ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label" for="r-to">To</label><input class="form-control" type="date" id="r-to" name="to" value="<?= esc($filters['to'], 'attr') ?>"></div>
    <?php endif ?>
    <?php foreach ($def['filters'] as $key): ?>
        <?php if ($key === 'days'): ?>
            <div class="col-6 col-md-2"><label class="form-label" for="r-days">Due within (days)</label><input class="form-control" type="number" min="0" max="365" id="r-days" name="days" value="<?= (int) $filters['days'] ?>"></div>
        <?php elseif ($key === 'status'): ?>
            <div class="col-6 col-md-2"><label class="form-label" for="r-status">Status</label>
                <select class="form-select" id="r-status" name="status"><option value="">Any (not cancelled)</option><option value="PENDING"<?= $filters['status'] === 'PENDING' ? ' selected' : '' ?>>Waiting for approval</option>
                    <?php foreach (ReportStatus::cases() as $case): ?><option value="<?= $case->value ?>"<?= $filters['status'] === $case->value ? ' selected' : '' ?>><?= esc($case->label()) ?></option><?php endforeach ?></select></div>
        <?php else: ?>
            <div class="col-6 col-md-2"><label class="form-label" for="r-<?= $key ?>"><?= ucfirst($key === 'type' ? 'Report type' : $key) ?></label>
                <select class="form-select" id="r-<?= $key ?>" name="<?= $key ?>"><option value="">All</option>
                    <?php foreach ($lookups[$key] as $id => $text): ?><option value="<?= (int) $id ?>"<?= (int) $filters[$key] === (int) $id ? ' selected' : '' ?>><?= esc($text) ?></option><?php endforeach ?></select></div>
        <?php endif ?>
    <?php endforeach ?>
    <div class="col-6 col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Show</button></div>
</form>

<div class="card"><div class="qms-table-wrap">
<table class="table table-sm table-hover qms-report-table">
    <thead><tr><?php foreach ($result['columns'] as $c): ?><th class="<?= in_array($c['type'], ['int', 'percent', 'decimal'], true) ? 'text-end' : '' ?>"><?= esc($c['label']) ?></th><?php endforeach ?><?= $link !== null ? '<th class="d-print-none"></th>' : '' ?></tr></thead>
    <tbody>
    <?php if ($result['rows'] === []): ?><tr><td colspan="<?= count($result['columns']) + 1 ?>" class="qms-empty"><?= qms_icon('bi-inbox') ?>No data for these filters.</td></tr><?php endif ?>
    <?php foreach ($result['rows'] as $row): ?>
        <tr>
            <?php foreach ($result['columns'] as $key => $c): ?>
                <td class="<?= in_array($c['type'], ['int', 'percent', 'decimal'], true) ? 'text-end num' : '' ?><?= $key === 'state' && ($row['state'] ?? '') === 'EXPIRED' ? ' text-danger fw-bold' : '' ?>"><?= $format($row[$key] ?? null, $c['type']) ?></td>
            <?php endforeach ?>
            <?php if ($link === 'id'): ?><td class="text-end d-print-none"><a class="btn btn-sm btn-outline-primary" href="<?= site_url('inspections/' . $row['id']) ?>"><?= qms_icon('bi-eye') ?></a></td><?php endif ?>
            <?php if ($link === 'gauge'): ?><td class="text-end d-print-none"><a class="btn btn-sm btn-outline-primary" href="<?= site_url('gauges/' . $row['id']) ?>"><?= qms_icon('bi-eye') ?></a></td><?php endif ?>
        </tr>
    <?php endforeach ?>
    </tbody>
    <?php if (($result['totals'] ?? null) !== null): ?>
        <tfoot><tr class="fw-bold">
            <?php foreach ($result['columns'] as $key => $c): ?><td class="<?= in_array($c['type'], ['int', 'percent', 'decimal'], true) ? 'text-end num' : '' ?>"><?= esc((string) ($result['totals'][$key] ?? '')) ?></td><?php endforeach ?>
            <?= $link !== null ? '<td class="d-print-none"></td>' : '' ?>
        </tr></tfoot>
    <?php endif ?>
</table>
</div></div>
<?php if (isset($result['totals']['rejection_rate']) && $result['totals']['rejection_rate'] !== null): ?>
    <p class="mt-2"><strong>Rejection rate:</strong> <?= esc($result['totals']['rejection_rate']) ?> % of inspected quantity (all In-Process inspections in the period).</p>
<?php endif ?>
<?php if ($paging !== null): ?><?= view('partials/pager', ['paging' => $paging]) ?><?php endif ?>
<p class="small text-muted mt-2 d-none d-print-block">Printed by <?= esc($currentUser['display_name']) ?> on <?= esc(plant_dt(service('clock')->nowUtcString())) ?></p>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= qms_asset('assets/js/print.js') ?>"></script>
<?= $this->endSection() ?>

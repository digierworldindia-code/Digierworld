<?php
/**
 * One management report: report.php?name=daily (filters in the query string).
 * &export=csv downloads it as CSV (needs report.export; every export is audited).
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('report.view');
require_once QMS_ROOT . '/includes/reports.php';
require_once QMS_ROOT . '/includes/masters.php';

$name    = get('name');
$def     = REPORT_CATALOGUE[$name] ?? not_found('Unknown report.');
$filters = report_filters($name, $_GET, production_current()['date']);

if (get('export') === 'csv') {
    require_permission('report.export');
    $result = report_run($filters);
    $file   = 'qms-' . $name . '-' . ($filters['from'] ?? $filters['today'] ?? 'now') . (isset($filters['to']) && $filters['to'] !== ($filters['from'] ?? '') ? '-' . $filters['to'] : '') . '.csv';
    audit_log('EXPORT', 'report', null, null, ['report' => $name, 'filters' => array_diff_key($filters, ['label' => 0]), 'rows' => count($result['rows'])], $def['title']);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file) . '"');
    header('Cache-Control: private, no-store');
    echo csv_build($result['columns'], $result['rows'], $result['totals']);
    exit;
}

$paging = in_array($name, ['daily', 'rejection', 'oos'], true) ? paging(50) : null;
$result = report_run($filters, $paging);
$link   = $result['link'] ?? null;
$query  = array_filter(array_intersect_key($filters, array_flip(['date', 'month', 'from', 'to', 'type', 'part', 'machine', 'shift', 'status', 'days'])),
    static fn ($v): bool => $v !== '' && $v !== 0 && $v !== null);
if ($def['period'] === 'day') {
    $query = ['date' => $filters['from']] + array_diff_key($query, ['from' => 0, 'to' => 0]);
} elseif ($def['period'] === 'month') {
    $query = array_diff_key($query, ['from' => 0, 'to' => 0]);
}
$lookups = ['type' => master_lookup('report-types'), 'part' => master_lookup('parts'), 'machine' => master_lookup('machines'), 'shift' => master_lookup('shifts')];
$numeric = static fn (array $c): bool => in_array($c['type'], ['int', 'percent', 'decimal'], true);

$page_title   = $def['title'];
$page_scripts = ['print.js'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1><?= e($def['title']) ?></h1><p class="qms-sub"><?= e($filters['label']) ?> · <?= e($def['description']) ?></p></div>
    <div class="d-flex gap-2 d-print-none">
        <a class="btn btn-outline-secondary" href="<?= url('reports.php') ?>"><?= qms_icon('bi-arrow-left') ?> Reports</a>
        <button class="btn btn-outline-primary" type="button" data-print><?= qms_icon('bi-printer') ?> Print</button>
        <?php if (can('report.export')): ?><a class="btn btn-primary" href="<?= e(url('report.php') . '?' . http_build_query(['name' => $name] + $query + ['export' => 'csv'])) ?>"><?= qms_icon('bi-filetype-csv') ?> Export CSV</a><?php endif ?>
    </div>
</div>

<form class="qms-filter row g-2 align-items-end d-print-none" method="get" action="<?= url('report.php') ?>">
    <input type="hidden" name="name" value="<?= e($name) ?>">
    <?php if ($def['period'] === 'day'): ?>
        <div class="col-6 col-md-2"><label class="form-label" for="r-date">Date</label><input class="form-control" type="date" id="r-date" name="date" value="<?= e($filters['from']) ?>"></div>
    <?php elseif ($def['period'] === 'month'): ?>
        <div class="col-6 col-md-2"><label class="form-label" for="r-month">Month</label><input class="form-control" type="month" id="r-month" name="month" value="<?= e($filters['month']) ?>"></div>
    <?php elseif ($def['period'] === 'range'): ?>
        <div class="col-6 col-md-2"><label class="form-label" for="r-from">From</label><input class="form-control" type="date" id="r-from" name="from" value="<?= e($filters['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label" for="r-to">To</label><input class="form-control" type="date" id="r-to" name="to" value="<?= e($filters['to']) ?>"></div>
    <?php endif ?>
    <?php foreach ($def['filters'] as $key): ?>
        <?php if ($key === 'days'): ?>
            <div class="col-6 col-md-2"><label class="form-label" for="r-days">Due within (days)</label><input class="form-control" type="number" min="0" max="365" id="r-days" name="days" value="<?= (int) $filters['days'] ?>"></div>
        <?php elseif ($key === 'status'): ?>
            <div class="col-6 col-md-2"><label class="form-label" for="r-status">Status</label>
                <select class="form-select" id="r-status" name="status"><option value="">Any (not cancelled)</option><option value="PENDING"<?= $filters['status'] === 'PENDING' ? ' selected' : '' ?>>Waiting for approval</option>
                    <?php foreach (REPORT_STATUSES as $code => $s): ?><option value="<?= $code ?>"<?= $filters['status'] === $code ? ' selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach ?></select></div>
        <?php else: ?>
            <div class="col-6 col-md-2"><label class="form-label" for="r-<?= $key ?>"><?= $key === 'type' ? 'Report type' : ucfirst($key) ?></label>
                <select class="form-select" id="r-<?= $key ?>" name="<?= $key ?>"><option value="">All</option>
                    <?php foreach ($lookups[$key] as $id => $text): ?><option value="<?= (int) $id ?>"<?= (int) $filters[$key] === (int) $id ? ' selected' : '' ?>><?= e($text) ?></option><?php endforeach ?></select></div>
        <?php endif ?>
    <?php endforeach ?>
    <div class="col-6 col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Show</button></div>
</form>

<div class="card"><div class="qms-table-wrap">
<table class="table table-sm table-hover qms-report-table">
    <thead><tr><?php foreach ($result['columns'] as $c): ?><th class="<?= $numeric($c) ? 'text-end' : '' ?>"><?= e($c['label']) ?></th><?php endforeach ?><?= $link !== null ? '<th class="d-print-none"></th>' : '' ?></tr></thead>
    <tbody>
    <?php if ($result['rows'] === []): ?><tr><td colspan="<?= count($result['columns']) + 1 ?>" class="qms-empty"><?= qms_icon('bi-inbox') ?>No data for these filters.</td></tr><?php endif ?>
    <?php foreach ($result['rows'] as $row): ?>
        <tr>
            <?php foreach ($result['columns'] as $key => $c): ?>
                <td class="<?= $numeric($c) ? 'text-end num' : '' ?><?= $key === 'state' && ($row['state'] ?? '') === 'EXPIRED' ? ' text-danger fw-bold' : '' ?>"><?= report_cell($row[$key] ?? null, $c['type']) ?></td>
            <?php endforeach ?>
            <?php if ($link === 'inspection'): ?><td class="text-end d-print-none"><a class="btn btn-sm btn-outline-primary" href="<?= url('inspection.php?id=' . (int) $row['id']) ?>" aria-label="Open report"><?= qms_icon('bi-eye') ?></a></td><?php endif ?>
            <?php if ($link === 'gauge'): ?><td class="text-end d-print-none"><a class="btn btn-sm btn-outline-primary" href="<?= url('gauge.php?id=' . (int) $row['id']) ?>" aria-label="Open gauge"><?= qms_icon('bi-eye') ?></a></td><?php endif ?>
        </tr>
    <?php endforeach ?>
    </tbody>
    <?php if (($result['totals'] ?? null) !== null): ?>
        <tfoot><tr class="fw-bold">
            <?php foreach ($result['columns'] as $key => $c): ?><td class="<?= $numeric($c) ? 'text-end num' : '' ?>"><?= e((string) ($result['totals'][$key] ?? '')) ?></td><?php endforeach ?>
            <?= $link !== null ? '<td class="d-print-none"></td>' : '' ?>
        </tr></tfoot>
    <?php endif ?>
</table>
</div></div>
<?php if (($result['totals']['rejection_rate'] ?? null) !== null): ?>
    <p class="mt-2"><strong>Rejection rate:</strong> <?= e($result['totals']['rejection_rate']) ?> % of inspected quantity (all In-Process inspections in the period).</p>
<?php endif ?>
<?php if ($paging !== null) { require QMS_ROOT . '/includes/views/pager.php'; } ?>
<p class="small text-muted mt-2 d-none d-print-block">Printed by <?= e($user['display_name']) ?> on <?= e(plant_now()->format('d-m-Y H:i')) ?></p>
<?php require QMS_ROOT . '/includes/layout/footer.php';

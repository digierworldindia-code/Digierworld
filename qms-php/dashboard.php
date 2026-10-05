<?php
/**
 * Management dashboard: KPI cards, pass / fail trend, workload and alerts.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('dashboard.view');
require_once QMS_ROOT . '/includes/dashboard.php';
require_once QMS_ROOT . '/includes/masters.php';
require_once QMS_ROOT . '/includes/sheets.php';

$today   = production_current()['date'];
$filters = dash_filters($_GET, $today);
$cards   = dash_cards($filters);
$trend   = dash_trend($filters);
$byType  = dash_by_type($filters);
$topOos  = dash_top_oos($filters);
$machines = dash_machines($filters);
$overdue = dash_overdue();
$gauges  = dash_gauge_alerts($today);
$syncPending = sheet_pending_report_count();
$syncOn  = sheet_configured();
$lookups = [
    'type'     => master_lookup('report-types'),
    'part'     => master_lookup('parts'),
    'machine'  => master_lookup('machines'),
    'shift'    => master_lookup('shifts'),
    'operator' => master_lookup('employees'),
];
$statuses = ['' => 'Any status', 'PENDING' => 'Waiting for approval'] + array_map(static fn (array $s): string => $s['label'], REPORT_STATUSES);

// Links from the cards to the inspection list with the same filters.
$listUrl = static function (array $extra) use ($filters): string {
    $q = ['from' => $filters['from'], 'to' => $filters['to']];
    foreach (['type', 'part', 'machine', 'shift'] as $k) {
        if ($filters[$k] > 0) {
            $q[$k] = $filters[$k];
        }
    }

    return url('inspections.php') . '?' . http_build_query($q + $extra);
};

// SVG bar chart (no inline styles or scripts: strict CSP).
$max    = max(1, ...array_map(static fn (array $d): int => $d['pass'] + $d['fail'] + $d['other'], $trend));
$n      = count($trend);
$width  = 720;
$height = 180;
$slot   = $width / max(1, $n);
$bar    = max(4, $slot * 0.6);

$page_title = 'Dashboard';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Dashboard</h1><p class="qms-sub"><?= e($filters['label']) ?></p></div>
    <?php if (can('report.view')): ?><a class="btn btn-outline-primary" href="<?= url('reports.php') ?>"><?= qms_icon('bi-bar-chart-line') ?> Reports</a><?php endif ?>
</div>

<form class="qms-filter row g-2 align-items-end" method="get" action="<?= url('dashboard.php') ?>">
    <div class="col-6 col-md-2">
        <label class="form-label" for="d-period">Period</label>
        <select class="form-select" id="d-period" name="period" data-autosubmit>
            <option value="day"<?= $filters['period'] === 'day' ? ' selected' : '' ?>>Day</option>
            <option value="month"<?= $filters['period'] === 'month' ? ' selected' : '' ?>>Month</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <?php if ($filters['period'] === 'month'): ?>
            <label class="form-label" for="d-month">Month</label><input class="form-control" type="month" id="d-month" name="month" value="<?= e($filters['month']) ?>">
        <?php else: ?>
            <label class="form-label" for="d-date">Date</label><input class="form-control" type="date" id="d-date" name="date" value="<?= e($filters['date']) ?>">
        <?php endif ?>
    </div>
    <?php foreach (['type' => 'Report type', 'part' => 'Part', 'machine' => 'Machine', 'shift' => 'Shift', 'operator' => 'Operator'] as $key => $label): ?>
        <div class="col-6 col-md-2">
            <label class="form-label" for="d-<?= $key ?>"><?= $label ?></label>
            <select class="form-select" id="d-<?= $key ?>" name="<?= $key ?>"><option value="">All</option>
                <?php foreach ($lookups[$key] as $id => $text): ?><option value="<?= (int) $id ?>"<?= $filters[$key] === (int) $id ? ' selected' : '' ?>><?= e($text) ?></option><?php endforeach ?>
            </select>
        </div>
    <?php endforeach ?>
    <div class="col-6 col-md-2">
        <label class="form-label" for="d-status">Status</label>
        <select class="form-select" id="d-status" name="status">
            <?php foreach ($statuses as $value => $text): ?><option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($text) ?></option><?php endforeach ?>
        </select>
    </div>
    <div class="col-6 col-md-2"><button class="btn btn-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Apply</button></div>
    <div class="col-6 col-md-2"><a class="btn btn-link w-100" href="<?= url('dashboard.php') ?>">Today</a></div>
</form>

<div class="qms-kpis">
    <a class="qms-kpi" href="<?= e($listUrl([])) ?>"><div class="label"><?= qms_icon('bi-clipboard-check') ?> Total inspections</div><div class="value"><?= $cards['total'] ?></div></a>
    <a class="qms-kpi qms-kpi--pass" href="<?= e($listUrl(['result' => 'PASS'])) ?>"><div class="label"><?= qms_icon('bi-check-circle') ?> Passed</div><div class="value"><?= $cards['passed'] ?></div></a>
    <a class="qms-kpi qms-kpi--fail" href="<?= e($listUrl(['result' => 'FAIL'])) ?>"><div class="label"><?= qms_icon('bi-x-octagon') ?> Failed</div><div class="value"><?= $cards['failed'] ?></div></a>
    <a class="qms-kpi qms-kpi--pending" href="<?= e($listUrl(['status' => 'PENDING'])) ?>"><div class="label"><?= qms_icon('bi-hourglass-split') ?> Pending approval</div><div class="value"><?= $cards['pending'] ?></div></a>
    <a class="qms-kpi qms-kpi--fail" href="<?= e(url('report.php') . '?' . http_build_query(['name' => 'oos', 'from' => $filters['from'], 'to' => $filters['to']])) ?>"><div class="label"><?= qms_icon('bi-exclamation-octagon') ?> Out of spec</div><div class="value"><?= $cards['oos'] ?></div><div class="small text-muted">readings in <?= $cards['oos_reports'] ?> report(s)</div></a>
    <?php if (can('sync.view')): ?><a class="qms-kpi<?= $syncPending > 0 ? ' qms-kpi--pending' : '' ?>" href="<?= url('admin/sync.php') ?>"><?php else: ?><div class="qms-kpi<?= $syncPending > 0 ? ' qms-kpi--pending' : '' ?>"><?php endif ?>
        <div class="label"><?= qms_icon('bi-cloud-arrow-up') ?> Pending Google sync</div><div class="value"><?= $syncPending ?></div><div class="small text-muted"><?= $syncOn ? 'reports not yet in Sheets' : 'integration not configured' ?></div>
    <?= can('sync.view') ? '</a>' : '</div>' ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header">Results by production day</div>
            <div class="card-body">
                <svg class="qms-chart" viewBox="0 0 <?= $width ?> <?= $height + 30 ?>" role="img" aria-labelledby="chartTitle">
                    <title id="chartTitle">Passed and failed reports per production day</title>
                    <?php foreach ($trend as $i => $d):
                        $x     = $i * $slot + ($slot - $bar) / 2;
                        $hPass = $d['pass'] / $max * $height;
                        $hFail = $d['fail'] / $max * $height;
                        $hOth  = $d['other'] / $max * $height; ?>
                        <g>
                            <title><?= e(plant_date($d['date'])) ?>: <?= $d['pass'] ?> passed, <?= $d['fail'] ?> failed, <?= $d['other'] ?> open</title>
                            <rect class="seg-other" x="<?= round($x, 1) ?>" y="<?= round($height - $hPass - $hFail - $hOth, 1) ?>" width="<?= round($bar, 1) ?>" height="<?= round($hOth, 1) ?>"></rect>
                            <rect class="seg-fail" x="<?= round($x, 1) ?>" y="<?= round($height - $hPass - $hFail, 1) ?>" width="<?= round($bar, 1) ?>" height="<?= round($hFail, 1) ?>"></rect>
                            <rect class="seg-pass" x="<?= round($x, 1) ?>" y="<?= round($height - $hPass, 1) ?>" width="<?= round($bar, 1) ?>" height="<?= round($hPass, 1) ?>"></rect>
                            <?php if ($n <= 16 || $i % 2 === 0): ?><text x="<?= round($x + $bar / 2, 1) ?>" y="<?= $height + 16 ?>" text-anchor="middle"><?= e(substr($d['date'], 8, 2)) ?></text><?php endif ?>
                        </g>
                    <?php endforeach ?>
                    <line x1="0" y1="<?= $height ?>" x2="<?= $width ?>" y2="<?= $height ?>" class="axis"></line>
                </svg>
                <div class="d-flex gap-3 small mt-1">
                    <span><span class="qms-legend seg-pass"></span> Passed</span><span><span class="qms-legend seg-fail"></span> Failed</span><span><span class="qms-legend seg-other"></span> Open / incomplete</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">Alerts</div>
            <div class="list-group list-group-flush">
                <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= url('gauges.php?due=expired') ?>">
                    <span><?= qms_icon('bi-x-octagon', 'text-danger') ?> Gauges out of calibration</span><strong class="<?= $gauges['expired'] > 0 ? 'text-danger' : '' ?>"><?= $gauges['expired'] ?></strong></a>
                <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= url('gauges.php?due=soon') ?>">
                    <span><?= qms_icon('bi-exclamation-triangle', 'text-warning') ?> Calibration due soon</span><strong><?= $gauges['soon'] ?></strong></a>
                <div class="list-group-item"><strong><?= qms_icon('bi-clock-history') ?> Waiting for approval &gt; 24 h</strong></div>
                <?php foreach ($overdue as $o): ?>
                    <a class="list-group-item list-group-item-action small" href="<?= url('inspection.php?id=' . (int) $o['id']) ?>">
                        <?= e($o['report_no']) ?><?= (int) $o['revision_no'] > 0 ? ' Rev ' . (int) $o['revision_no'] : '' ?> · <?= e($o['part_number']) ?> · <?= e($o['machine_code']) ?>
                        <div class="text-muted"><?= e(status_label($o['status'])) ?> since <?= e(plant_dt($o['submitted_at'])) ?></div></a>
                <?php endforeach ?>
                <?php if ($overdue === []): ?><div class="list-group-item small text-muted">None.</div><?php endif ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header">By report type</div><div class="qms-table-wrap">
            <table class="table table-sm">
                <thead><tr><th>Type</th><th class="text-end">Total</th><th class="text-end" title="Draft or returned">Open</th><th class="text-end">Pending</th><th class="text-end">Approved</th><th class="text-end">Failed</th></tr></thead>
                <tbody>
                <?php foreach ($byType as $t): ?>
                    <tr><td><strong><?= e($t['code']) ?></strong> <span class="small text-muted d-none d-xxl-inline"><?= e($t['name']) ?></span></td><td class="text-end"><?= (int) $t['total'] ?></td><td class="text-end"><?= (int) $t['in_progress'] ?></td><td class="text-end"><?= (int) $t['pending'] ?></td><td class="text-end"><?= (int) $t['approved'] ?></td><td class="text-end<?= (int) $t['failed'] > 0 ? ' text-danger fw-bold' : '' ?>"><?= (int) $t['failed'] ?></td></tr>
                <?php endforeach ?>
                <?php if ($byType === []): ?><tr><td colspan="6" class="qms-empty">No inspections in this period.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header">Most frequent out-of-spec parameters</div><div class="qms-table-wrap">
            <table class="table table-sm">
                <thead><tr><th>Parameter</th><th class="text-end">OOS readings</th><th class="text-end">Reports</th></tr></thead>
                <tbody>
                <?php foreach ($topOos as $p): ?><tr><td><?= e($p['name']) ?> <span class="small text-muted"><?= e($p['code']) ?></span></td><td class="text-end text-danger fw-bold"><?= (int) $p['readings'] ?></td><td class="text-end"><?= (int) $p['reports'] ?></td></tr><?php endforeach ?>
                <?php if ($topOos === []): ?><tr><td colspan="3" class="qms-empty"><?= qms_icon('bi-emoji-smile') ?>No out-of-spec readings.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="col-12">
        <div class="card"><div class="card-header">Machines</div><div class="qms-table-wrap">
            <table class="table table-sm">
                <thead><tr><th>Machine</th><th class="text-end">Inspections</th><th class="text-end">Failed</th><th class="text-end">OOS readings</th></tr></thead>
                <tbody>
                <?php foreach ($machines as $m): ?><tr><td><?= e($m['machine_code']) ?> <span class="small text-muted"><?= e($m['machine_name']) ?></span></td><td class="text-end"><?= (int) $m['total'] ?></td><td class="text-end<?= (int) $m['failed'] > 0 ? ' text-danger fw-bold' : '' ?>"><?= (int) $m['failed'] ?></td><td class="text-end"><?= (int) $m['oos'] ?></td></tr><?php endforeach ?>
                <?php if ($machines === []): ?><tr><td colspan="4" class="qms-empty">No inspections in this period.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div></div>
    </div>
</div>
<?php require QMS_ROOT . '/includes/layout/footer.php';

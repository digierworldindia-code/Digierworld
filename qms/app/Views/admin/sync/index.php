<?php
/**
 * Google Sheets Sync Monitor.
 *
 * @var array<string, int>          $summary
 * @var list<array<string, mixed>>  $jobs
 * @var array<string, string>       $filters
 * @var \App\Libraries\Paging       $paging
 * @var array<string, mixed>        $heartbeat
 * @var array<string, mixed>        $credentials
 * @var bool                        $enabled
 * @var bool                        $configured
 */
$lastRun     = $heartbeat['last_run'] ?? null;
$lastSuccess = $heartbeat['last_success'] ?? null;
$stale       = $enabled && ($summary['PENDING_SYNC'] + $summary['PROCESSING']) > 0
    && ($lastSuccess === null || strtotime($lastSuccess . ' UTC') < time() - 900);
$manage      = can('sync.manage');
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Google Sheets sync</h1><p class="qms-sub">MySQL is the system of record. Google Sheets receives a reporting copy through this queue.</p></div>
    <?php if (can('setting.manage')): ?><a class="btn btn-outline-primary" href="<?= site_url('admin/settings?tab=google') ?>"><?= qms_icon('bi-sliders') ?> Settings</a><?php endif ?>
</div>

<?php if (! $configured): ?>
    <div class="alert alert-info"><?= qms_icon('bi-info-circle') ?> The integration is not configured: no jobs are being queued. Enter the spreadsheet ID in Settings → Google Sheets, then use “Queue past reports” below.</div>
<?php elseif (! $enabled): ?>
    <div class="alert alert-warning"><?= qms_icon('bi-pause-circle') ?> Sync is switched off. Jobs are kept and will be sent when it is switched on again.</div>
<?php endif ?>
<?php if (! $credentials['configured']): ?>
    <div class="alert alert-danger"><?= qms_icon('bi-key') ?> <strong>Credentials:</strong> <?= esc($credentials['hint']) ?></div>
<?php endif ?>
<?php if ($stale): ?>
    <div class="alert alert-danger"><?= qms_icon('bi-exclamation-octagon') ?> No successful sync for more than 15 minutes while jobs are waiting. Check the cron job (<code>php spark sheets:sync</code>) and the last error below.</div>
<?php endif ?>

<div class="qms-kpis">
    <a class="qms-kpi qms-kpi--pending" href="?status=PENDING_SYNC"><div class="label"><?= qms_icon('bi-hourglass-split') ?> Pending</div><div class="value"><?= (int) $summary['PENDING_SYNC'] ?></div></a>
    <a class="qms-kpi" href="?status=PROCESSING"><div class="label"><?= qms_icon('bi-arrow-repeat') ?> Processing</div><div class="value"><?= (int) $summary['PROCESSING'] ?></div></a>
    <a class="qms-kpi qms-kpi--fail" href="?status=FAILED"><div class="label"><?= qms_icon('bi-cloud-slash') ?> Failed</div><div class="value"><?= (int) $summary['FAILED'] ?></div></a>
    <a class="qms-kpi qms-kpi--pass" href="?status=SYNCED"><div class="label"><?= qms_icon('bi-cloud-check') ?> Synced</div><div class="value"><?= (int) $summary['SYNCED'] ?></div></a>
    <div class="qms-kpi"><div class="label"><?= qms_icon('bi-clock-history') ?> Oldest waiting</div><div class="value"><?= (int) $summary['oldest_pending_minutes'] ?><small class="fs-6"> min</small></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header">Status</div><div class="card-body">
            <dl class="qms-dl">
                <dt>Sync</dt><dd><?= $enabled ? '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-play-fill') . ' On</span>' : '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-pause-fill') . ' Off</span>' ?></dd>
                <dt>Service account</dt><dd><?= esc((string) ($credentials['email'] ?? 'not configured')) ?></dd>
                <dt>Spreadsheet</dt><dd class="text-break"><?= esc($spreadsheet !== '' ? $spreadsheet : '–') ?></dd>
                <dt>Detail spreadsheet</dt><dd class="text-break"><?= esc($detail !== '' ? $detail : 'same as main') ?></dd>
                <dt>Last worker run</dt><dd><?= $lastRun !== null ? esc(plant_dt($lastRun, 'd-m-Y H:i:s')) : 'never' ?></dd>
                <dt>Last success</dt><dd><?= $lastSuccess !== null ? esc(plant_dt($lastSuccess, 'd-m-Y H:i:s')) : 'never' ?></dd>
                <dt>Last error</dt><dd class="small text-break"><?= esc((string) ($heartbeat['last_error'] ?? '–')) ?></dd>
            </dl>
        </div></div>
    </div>
    <?php if ($manage): ?>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header">Actions</div><div class="card-body">
            <form method="post" action="<?= site_url('admin/sync/retry-failed') ?>" class="mb-3" data-confirm="Queue all failed jobs again?">
                <?= csrf_field() ?>
                <button class="btn btn-outline-primary" type="submit" data-once<?= $summary['FAILED'] === 0 ? ' disabled' : '' ?>><?= qms_icon('bi-arrow-clockwise') ?> Retry all failed jobs</button>
            </form>
            <form method="post" action="<?= site_url('admin/sync/backfill') ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-12"><strong>Queue past reports</strong><div class="small text-muted">For reports created before the integration was configured or after a spreadsheet was replaced. Rows already in the sheet are not duplicated.</div></div>
                <div class="col-sm-5"><label class="form-label" for="bf-from">From</label><input class="form-control" type="date" id="bf-from" name="from" required></div>
                <div class="col-sm-5"><label class="form-label" for="bf-to">To</label><input class="form-control" type="date" id="bf-to" name="to" required></div>
                <div class="col-sm-2"><button class="btn btn-primary w-100" type="submit" data-once>Queue</button></div>
            </form>
        </div></div>
    </div>
    <?php endif ?>
</div>

<form class="qms-filter row g-2 align-items-end" method="get">
    <div class="col-md-3"><label class="form-label" for="s-status">Status</label>
        <select class="form-select" id="s-status" name="status"><option value="">All</option>
            <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= ($filters['status'] ?? '') === $s ? ' selected' : '' ?>><?= esc(str_replace('_', ' ', $s)) ?></option><?php endforeach ?></select></div>
    <div class="col-md-3"><label class="form-label" for="s-type">Job</label>
        <select class="form-select" id="s-type" name="job_type"><option value="">All</option>
            <?php foreach (['REPORT_UPSERT' => 'Reports tab', 'OBSERVATIONS_APPEND' => 'Observation rows'] as $k => $v): ?><option value="<?= $k ?>"<?= ($filters['job_type'] ?? '') === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach ?></select></div>
    <div class="col-md-4"><label class="form-label" for="s-q">Report no.</label><input class="form-control" id="s-q" name="q" value="<?= esc($filters['q'] ?? '', 'attr') ?>"></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>

<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>#</th><th>Report</th><th>Job</th><th>Tab</th><th>Status</th><th>Attempts</th><th>Next / last attempt</th><th>Result</th><th></th></tr></thead>
    <tbody>
    <?php if ($jobs === []): ?><tr><td colspan="9" class="qms-empty"><?= qms_icon('bi-cloud') ?>No sync jobs.</td></tr><?php endif ?>
    <?php foreach ($jobs as $j): ?>
        <tr>
            <td class="text-muted"><?= (int) $j['id'] ?></td>
            <td><a href="<?= site_url('inspections/' . $j['report_id']) ?>"><?= esc((string) $j['report_no']) ?></a><?= (int) $j['revision_no'] > 0 ? ' <span class="small">Rev ' . (int) $j['revision_no'] . '</span>' : '' ?></td>
            <td class="small"><?= $j['job_type'] === 'REPORT_UPSERT' ? 'Reports row' : 'Observations' ?></td>
            <td class="small"><?= esc($j['sheet_name']) ?></td>
            <td><?= sync_badge($j['sync_status']) ?></td>
            <td class="num"><?= (int) $j['attempt_count'] ?></td>
            <td class="small"><?= $j['sync_status'] === 'PENDING_SYNC' ? 'next ' . esc(plant_dt($j['next_attempt_at'], 'd-m H:i')) : '' ?><?= $j['last_attempt_at'] !== null ? '<div class="text-muted">last ' . esc(plant_dt($j['last_attempt_at'], 'd-m H:i')) . '</div>' : '' ?></td>
            <td class="small text-break"><?= $j['sync_status'] === 'SYNCED' ? esc((string) $j['sheet_range']) : '<span class="text-danger">' . esc((string) ($j['error_message'] ?? '')) . '</span>' ?></td>
            <td class="text-end">
                <?php if ($manage && in_array($j['sync_status'], ['FAILED', 'PENDING_SYNC', 'PROCESSING'], true)): ?>
                    <form method="post" action="<?= site_url('admin/sync/' . $j['id'] . '/retry') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary" type="submit" data-once><?= qms_icon('bi-arrow-clockwise') ?> Retry now</button></form>
                <?php endif ?>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['paging' => $paging]) ?>
<?= $this->endSection() ?>

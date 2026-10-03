<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Login history</h1><p class="qms-sub">Logins, failures, lockouts, logouts and session timeouts (append-only).</p></div>
</div>
<form class="qms-filter row g-2 align-items-end" method="get">
    <div class="col-6 col-md-2"><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= esc($filters['from'] ?? '', 'attr') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= esc($filters['to'] ?? '', 'attr') ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="user">Username</label><input class="form-control" id="user" name="user" value="<?= esc($filters['user'] ?? '', 'attr') ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="event">Event</label>
        <select class="form-select" id="event" name="event"><option value="">All</option>
        <?php foreach ($events as $e): ?><option<?= ($filters['event'] ?? '') === $e ? ' selected' : '' ?>><?= esc($e) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="ip">IP</label><input class="form-control" id="ip" name="ip" value="<?= esc($filters['ip'] ?? '', 'attr') ?>"></div>
    <div class="col-12 text-end"><button class="btn btn-outline-primary" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>Date / time</th><th>Username</th><th>Event</th><th>Reason</th><th>IP</th><th>Device</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="6" class="qms-empty">No entries.</td></tr><?php endif ?>
    <?php foreach ($rows as $r):
        $tone = match ($r['event']) { 'LOGIN_SUCCESS' => 'pass', 'LOGIN_FAILED', 'ACCOUNT_LOCKED', 'SESSION_REVOKED' => 'fail', default => 'muted' }; ?>
        <tr>
            <td class="num text-nowrap"><?= esc(plant_dt($r['created_at'], 'd-m-Y H:i:s')) ?></td>
            <td><?= esc($r['username_attempted']) ?></td>
            <td><span class="qms-badge qms-badge--<?= $tone ?>"><?= esc(str_replace('_', ' ', $r['event'])) ?></span></td>
            <td class="small"><?= esc($r['failure_reason'] ?? '') ?></td>
            <td class="num"><?= esc($r['ip_address']) ?></td>
            <td class="small text-muted"><?= esc(mb_substr((string) $r['user_agent'], 0, 60)) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['paging' => $paging]) ?>
<?= $this->endSection() ?>

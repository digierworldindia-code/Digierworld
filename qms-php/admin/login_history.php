<?php
/**
 * Login history: logins, failures, lockouts, logouts and session ends (append-only).
 */
require dirname(__DIR__) . '/includes/init.php';
$user = require_permission('loginlog.view');
require_once QMS_ROOT . '/includes/validate.php';

const LOGIN_EVENTS = ['LOGIN_SUCCESS', 'LOGIN_FAILED', 'LOGOUT', 'ACCOUNT_LOCKED', 'ACCOUNT_UNLOCKED', 'PASSWORD_CHANGED', 'PASSWORD_RESET', 'SESSION_TIMEOUT', 'SESSION_REVOKED'];

$f      = ['from' => get('from'), 'to' => get('to'), 'user' => get('user'), 'event' => get('event'), 'ip' => get('ip')];
$paging = paging(50);
$where  = [];
$params = [];
if (validate_date($f['from'])) {
    $where[]  = 'created_at >= ?';
    $params[] = plant_to_utc($f['from'] . ' 00:00:00');
}
if (validate_date($f['to'])) {
    $where[]  = 'created_at < ?';
    $params[] = plant_to_utc((new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00');
}
if ($f['user'] !== '') {
    $where[]  = "username_attempted LIKE ? ESCAPE '!'";
    $params[] = db_like($f['user'], 'after');
}
if (in_array($f['event'], LOGIN_EVENTS, true)) {
    $where[]  = 'event = ?';
    $params[] = $f['event'];
}
if ($f['ip'] !== '') {
    $where[]  = "ip_address LIKE ? ESCAPE '!'";
    $params[] = db_like($f['ip'], 'after');
}
$sqlWhere        = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$paging['total'] = (int) db_value('SELECT COUNT(*) FROM login_logs' . $sqlWhere, $params);
$rows = db_all('SELECT * FROM login_logs' . $sqlWhere . ' ORDER BY id DESC LIMIT ? OFFSET ?', [...$params, $paging['per_page'], $paging['offset']]);

$page_title = 'Login history';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Login history</h1><p class="qms-sub">Logins, failures, lockouts, logouts and session timeouts (append-only).</p></div>
</div>
<form class="qms-filter row g-2 align-items-end" method="get" action="<?= url('admin/login_history.php') ?>">
    <div class="col-6 col-md-2"><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="user">Username</label><input class="form-control" id="user" name="user" value="<?= e($f['user']) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="event">Event</label>
        <select class="form-select" id="event" name="event"><option value="">All</option>
        <?php foreach (LOGIN_EVENTS as $ev): ?><option<?= $f['event'] === $ev ? ' selected' : '' ?>><?= e($ev) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="ip">IP</label><input class="form-control" id="ip" name="ip" value="<?= e($f['ip']) ?>"></div>
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
            <td class="num text-nowrap"><?= e(plant_dt($r['created_at'], 'd-m-Y H:i:s')) ?></td>
            <td><?= e($r['username_attempted']) ?></td>
            <td><span class="qms-badge qms-badge--<?= $tone ?>"><?= e(str_replace('_', ' ', $r['event'])) ?></span></td>
            <td class="small"><?= e($r['failure_reason'] ?? '') ?></td>
            <td class="num"><?= e($r['ip_address']) ?></td>
            <td class="small text-muted"><?= e(mb_substr((string) $r['user_agent'], 0, 60)) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?php require QMS_ROOT . '/includes/views/pager.php'; ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';

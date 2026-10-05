<?php
/**
 * Read-only audit trail. Nothing in the application can change or delete entries.
 */
require dirname(__DIR__) . '/includes/init.php';
$user = require_permission('audit.view');
require_once QMS_ROOT . '/includes/validate.php';

$f      = ['from' => get('from'), 'to' => get('to'), 'user' => get('user'), 'module' => get('module'), 'action' => get('action'), 'q' => get('q'), 'record' => get('record')];
$paging = paging(50);
$where  = [];
$params = [];
if (validate_date($f['from'])) {
    $where[]  = 'a.created_at >= ?';
    $params[] = plant_to_utc($f['from'] . ' 00:00:00');
}
if (validate_date($f['to'])) {
    $where[]  = 'a.created_at < ?';
    $params[] = plant_to_utc((new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00');
}
if ($f['user'] !== '') {
    $where[]  = "a.username LIKE ? ESCAPE '!'";
    $params[] = db_like($f['user'], 'after');
}
if ($f['module'] !== '') {
    $where[]  = 'a.module = ?';
    $params[] = $f['module'];
}
if ($f['action'] !== '') {
    $where[]  = 'a.action = ?';
    $params[] = strtoupper($f['action']);
}
if ($f['q'] !== '') {
    $where[]  = "a.record_ref LIKE ? ESCAPE '!'";
    $params[] = db_like($f['q']);
}
if (ctype_digit($f['record'])) {
    $where[]  = 'a.record_id = ?';
    $params[] = (int) $f['record'];
}
$sqlWhere        = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$paging['total'] = (int) db_value('SELECT COUNT(*) FROM audit_logs a' . $sqlWhere, $params);
$rows    = db_all('SELECT a.*, e.employee_code, e.full_name FROM audit_logs a LEFT JOIN employees e ON e.id = a.employee_id' . $sqlWhere
    . ' ORDER BY a.id DESC LIMIT ? OFFSET ?', [...$params, $paging['per_page'], $paging['offset']]);
$modules = db_column('SELECT DISTINCT module FROM audit_logs ORDER BY module');
$actions = db_column('SELECT DISTINCT action FROM audit_logs ORDER BY action');
$pretty  = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $data = json_decode($json, true);

    return is_array($data) ? (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $json;
};

$page_title = 'Audit trail';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Audit trail</h1><p class="qms-sub">Every change, workflow action, print and export. Append-only: entries can never be edited or deleted.</p></div>
</div>
<form class="qms-filter row g-2 align-items-end" method="get" action="<?= url('admin/audit.php') ?>">
    <div class="col-6 col-md-2"><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="user">User</label><input class="form-control" id="user" name="user" value="<?= e($f['user']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="module">Module</label>
        <select class="form-select" id="module" name="module"><option value="">All</option>
        <?php foreach ($modules as $m): ?><option<?= $f['module'] === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="action">Action</label>
        <select class="form-select" id="action" name="action"><option value="">All</option>
        <?php foreach ($actions as $a): ?><option<?= $f['action'] === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="q">Record</label><input class="form-control" id="q" name="q" placeholder="Report no., code…" value="<?= e($f['q']) ?>">
        <?php if (ctype_digit($f['record'])): ?><input type="hidden" name="record" value="<?= (int) $f['record'] ?>"><div class="form-text">Record #<?= (int) $f['record'] ?> only</div><?php endif ?></div>
    <div class="col-12 text-end"><button class="btn btn-outline-primary" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table align-top">
    <thead><tr><th>Date / time</th><th>User</th><th>Action</th><th>Record</th><th>Previous value</th><th>New value</th><th>IP / device</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="7" class="qms-empty"><?= qms_icon('bi-journal-text') ?>No audit entries match the filter.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="num text-nowrap"><?= e(plant_dt($r['created_at'], 'd-m-Y')) ?><br><span class="text-muted"><?= e(plant_dt($r['created_at'], 'H:i:s')) ?></span></td>
            <td><?= e($r['username'] ?? 'system') ?><?php if ($r['employee_code'] !== null): ?><div class="small text-muted"><?= e($r['employee_code'] . ' ' . $r['full_name']) ?></div><?php endif ?></td>
            <td><strong><?= e($r['action']) ?></strong><div class="small text-muted"><?= e($r['module']) ?></div></td>
            <td><?= e($r['record_ref'] ?? '') ?><?php if ($r['record_id'] !== null): ?><div class="small text-muted">#<?= e($r['record_id']) ?></div><?php endif ?></td>
            <td><pre class="qms-diff"><?= e($pretty($r['previous_value'])) ?></pre></td>
            <td><pre class="qms-diff"><?= e($pretty($r['new_value'])) ?></pre></td>
            <td class="small"><?= e($r['ip_address'] ?? '') ?><div class="text-muted text-truncate" title="<?= e($r['user_agent'] ?? '') ?>"><?= e(mb_substr((string) $r['user_agent'], 0, 40)) ?></div></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?php require QMS_ROOT . '/includes/views/pager.php'; ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';

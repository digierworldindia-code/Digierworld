<?php
$pretty = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $data = json_decode($json, true);

    return is_array($data) ? (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $json;
};
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Audit trail</h1><p class="qms-sub">Every change, workflow action, print and export. Append-only: entries can never be edited or deleted.</p></div>
</div>
<form class="qms-filter row g-2 align-items-end" method="get">
    <div class="col-6 col-md-2"><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= esc($filters['from'] ?? '', 'attr') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= esc($filters['to'] ?? '', 'attr') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="user">User</label><input class="form-control" id="user" name="user" value="<?= esc($filters['user'] ?? '', 'attr') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label" for="module">Module</label>
        <select class="form-select" id="module" name="module"><option value="">All</option>
        <?php foreach ($modules as $m): ?><option<?= ($filters['module'] ?? '') === $m ? ' selected' : '' ?>><?= esc($m) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="action">Action</label>
        <select class="form-select" id="action" name="action"><option value="">All</option>
        <?php foreach ($actions as $a): ?><option<?= ($filters['action'] ?? '') === $a ? ' selected' : '' ?>><?= esc($a) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label" for="q">Record</label><input class="form-control" id="q" name="q" placeholder="Report no., code…" value="<?= esc($filters['q'] ?? '', 'attr') ?>"><?php if (ctype_digit($filters['record'] ?? '')): ?><input type="hidden" name="record" value="<?= (int) $filters['record'] ?>"><div class="form-text">Record #<?= (int) $filters['record'] ?> only</div><?php endif ?></div>
    <div class="col-12 text-end"><button class="btn btn-outline-primary" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table align-top">
    <thead><tr><th>Date / time</th><th>User</th><th>Action</th><th>Record</th><th>Previous value</th><th>New value</th><th>IP / device</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="7" class="qms-empty"><?= qms_icon('bi-journal-text') ?>No audit entries match the filter.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="num text-nowrap"><?= esc(plant_dt($r['created_at'], 'd-m-Y')) ?><br><span class="text-muted"><?= esc(plant_dt($r['created_at'], 'H:i:s')) ?></span></td>
            <td><?= esc($r['username'] ?? 'system') ?><?php if ($r['employee_code'] !== null): ?><div class="small text-muted"><?= esc($r['employee_code'] . ' ' . $r['full_name']) ?></div><?php endif ?></td>
            <td><strong><?= esc($r['action']) ?></strong><div class="small text-muted"><?= esc($r['module']) ?></div></td>
            <td><?= esc($r['record_ref'] ?? '') ?><?php if ($r['record_id'] !== null): ?><div class="small text-muted">#<?= esc($r['record_id']) ?></div><?php endif ?></td>
            <td><pre class="qms-diff"><?= esc($pretty($r['previous_value'])) ?></pre></td>
            <td><pre class="qms-diff"><?= esc($pretty($r['new_value'])) ?></pre></td>
            <td class="small"><?= esc($r['ip_address'] ?? '') ?><div class="text-muted text-truncate" title="<?= esc($r['user_agent'] ?? '', 'attr') ?>"><?= esc(mb_substr((string) $r['user_agent'], 0, 40)) ?></div></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['paging' => $paging]) ?>
<?= $this->endSection() ?>

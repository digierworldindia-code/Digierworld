<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<a href="<?= site_url('admin/audit/verify') ?>" class="btn btn-outline-dark btn-sm"><i class="bi bi-link-45deg"></i> Verify hash chain</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-3"><select name="user" class="form-select form-select-sm" aria-label="User"><option value="">All users</option>
        <?php foreach ($users as $u): ?><option value="<?= $u['user_id'] ?>" <?= $f['user'] === (int) $u['user_id'] ? 'selected' : '' ?>><?= esc($u['full_name']) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><input name="action" value="<?= esc($f['action']) ?>" class="form-control form-control-sm" placeholder="Action e.g. billing." aria-label="Action"></div>
    <div class="col-md-2"><select name="entity" class="form-select form-select-sm" aria-label="Entity"><option value="">All records</option>
        <?php foreach ($entities as $e): ?><option <?= $f['entity'] === $e ? 'selected' : '' ?>><?= esc($e) ?></option><?php endforeach ?></select></div>
    <div class="col-md-1"><input name="id" value="<?= $f['id'] ?: '' ?>" class="form-control form-control-sm" placeholder="ID" aria-label="Record id"></div>
    <div class="col-md-2"><input type="date" name="from" value="<?= esc($f['from'] ?? '') ?>" class="form-control form-control-sm" aria-label="From"></div>
    <div class="col-md-2"><input type="date" name="to" value="<?= esc($f['to'] ?? '') ?>" class="form-control form-control-sm" aria-label="To"></div>
    <div class="col-12 text-end"><button class="btn btn-dark btn-sm">Filter</button></div>
</form>
<div class="table-card"><div class="table-responsive">
<table class="table table-sm table-hover align-middle small">
    <thead><tr><th>#</th><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Summary</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= site_url('admin/audit/' . $r['id']) ?>"><?= $r['id'] ?></a></td>
            <td class="text-nowrap"><?= dmy($r['occurred_at'], true) ?></td>
            <td><?= esc($r['full_name'] ?? ucfirst($r['actor_type'])) ?></td>
            <td><code><?= esc($r['action']) ?></code></td>
            <td><?= $r['entity_type'] ? esc($r['entity_type']) . ' #' . (int) $r['entity_id'] : '—' ?></td>
            <td><?= esc($r['summary']) ?></td>
            <td class="mono"><?= esc($r['ip_address']) ?></td>
        </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-clock-history"></i>No matching activity.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>

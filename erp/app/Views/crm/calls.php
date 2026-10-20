<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-6 col-md-2"><input type="date" class="form-control form-control-sm" name="from" value="<?= esc($from) ?>" aria-label="From"></div>
    <div class="col-6 col-md-2"><input type="date" class="form-control form-control-sm" name="to" value="<?= esc($to) ?>" aria-label="To"></div>
    <div class="col-6 col-md-3"><select class="form-select form-select-sm" name="outcome" aria-label="Outcome"><option value="">Any outcome</option><?php foreach ($outcomes as $k => $l): ?><option value="<?= $k ?>" <?= $outcome === $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
    <?php if (count($users) > 1): ?><div class="col-6 col-md-3"><select class="form-select form-select-sm" name="caller" aria-label="Caller"><option value="">Anyone</option><?php foreach ($users as $id => $n): ?><option value="<?= $id ?>" <?= $caller === (int) $id ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select></div><?php endif ?>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Show</button></div>
</form>
<div class="table-card"><div class="table-responsive"><table class="table table-hover small align-middle">
    <thead><tr><th>When</th><th>Lead</th><th>Direction</th><th>Outcome</th><th>Notes</th><th>Next</th><th>By</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td class="text-nowrap"><?= dmy($r['called_at'], true) ?></td><td><a href="<?= site_url('crm/leads/' . $r['lead_id']) ?>"><?= esc($r['company_name']) ?></a><div class="mono text-muted"><?= esc($r['lead_code']) ?></div></td>
        <td><?= $r['direction'] === 'inbound' ? '<i class="bi bi-telephone-inbound"></i> In' : '<i class="bi bi-telephone-outbound"></i> Out' ?></td>
        <td><?= esc($outcomes[$r['outcome']] ?? $r['outcome']) ?><?= (int) $r['is_connected'] === 1 ? ' <span class="badge badge-success">connected</span>' : '' ?></td>
        <td><?= esc(mb_strimwidth((string) $r['notes'], 0, 90, '…')) ?></td><td class="text-nowrap"><?= $r['next_action_at'] ? dmy($r['next_action_at'], true) : '' ?></td><td class="text-muted"><?= esc((string) $r['caller']) ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-telephone"></i>No calls in this period.</div></td></tr><?php endif ?>
</tbody></table></div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>

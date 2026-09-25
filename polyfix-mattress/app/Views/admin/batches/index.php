<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var list<array> $warehouses */ ?>
<div class="page-head"><div><h1>Manufacturing batches</h1><p>A batch is one production run at one plant. Units are serialised against it.</p></div></div>
<div class="row g-3">
    <div class="<?= $ctx->can('batch:write') ? 'col-xl-8' : 'col-12' ?>">
        <div class="panel">
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Batch</th><th>Made on</th><th>Plant</th><th class="num">Planned</th><th class="num">Serialised</th><th>Supervisor</th></tr></thead>
                <tbody>
                <?php foreach ($list['rows'] as $b): ?>
                <tr><td><a class="mono fw-semibold" href="<?= site_url('admin/batches/' . $b['id']) ?>"><?= esc($b['batch_code']) ?></a></td>
                    <td class="small"><?= esc(local_date($b['manufactured_on'])) ?></td><td class="small"><?= esc($b['warehouse']) ?></td>
                    <td class="num"><?= (int) $b['planned_quantity'] ?></td><td class="num"><?= (int) $b['serialised'] ?></td>
                    <td class="small"><?= esc($b['line_supervisor'] ?? '—') ?></td></tr>
                <?php endforeach ?>
                <?php if ($list['rows'] === []): ?><tr><td colspan="6" class="table-empty">No batches yet.</td></tr><?php endif ?>
                </tbody>
            </table></div>
            <?= view('partials/pager', ['p' => $list]) ?>
        </div>
    </div>
    <?php if ($ctx->can('batch:write')): ?>
    <div class="col-xl-4">
        <form class="panel" method="post" action="<?= site_url('admin/batches') ?>">
            <div class="panel-head"><h2>New batch</h2></div>
            <div class="panel-body">
                <?= csrf_field() ?>
                <div class="mb-3"><label class="form-label" for="warehouse_id">Plant</label>
                    <select class="form-select<?= invalid('warehouse_id') ?>" id="warehouse_id" name="warehouse_id" required>
                    <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"<?= old('warehouse_id') === $w['id'] ? ' selected' : '' ?>><?= esc($w['name']) ?> (<?= esc($w['code']) ?>)</option><?php endforeach ?></select><?= field_error('warehouse_id') ?></div>
                <div class="row g-2 mb-3">
                    <div class="col-7"><label class="form-label" for="manufactured_on">Made on</label><input class="form-control<?= invalid('manufactured_on') ?>" type="date" id="manufactured_on" name="manufactured_on" required value="<?= esc(old('manufactured_on', local_time(utc_now(), 'Y-m-d')), 'attr') ?>"><?= field_error('manufactured_on') ?></div>
                    <div class="col-5"><label class="form-label" for="planned_quantity">Planned</label><input class="form-control<?= invalid('planned_quantity') ?>" type="number" min="1" id="planned_quantity" name="planned_quantity" required value="<?= esc(old('planned_quantity', ''), 'attr') ?>"><?= field_error('planned_quantity') ?></div>
                </div>
                <div class="mb-3"><label class="form-label" for="line_supervisor">Line supervisor</label><input class="form-control" id="line_supervisor" name="line_supervisor" maxlength="120" value="<?= esc(old('line_supervisor', ''), 'attr') ?>"></div>
                <div class="mb-3"><label class="form-label" for="quality_checked_by">Quality checked by</label><input class="form-control" id="quality_checked_by" name="quality_checked_by" maxlength="120" value="<?= esc(old('quality_checked_by', ''), 'attr') ?>"></div>
                <div class="mb-3"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000"><?= esc(old('notes', '')) ?></textarea></div>
                <button class="btn btn-primary" type="submit">Create batch</button>
            </div>
        </form>
    </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

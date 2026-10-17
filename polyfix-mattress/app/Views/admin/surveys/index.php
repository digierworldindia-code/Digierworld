<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/**
 * Every dealer stock survey. History, never overwritten: each visit is its
 * own row and keeps its own report.
 *
 * @var array                     $list
 * @var string|null               $status
 * @var string|null               $agent
 * @var list<array<string,mixed>> $agents
 */
?>
<div class="page-head">
    <div>
        <h1>Stock surveys</h1>
        <p>Field agents check a dealer's shelf against the records every six months. Each visit keeps its own report.</p>
    </div>
</div>

<form class="panel panel-body mb-3" method="get" action="<?= site_url('admin/surveys') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-sm-4">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status" data-autosubmit>
                <option value="">Any</option>
                <?php foreach (['IN_PROGRESS', 'SUBMITTED', 'REVIEWED', 'ABANDONED'] as $key): ?>
                    <option value="<?= $key ?>"<?= $status === $key ? ' selected' : '' ?>><?= esc(humanise($key)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-sm-5">
            <label class="form-label" for="agent">Agent</label>
            <select class="form-select" id="agent" name="agent" data-autosubmit>
                <option value="">Any</option>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= esc($a['id'], 'attr') ?>"<?= $agent === $a['id'] ? ' selected' : '' ?>><?= esc($a['full_name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-sm-3">
            <a class="btn btn-light w-100" href="<?= site_url('admin/surveys') ?>">Clear</a>
        </div>
    </div>
</form>

<div class="panel">
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr>
            <th>Reference</th><th>Dealer</th><th>Agent</th><th>Visited</th>
            <th class="num">On record</th><th class="num">Found</th><th class="num">To look into</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $s): ?>
            <tr>
                <td><a class="mono fw-semibold" href="<?= site_url('admin/surveys/' . $s['id']) ?>"><?= esc($s['reference']) ?></a></td>
                <td><?= esc($s['dealer']) ?><span class="d-block small text-muted"><?= esc($s['city'] ?? '') ?></span></td>
                <td class="small"><?= esc($s['agent']) ?></td>
                <td class="small"><?= esc(local_date($s['submitted_at'] ?? $s['started_at'])) ?></td>
                <td class="num"><?= (int) $s['system_total'] ?></td>
                <td class="num"><?= (int) $s['physically_found'] ?></td>
                <td class="num"><?= (int) $s['discrepancy_count'] > 0
                    ? '<span class="text-danger fw-semibold">' . (int) $s['discrepancy_count'] . '</span>'
                    : '0' ?></td>
                <td><?= pill($s['status']) ?></td>
            </tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?>
            <tr><td colspan="8" class="table-empty">No surveys yet. Assign a dealer to a field agent to start.</td></tr>
        <?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>

<?php
/**
 * Master data: overview of all lists, or one list with ?type=parts (machines, employees …).
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('master.view');
require_once QMS_ROOT . '/includes/masters.php';

$type = get('type');

if ($type === '') {
    $definitions = master_definitions();
    $counts      = master_counts();
    $page_title  = 'Master data';
    require QMS_ROOT . '/includes/layout/header.php';
    ?>
<div class="qms-page-head">
    <div><h1>Master data</h1><p class="qms-sub">Reference lists used by templates and inspections. Codes never change; unused rows are retired, not deleted.</p></div>
</div>
<div class="qms-tiles">
    <?php foreach ($definitions as $slug => $def): ?>
        <a class="qms-tile" href="<?= url('masters.php?type=' . urlencode($slug)) ?>">
            <?= qms_icon($def['icon']) ?>
            <strong><?= e($def['title']) ?></strong>
            <span><?= number_format($counts[$slug] ?? 0) ?> active</span>
        </a>
    <?php endforeach ?>
    <?php if (can('gauge.view')): ?>
        <a class="qms-tile" href="<?= url('gauges.php') ?>"><?= qms_icon('bi-rulers') ?><strong>Gauges</strong><span>Instruments and calibration</span></a>
    <?php endif ?>
</div>
    <?php
    require QMS_ROOT . '/includes/layout/footer.php';
    exit;
}

$def     = master_definition($type);
$paging  = paging(30);
$q       = get('q');
$status  = in_array(get('status'), ['active', 'retired', 'all'], true) ? get('status') : 'active';
$rows    = master_list($type, $q, $status, $paging);
$options = master_select_options($type, false);
$canEdit = can($def['permission']);
$back    = current_page();

$page_title = $def['title'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1><?= e($def['title']) ?></h1><p class="qms-sub"><?= e($def['help']) ?></p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= url('masters.php') ?>"><?= qms_icon('bi-collection') ?> All masters</a>
        <?php if ($canEdit): ?><a class="btn btn-primary btn-lg" href="<?= url('master_edit.php?type=' . urlencode($type)) ?>"><?= qms_icon('bi-plus-lg') ?> New <?= e($def['singular']) ?></a><?php endif ?>
    </div>
</div>
<form class="qms-filter row g-2 align-items-end" method="get" action="<?= url('masters.php') ?>">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <div class="col-md-6"><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="status">Show</label>
        <select class="form-select" id="status" name="status">
            <?php foreach (['active' => 'Active', 'retired' => 'Retired', 'all' => 'All'] as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach ?>
        </select></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr>
        <?php foreach ($def['list'] as $column): ?><th><?= e($def['fields'][$column]['label']) ?></th><?php endforeach ?>
        <th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="<?= count($def['list']) + 2 ?>" class="qms-empty"><?= qms_icon($def['icon']) ?>Nothing here yet.</td></tr><?php endif ?>
    <?php foreach ($rows as $row): ?>
        <tr>
            <?php foreach ($def['list'] as $i => $column): ?>
                <td><?= $i === 0 ? '<strong>' . master_cell($def, $options, $column, $row) . '</strong>' : master_cell($def, $options, $column, $row) ?></td>
            <?php endforeach ?>
            <td><?= active_badge($row['is_active']) ?></td>
            <td class="text-end text-nowrap">
                <?php if ($canEdit): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('master_edit.php?type=' . urlencode($type) . '&id=' . (int) $row['id'])) ?>"><?= qms_icon('bi-pencil') ?> Edit</a>
                    <form class="d-inline" method="post" action="<?= e(url('master_edit.php?type=' . urlencode($type) . '&id=' . (int) $row['id'])) ?>"
                          data-confirm="<?= (int) $row['is_active'] === 1 ? 'Retire this ' . e($def['singular']) . '? It stays on existing reports.' : 'Re-activate this ' . e($def['singular']) . '?' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="back" value="<?= e($back) ?>">
                        <button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $row['is_active'] === 1 ? qms_icon('bi-archive') . ' Retire' : qms_icon('bi-arrow-counterclockwise') . ' Re-activate' ?></button>
                    </form>
                <?php endif ?>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?php require QMS_ROOT . '/includes/views/pager.php'; ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';

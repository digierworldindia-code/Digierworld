<?php
/**
 * Operator landing page: start tiles, returned reports, drafts, open day sheets.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('inspection.create');
require_once QMS_ROOT . '/includes/inspections.php';

$options   = insp_wizard_options();
$lists     = insp_home_lists($user);
$current   = $options['current'];
$typeIcons = ['SECTIONED' => 'bi-tools', 'METHOD_MATRIX' => 'bi-rulers', 'SHIFT_GRID' => 'bi-grid-3x3'];
$row       = static function (array $r, string $hint = ''): string {
    $label = $r['report_no'] !== null ? $r['report_no'] . ((int) $r['revision_no'] > 0 ? ' Rev ' . $r['revision_no'] : '') : $r['type_code'] . ' draft #' . $r['id'];
    $page  = in_array($r['status'], ['DRAFT', 'RETURNED'], true) ? 'inspection_edit.php' : 'inspection.php';

    return '<a class="list-group-item list-group-item-action qms-list-item" href="' . e(url($page . '?id=' . (int) $r['id'])) . '">'
        . '<div class="d-flex justify-content-between gap-2 flex-wrap"><strong>' . e($label) . '</strong>' . status_badge($r['status']) . '</div>'
        . '<div class="small text-muted">' . e($r['type_name']) . ' · ' . e($r['part_number']) . ' · ' . e($r['machine_code']) . ' · ' . e(plant_date($r['inspection_date']))
        . ($r['shift_code'] !== null ? ' · Shift ' . e($r['shift_code']) : '') . ($hint !== '' ? ' · ' . e($hint) : '') . '</div></a>';
};

$page_title = 'Home';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div>
        <h1>Hello, <?= e($user['display_name']) ?></h1>
        <p class="qms-sub">Production date <?= e(plant_date($current['date'])) ?><?= $current['shift'] !== null ? ' · Shift ' . e($current['shift']['code']) : '' ?></p>
    </div>
</div>

<h2 class="h5 mb-2">Start an inspection</h2>
<div class="qms-tiles mb-4">
    <?php foreach ($options['reportTypes'] as $type): ?>
        <?php if ((int) $type['template_count'] === 0) { continue; } ?>
        <a class="qms-tile" href="<?= url('inspection_new.php?type=' . (int) $type['id']) ?>">
            <?= qms_icon($typeIcons[$type['layout']] ?? 'bi-clipboard-check') ?>
            <strong><?= e($type['name']) ?></strong>
            <span><?= $type['layout'] === 'SHIFT_GRID' ? 'Open or join today\'s sheet for a machine' : 'New ' . e($type['code']) . ' report' ?></span>
        </a>
    <?php endforeach ?>
    <?php if (can('inspection.verify_production', 'inspection.verify_quality', 'inspection.approve_qa')): ?>
        <a class="qms-tile" href="<?= url('approvals.php') ?>">
            <?= qms_icon('bi-pen') ?>
            <strong>Approvals <?= approval_count() > 0 ? '<span class="qms-count">' . approval_count() . '</span>' : '' ?></strong>
            <span>Reports waiting for your signature</span>
        </a>
    <?php endif ?>
</div>

<div class="row g-3">
    <?php if ($lists['returned'] !== []): ?>
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header"><?= qms_icon('bi-arrow-return-left') ?> Returned to you for correction</div>
                <div class="list-group list-group-flush"><?php foreach ($lists['returned'] as $r): ?><?= $row($r) ?><?php endforeach ?></div>
            </div>
        </div>
    <?php endif ?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><?= qms_icon('bi-grid-3x3') ?> Open In-Process sheets</div>
            <div class="list-group list-group-flush">
                <?php foreach ($lists['sheets'] as $r): ?><?= $row($r, $r['round_count'] . ' inspection(s)') ?><?php endforeach ?>
                <?php if ($lists['sheets'] === []): ?><div class="qms-empty"><?= qms_icon('bi-grid-3x3') ?>No open day sheets.</div><?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><?= qms_icon('bi-pencil-square') ?> Your drafts</div>
            <div class="list-group list-group-flush">
                <?php foreach ($lists['drafts'] as $r): ?><?= $row($r) ?><?php endforeach ?>
                <?php if ($lists['drafts'] === []): ?><div class="qms-empty"><?= qms_icon('bi-pencil-square') ?>No drafts.</div><?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between"><span><?= qms_icon('bi-send-check') ?> Recently submitted by you</span>
                <a class="small" href="<?= url('inspections.php') ?>">All inspections</a></div>
            <div class="list-group list-group-flush">
                <?php foreach ($lists['recent'] as $r): ?><?= $row($r) ?><?php endforeach ?>
                <?php if ($lists['recent'] === []): ?><div class="qms-empty"><?= qms_icon('bi-send') ?>Nothing submitted yet.</div><?php endif ?>
            </div>
        </div>
    </div>
</div>
<?php require QMS_ROOT . '/includes/layout/footer.php';

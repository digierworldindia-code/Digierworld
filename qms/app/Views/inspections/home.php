<?php
/**
 * Operator landing page.
 *
 * @var array<string, list<array<string, mixed>>> $lists
 * @var list<array<string, mixed>>                $reportTypes
 * @var array<string, mixed>                      $current
 */
$typeIcons = ['SECTIONED' => 'bi-tools', 'METHOD_MATRIX' => 'bi-rulers', 'SHIFT_GRID' => 'bi-grid-3x3'];
$row = static function (array $r, string $hint = ''): string {
    $label = $r['report_no'] !== null ? $r['report_no'] . ((int) $r['revision_no'] > 0 ? ' Rev ' . $r['revision_no'] : '') : $r['type_code'] . ' draft #' . $r['id'];

    return '<a class="list-group-item list-group-item-action qms-list-item" href="' . site_url('inspections/' . $r['id'] . (in_array($r['status'], ['DRAFT', 'RETURNED'], true) ? '/edit' : '')) . '">'
        . '<div class="d-flex justify-content-between gap-2 flex-wrap"><strong>' . esc($label) . '</strong>' . status_badge($r['status']) . '</div>'
        . '<div class="small text-muted">' . esc($r['type_name']) . ' · ' . esc($r['part_number']) . ' · ' . esc($r['machine_code']) . ' · ' . esc(plant_date($r['inspection_date']))
        . ($r['shift_code'] !== null ? ' · Shift ' . esc($r['shift_code']) : '') . ($hint !== '' ? ' · ' . esc($hint) : '') . '</div></a>';
};
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div>
        <h1>Hello, <?= esc($currentUser['display_name']) ?></h1>
        <p class="qms-sub">Production date <?= esc(plant_date($current['date'])) ?><?= $current['shift'] !== null ? ' · Shift ' . esc($current['shift']['code']) : '' ?></p>
    </div>
</div>

<h2 class="h5 mb-2">Start an inspection</h2>
<div class="qms-tiles mb-4">
    <?php foreach ($reportTypes as $type): ?>
        <?php if ((int) $type['template_count'] === 0) { continue; } ?>
        <a class="qms-tile" href="<?= site_url('inspections/new?type=' . $type['id']) ?>">
            <?= qms_icon($typeIcons[$type['layout']] ?? 'bi-clipboard-check') ?>
            <strong><?= esc($type['name']) ?></strong>
            <span><?= $type['layout'] === 'SHIFT_GRID' ? 'Open or join today\'s sheet for a machine' : 'New ' . esc($type['code']) . ' report' ?></span>
        </a>
    <?php endforeach ?>
    <?php if (can('inspection.verify_production', 'inspection.verify_quality', 'inspection.approve_qa')): ?>
        <a class="qms-tile" href="<?= site_url('approvals') ?>">
            <?= qms_icon('bi-pen') ?>
            <strong>Approvals <?= service('navigation')->approvalCount() > 0 ? '<span class="qms-count">' . service('navigation')->approvalCount() . '</span>' : '' ?></strong>
            <span>Reports waiting for your signature</span>
        </a>
    <?php endif ?>
</div>

<div class="row g-3">
    <?php if ($lists['returned'] !== []): ?>
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header"><?= qms_icon('bi-arrow-return-left') ?> Returned to you for correction</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($lists['returned'] as $r): ?><?= $row($r) ?><?php endforeach ?>
                </div>
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
                <a class="small" href="<?= site_url('inspections') ?>">All inspections</a></div>
            <div class="list-group list-group-flush">
                <?php foreach ($lists['recent'] as $r): ?><?= $row($r) ?><?php endforeach ?>
                <?php if ($lists['recent'] === []): ?><div class="qms-empty"><?= qms_icon('bi-send') ?>Nothing submitted yet.</div><?php endif ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

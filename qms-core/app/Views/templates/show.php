<?php
/**
 * Template editor / viewer.
 *
 * @var array<string, mixed>        $template
 * @var list<array<string, mixed>>  $structure
 * @var array{parts: list<int>, machines: list<int>} $mapping
 * @var bool                        $editable
 */
$statusTone = ['DRAFT' => 'muted', 'PUBLISHED' => 'pass', 'RETIRED' => 'muted'][$template['status']];
$canManage  = can('template.manage');
$canPublish = can('template.publish');
$spec       = static function (array $p): string {
    $unit = $p['unit_symbol'] !== null ? ' ' . $p['unit_symbol'] : '';
    $dp   = (int) $p['decimal_places'];

    return match ($p['observation_type']) {
        'NUMERIC', 'PERCENTAGE' => match (true) {
            $p['lsl'] !== null && $p['usl'] !== null => qms_decimal($p['lsl'], $dp) . ' – ' . qms_decimal($p['usl'], $dp) . $unit,
            $p['lsl'] !== null                       => '≥ ' . qms_decimal($p['lsl'], $dp) . $unit,
            $p['usl'] !== null                       => '≤ ' . qms_decimal($p['usl'], $dp) . $unit,
            default                                  => 'record only' . $unit,
        },
        'OK_NOT_OK', 'VISUAL' => 'OK',
        'GO_NO_GO'            => 'GO',
        'DATE'                => $p['date_rule'] === 'ON_OR_AFTER_INSPECTION_DATE' ? 'not before inspection date' : 'date',
        default               => 'record',
    };
};
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div>
        <h1><?= esc($template['template_code']) ?> <span class="text-muted">v<?= (int) $template['version'] ?></span></h1>
        <p class="qms-sub"><?= esc($template['name']) ?> · <?= esc($template['type_name']) ?> ·
            <span class="qms-badge qms-badge--<?= $statusTone ?>"><?= esc($template['status']) ?></span>
            <?php if ($template['status'] === 'PUBLISHED'): ?> since <?= esc(plant_dt($template['published_at'])) ?> by <?= esc($template['published_by_name'] ?? '') ?><?php endif ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= site_url('templates') ?>"><?= qms_icon('bi-arrow-left') ?> Templates</a>
        <a class="btn btn-outline-primary" href="<?= site_url('templates/' . $template['id'] . '/preview') ?>"><?= qms_icon('bi-eye') ?> Preview</a>
        <?php if ($canManage): ?>
            <form method="post" action="<?= site_url('templates/' . $template['id'] . '/new-version') ?>" data-confirm="Create a new draft version from v<?= (int) $template['version'] ?>?">
                <?= csrf_field() ?><button class="btn btn-outline-primary" type="submit"><?= qms_icon('bi-files') ?> New version</button></form>
        <?php endif ?>
        <?php if ($template['status'] === 'DRAFT' && $canPublish): ?>
            <form method="post" action="<?= site_url('templates/' . $template['id'] . '/publish') ?>" data-confirm="Publish this version? It becomes read-only and replaces the currently published version of <?= esc($template['template_code'], 'attr') ?>.">
                <?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= qms_icon('bi-send-check') ?> Publish</button></form>
        <?php elseif ($template['status'] === 'PUBLISHED' && $canPublish): ?>
            <form method="post" action="<?= site_url('templates/' . $template['id'] . '/retire') ?>" data-confirm="Retire this template? New inspections can no longer use it.">
                <?= csrf_field() ?><button class="btn btn-outline-danger" type="submit"><?= qms_icon('bi-archive') ?> Retire</button></form>
        <?php endif ?>
    </div>
</div>

<?php if ($template['status'] !== 'DRAFT'): ?>
    <div class="alert alert-primary d-flex gap-2"><?= qms_icon('bi-lock-fill', 'mt-1') ?>
        <div>This version is a controlled document and cannot be changed (reports refer to it). Use <strong>New version</strong> to change specifications; part and machine mapping can still be edited<?= $template['status'] === 'RETIRED' ? ' on published versions' : '' ?>.</div></div>
<?php endif ?>

<div class="row g-3">
<div class="col-xl-8">
    <?php foreach ($structure as $si => $section): ?>
    <div class="card qms-section-card mb-3" id="s<?= $section['id'] ?>">
        <div class="card-header">
            <div><?= esc($section['title']) ?><?php if ($section['description'] !== null): ?><div class="small text-muted fw-normal"><?= esc($section['description']) ?></div><?php endif ?></div>
            <?php if ($editable): ?>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach (['up' => 'bi-arrow-up', 'down' => 'bi-arrow-down'] as $dir => $icon): ?>
                    <form method="post" action="<?= site_url('templates/' . $template['id'] . '/sections/' . $section['id'] . '/move') ?>">
                        <?= csrf_field() ?><input type="hidden" name="direction" value="<?= $dir ?>">
                        <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Move section <?= $dir ?>"><?= qms_icon($icon) ?></button></form>
                <?php endforeach ?>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#se<?= $section['id'] ?>"><?= qms_icon('bi-pencil') ?> Rename</button>
                <form method="post" action="<?= site_url('templates/' . $template['id'] . '/sections/' . $section['id'] . '/delete') ?>" data-confirm="Delete this section and its parameters?">
                    <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete section"><?= qms_icon('bi-trash') ?></button></form>
            </div>
            <?php endif ?>
        </div>
        <?php if ($editable): ?>
        <div class="collapse border-bottom" id="se<?= $section['id'] ?>">
            <form class="p-3 row g-2 align-items-end" method="post" action="<?= site_url('templates/' . $template['id'] . '/sections/' . $section['id']) ?>">
                <?= csrf_field() ?>
                <div class="col-md-5"><label class="form-label">Title</label><input class="form-control" name="title" maxlength="120" value="<?= esc($section['title'], 'attr') ?>" required></div>
                <div class="col-md-5"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="255" value="<?= esc($section['description'] ?? '', 'attr') ?>"></div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Save</button></div>
            </form>
        </div>
        <?php endif ?>
        <div class="qms-table-wrap">
        <table class="table table-sm align-middle">
            <thead><tr><th>#</th><th>Parameter</th><th>Type</th><th>Specification</th><th>Method</th><th>Gauge</th><th class="text-center">Obs.</th><?php if ($editable): ?><th></th><?php endif ?></tr></thead>
            <tbody>
            <?php if ($section['parameters'] === []): ?><tr><td colspan="8" class="text-muted small p-3">No parameters yet.</td></tr><?php endif ?>
            <?php foreach ($section['parameters'] as $pi => $p): ?>
                <tr class="qms-param-row" id="p<?= $p['id'] ?>" data-param="<?= esc((string) json_encode($p), 'attr') ?>">
                    <td class="text-muted"><?= $pi + 1 ?></td>
                    <td><strong><?= esc($p['name']) ?></strong>
                        <?php if ($p['specification_text'] !== null): ?><div class="small text-muted"><?= esc($p['specification_text']) ?></div><?php endif ?>
                        <?php if ((int) $p['is_mandatory'] === 0): ?><span class="qms-badge qms-badge--muted">optional</span><?php endif ?>
                        <?php if ($p['prefill_source'] !== 'NONE'): ?><span class="qms-badge qms-badge--approved">pre-filled</span><?php endif ?></td>
                    <td class="small"><?= esc(\App\Enums\ObservationType::from($p['observation_type'])->label()) ?></td>
                    <td class="num"><?= esc($spec($p)) ?></td>
                    <td class="small"><?= esc($p['method_label'] ?? '—') ?></td>
                    <td class="small"><?= esc($p['gauge_type_name'] ?? '—') ?><?= (int) $p['gauge_required'] === 1 ? '<br><span class="qms-badge qms-badge--pending">Gauge ID required</span>' : '' ?></td>
                    <td class="text-center num"><?= (int) $p['observation_count'] ?></td>
                    <?php if ($editable): ?>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" type="button" data-edit-param aria-label="Edit parameter"><?= qms_icon('bi-pencil') ?></button>
                        <?php foreach (['up' => 'bi-arrow-up', 'down' => 'bi-arrow-down'] as $dir => $icon): ?>
                            <form class="d-inline" method="post" action="<?= site_url('templates/' . $template['id'] . '/parameters/' . $p['id'] . '/move') ?>">
                                <?= csrf_field() ?><input type="hidden" name="direction" value="<?= $dir ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Move <?= $dir ?>"><?= qms_icon($icon) ?></button></form>
                        <?php endforeach ?>
                        <form class="d-inline" method="post" action="<?= site_url('templates/' . $template['id'] . '/parameters/' . $p['id'] . '/delete') ?>" data-confirm="Delete this parameter?">
                            <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete parameter"><?= qms_icon('bi-trash') ?></button></form>
                    </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php if ($editable): ?>
            <div class="card-body pt-2"><button class="btn btn-outline-primary" type="button" data-add-param="<?= $section['id'] ?>"><?= qms_icon('bi-plus-lg') ?> Add parameter</button></div>
        <?php endif ?>
    </div>
    <?php endforeach ?>

    <?php if ($editable): ?>
    <div class="card"><div class="card-header">Add section</div><div class="card-body">
        <form class="row g-2 align-items-end" method="post" action="<?= site_url('templates/' . $template['id'] . '/sections') ?>">
            <?= csrf_field() ?>
            <div class="col-md-5"><label class="form-label required" for="new-section-title">Title</label>
                <input class="form-control<?= field_invalid($errors, 'title') ?>" id="new-section-title" name="title" maxlength="120" placeholder="e.g. Machine Readiness" required><?= field_error($errors, 'title') ?></div>
            <div class="col-md-5"><label class="form-label" for="new-section-desc">Description</label><input class="form-control" id="new-section-desc" name="description" maxlength="255"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><?= qms_icon('bi-plus-lg') ?> Add</button></div>
        </form>
    </div></div>
    <?php elseif ($structure === []): ?>
        <div class="qms-empty card"><?= qms_icon('bi-ui-checks-grid') ?>This template has no sections.</div>
    <?php endif ?>
</div>

<div class="col-xl-4">
    <div class="card mb-3"><div class="card-header">Document control</div><div class="card-body">
        <?php if ($editable): ?>
            <form method="post" action="<?= site_url('templates/' . $template['id']) ?>" novalidate data-layout="<?= esc($template['layout'], 'attr') ?>">
                <?= csrf_field() ?>
                <?= view('templates/_header_fields', ['template' => $template, 'errors' => $errors, 'disabled' => false]) ?>
                <button class="btn btn-primary mt-3" type="submit"><?= qms_icon('bi-check2') ?> Save header</button>
            </form>
            <hr>
            <form method="post" action="<?= site_url('templates/' . $template['id'] . '/delete') ?>" data-confirm="Delete this draft version completely?">
                <?= csrf_field() ?><button class="btn btn-outline-danger btn-sm" type="submit"><?= qms_icon('bi-trash') ?> Delete draft</button></form>
        <?php else: ?>
            <dl class="qms-dl">
                <dt>Document no.</dt><dd><?= esc($template['format_doc_no']) ?></dd>
                <dt>Revision no.</dt><dd><?= esc($template['format_rev_no']) ?></dd>
                <dt>Make date</dt><dd><?= esc(plant_date($template['format_made_date'])) ?></dd>
                <dt>Revision date</dt><dd><?= esc(plant_date($template['format_rev_date'])) ?: '—' ?></dd>
                <?php if ($template['planned_rounds_per_shift'] !== null): ?><dt>Inspections / shift</dt><dd><?= (int) $template['planned_rounds_per_shift'] ?></dd><?php endif ?>
                <dt>Used by</dt><dd><?= (int) $usage ?> report<?= (int) $usage === 1 ? '' : 's' ?></dd>
            </dl>
        <?php endif ?>
    </div></div>

    <div class="card mb-3" id="mapping"><div class="card-header">Applies to</div><div class="card-body">
        <?php $mapEditable = $canManage && $template['status'] !== 'RETIRED'; ?>
        <form method="post" action="<?= site_url('templates/' . $template['id'] . '/mapping') ?>">
            <?= csrf_field() ?>
            <p class="small text-muted">No ticks = all parts / all machines. The most specific published template wins (part + machine &gt; part &gt; machine &gt; all).</p>
            <?php foreach (['parts' => ['Parts', $parts, 'part_number', 'part_name'], 'machines' => ['Machines', $machines, 'machine_code', 'machine_name']] as $key => [$label, $rows, $codeCol, $nameCol]): ?>
                <label class="form-label mt-2"><?= $label ?> <span class="text-muted small">(<?= count($mapping[$key]) === 0 ? 'all' : count($mapping[$key]) . ' selected' ?>)</span></label>
                <input class="form-control form-control-sm mb-1" type="search" placeholder="Filter <?= strtolower($label) ?>…" data-filter-list="#map-<?= $key ?>" aria-label="Filter <?= strtolower($label) ?>">
                <div class="border rounded p-2 qms-scroll-y" id="map-<?= $key ?>">
                    <?php foreach ($rows as $row): $checked = in_array((int) $row['id'], $mapping[$key], true); ?>
                        <?php if ((int) $row['is_active'] === 1 || $checked): ?>
                        <div class="form-check" data-filter-text="<?= esc(strtolower($row[$codeCol] . ' ' . $row[$nameCol]), 'attr') ?>">
                            <input class="form-check-input" type="checkbox" name="<?= $key ?>[]" value="<?= $row['id'] ?>" id="<?= $key ?>-<?= $row['id'] ?>"<?= $checked ? ' checked' : '' ?><?= $mapEditable ? '' : ' disabled' ?>>
                            <label class="form-check-label" for="<?= $key ?>-<?= $row['id'] ?>"><strong><?= esc($row[$codeCol]) ?></strong> <span class="small text-muted"><?= esc($row[$nameCol]) ?></span></label>
                        </div>
                        <?php endif ?>
                    <?php endforeach ?>
                </div>
            <?php endforeach ?>
            <?php if ($mapEditable): ?><button class="btn btn-primary mt-3" type="submit"><?= qms_icon('bi-check2') ?> Save mapping</button><?php endif ?>
        </form>
    </div></div>

    <div class="card"><div class="card-header">Versions</div><ul class="list-group list-group-flush">
        <?php foreach ($versions as $ver): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <a href="<?= site_url('templates/' . $ver['id']) ?>"<?= (int) $ver['id'] === (int) $template['id'] ? ' aria-current="page" class="fw-bold"' : '' ?>>Version <?= (int) $ver['version'] ?></a>
                <span class="qms-badge qms-badge--<?= ['DRAFT' => 'muted', 'PUBLISHED' => 'pass', 'RETIRED' => 'muted'][$ver['status']] ?>"><?= esc($ver['status']) ?></span>
            </li>
        <?php endforeach ?>
    </ul></div>
</div>
</div>

<?php if ($editable): ?>
<div class="modal fade" id="paramModal" tabindex="-1" aria-labelledby="paramModalTitle" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
<form method="post" action="<?= site_url('templates/' . $template['id'] . '/parameters') ?>" novalidate id="paramForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="0">
    <div class="modal-header"><h2 class="modal-title fs-5" id="paramModalTitle">Parameter</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label required" for="pf-section">Section</label>
                <select class="form-select" id="pf-section" name="section_id" required>
                    <?php foreach ($structure as $section): ?><option value="<?= $section['id'] ?>"><?= esc($section['title']) ?></option><?php endforeach ?>
                </select></div>
            <div class="col-md-6"><label class="form-label required" for="pf-library">Library parameter</label>
                <select class="form-select" id="pf-library" name="parameter_id" required>
                    <option value="">Choose…</option>
                    <?php foreach ($library as $lib): ?>
                        <option value="<?= $lib['id'] ?>" data-defaults="<?= esc((string) json_encode($lib), 'attr') ?>"><?= esc($lib['name']) ?> (<?= esc($lib['code']) ?>)</option>
                    <?php endforeach ?>
                </select>
                <div class="form-text">Missing? Add it under Master data → Parameter library.</div></div>
            <div class="col-md-6"><label class="form-label" for="pf-name">Name on the sheet</label><input class="form-control" id="pf-name" name="name" maxlength="150"></div>
            <div class="col-md-6"><label class="form-label" for="pf-spec">Specification text</label><input class="form-control" id="pf-spec" name="specification_text" maxlength="150" placeholder="e.g. 6.50 ±0.10, 3/4-14 NPSM"></div>
            <div class="col-md-4"><label class="form-label required" for="pf-type">Observation type</label>
                <select class="form-select" id="pf-type" name="observation_type" required>
                    <?php foreach ($types as $value => $label): ?><option value="<?= $value ?>"><?= esc($label) ?></option><?php endforeach ?>
                </select></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-unit">Unit</label>
                <select class="form-select" id="pf-unit" name="unit_id"><option value="">—</option>
                    <?php foreach ($units as $id => $label): ?><option value="<?= $id ?>"><?= esc($label) ?></option><?php endforeach ?></select></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-decimals">Decimals</label>
                <input class="form-control" id="pf-decimals" name="decimal_places" type="number" min="0" max="6" value="2"></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-lsl">LSL</label><input class="form-control num" id="pf-lsl" name="lsl" inputmode="decimal"></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-nominal">Nominal</label><input class="form-control num" id="pf-nominal" name="nominal" inputmode="decimal"></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-usl">USL</label><input class="form-control num" id="pf-usl" name="usl" inputmode="decimal"></div>
            <div class="col-md-6" data-for-types="DATE"><label class="form-label" for="pf-daterule">Date rule</label>
                <select class="form-select" id="pf-daterule" name="date_rule"><option value="NONE">No rule</option><option value="ON_OR_AFTER_INSPECTION_DATE">Must not be before the inspection date (e.g. PM due)</option></select></div>
            <div class="col-md-6"><label class="form-label" for="pf-method">Inspection method</label>
                <select class="form-select" id="pf-method" name="inspection_method_id"><option value="">—</option>
                    <?php foreach ($methods as $id => $label): ?><option value="<?= $id ?>"><?= esc($label) ?></option><?php endforeach ?></select></div>
            <div class="col-md-6"><label class="form-label" for="pf-gauge">Gauge / instrument</label>
                <select class="form-select" id="pf-gauge" name="gauge_type_id"><option value="">—</option>
                    <?php foreach ($gaugeTypes as $id => $label): ?><option value="<?= $id ?>"><?= esc($label) ?></option><?php endforeach ?></select></div>
            <div class="col-md-4"><label class="form-label required" for="pf-count">Observations per round</label>
                <input class="form-control" id="pf-count" name="observation_count" type="number" min="1" max="10" value="2"></div>
            <div class="col-md-4"><label class="form-label" for="pf-prefill">Pre-fill</label>
                <select class="form-select" id="pf-prefill" name="prefill_source"><option value="NONE">None</option>
                    <option value="OPERATOR_SKILL_LEVEL">Operator skill level (numeric)</option><option value="MACHINE_PM_DUE_DATE">Machine PM due date (date)</option></select></div>
            <div class="col-md-4 d-flex flex-column justify-content-end">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="pf-gaugereq" name="gauge_required" value="1"><label class="form-check-label" for="pf-gaugereq">Gauge ID required</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="pf-mandatory" name="is_mandatory" value="1" checked><label class="form-check-label" for="pf-mandatory">Mandatory</label></div>
            </div>
            <div class="col-12"><label class="form-label" for="pf-help">Help text for the inspector</label><input class="form-control" id="pf-help" name="help_text" maxlength="255"></div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit"><?= qms_icon('bi-check2') ?> Save parameter</button></div>
</form>
</div></div></div>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script type="module" src="<?= qms_asset('assets/js/template-editor.js') ?>"></script>
<?= $this->endSection() ?>

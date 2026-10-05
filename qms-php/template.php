<?php
/**
 * One template version: sections, parameters, header, mapping, versions.
 * Drafts are edited here; every change is a POST with an "action" field.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('template.view');
require_once QMS_ROOT . '/includes/templates.php';
require_once QMS_ROOT . '/includes/masters.php';

$id   = get_int('id');
$self = 'template.php?id=' . $id;

if (is_post()) {
    $action = post('action');
    require_permission(in_array($action, ['publish', 'retire'], true) ? 'template.publish' : 'template.manage');
    $sectionId = ctype_digit(post('section_id')) ? (int) post('section_id') : 0;
    $paramId   = ctype_digit(post('param_id')) ? (int) post('param_id') : 0;
    $direction = post('direction') === 'up' ? 'up' : 'down';

    try {
        switch ($action) {
            case 'header':
                template_update_header($id, post_fields(TEMPLATE_HEADER_FIELDS), $user);
                done('Template header saved.', $self);
            case 'delete':
                template_delete($id, $user);
                done('Draft template deleted.', 'templates.php');
            case 'section_add':
                template_section_add($id, post('title'), post('description'), $user);
                done('Section added.', $self);
            case 'section_save':
                template_section_update($id, $sectionId, post('title'), post('description'), $user);
                done('Section saved.', $self . '#s' . $sectionId);
            case 'section_delete':
                template_section_delete($id, $sectionId, $user);
                done('Section deleted.', $self);
            case 'section_move':
                template_section_move($id, $sectionId, $direction);
                done('Section moved.', $self . '#s' . $sectionId);
            case 'param_save':
                $saved = template_param_save($id, post_fields(TEMPLATE_PARAM_FIELDS), $user);
                done('Parameter saved.', $self . '#p' . $saved);
            case 'param_delete':
                template_param_delete($id, $paramId, $user);
                done('Parameter deleted.', $self);
            case 'param_move':
                template_param_move($id, $paramId, $direction);
                done('Parameter moved.', $self . '#p' . $paramId);
            case 'mapping':
                template_save_mapping($id, post_list('parts'), post_list('machines'), $user);
                done('Mapping saved.', $self . '#mapping');
            case 'new_version':
                $newId = template_new_version($id, $user);
                done('New draft version created. Change it, then publish to replace the current version.', 'template.php?id=' . $newId);
            case 'publish':
                template_publish($id, $user);
                done('Template published. New inspections for the mapped parts / machines now use this version.', $self);
            case 'retire':
                template_retire($id, $user);
                done('Template retired.', $self);
            default:
                fail('Unknown action.');
        }
    } catch (Throwable $e) {
        back_with_error($e, $self);
    }
}

$template   = template_find($id);
$structure  = template_structure($id);
$mapping    = template_mapping($id);
$usage      = template_usage($id);
$versions   = template_versions($template['template_code']);
$canManage  = can('template.manage');
$canPublish = can('template.publish');
$editable   = $template['status'] === 'DRAFT' && $canManage;
$errors     = form_errors();
$statusTone = TEMPLATE_STATUS_TONES[$template['status']];

if ($editable) {
    $library    = db_all('SELECT id, code, name, default_observation_type, default_unit_id, default_method_id, default_gauge_type_id
                            FROM inspection_parameters WHERE is_active = 1 ORDER BY name');
    $units      = master_lookup('units');
    $methods    = master_lookup('methods');
    $gaugeTypes = master_lookup('gauge-types');
}
$parts    = db_all('SELECT id, part_number, part_name, is_active FROM parts ORDER BY part_number');
$machines = db_all('SELECT id, machine_code, machine_name, is_active FROM machines ORDER BY machine_code');

/** Small POST form with one button (move / delete). */
$button = static function (string $action, array $fields, string $label, string $class, string $confirm = '', string $aria = '') use ($self): string {
    $html = '<form class="d-inline" method="post" action="' . e(url($self)) . '"'
        . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . e($name) . '" value="' . e((string) $value) . '">';
    }

    return $html . '<button class="' . e($class) . '" type="submit"' . ($aria !== '' ? ' aria-label="' . e($aria) . '"' : '') . '>' . $label . '</button></form>';
};

$page_title   = $template['template_code'] . ' v' . $template['version'];
$page_scripts = ['template-editor.js'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div>
        <h1><?= e($template['template_code']) ?> <span class="text-muted">v<?= (int) $template['version'] ?></span></h1>
        <p class="qms-sub"><?= e($template['name']) ?> · <?= e($template['type_name']) ?> ·
            <span class="qms-badge qms-badge--<?= $statusTone ?>"><?= e($template['status']) ?></span>
            <?php if ($template['status'] === 'PUBLISHED'): ?> since <?= e(plant_dt($template['published_at'])) ?> by <?= e($template['published_by_name'] ?? '') ?><?php endif ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= url('templates.php') ?>"><?= qms_icon('bi-arrow-left') ?> Templates</a>
        <a class="btn btn-outline-primary" href="<?= url('template_preview.php?id=' . (int) $template['id']) ?>"><?= qms_icon('bi-eye') ?> Preview</a>
        <?php if ($canManage): ?>
            <?= $button('new_version', [], qms_icon('bi-files') . ' New version', 'btn btn-outline-primary', 'Create a new draft version from v' . (int) $template['version'] . '?') ?>
        <?php endif ?>
        <?php if ($template['status'] === 'DRAFT' && $canPublish): ?>
            <?= $button('publish', [], qms_icon('bi-send-check') . ' Publish', 'btn btn-primary', 'Publish this version? It becomes read-only and replaces the currently published version of ' . $template['template_code'] . '.') ?>
        <?php elseif ($template['status'] === 'PUBLISHED' && $canPublish): ?>
            <?= $button('retire', [], qms_icon('bi-archive') . ' Retire', 'btn btn-outline-danger', 'Retire this template? New inspections can no longer use it.') ?>
        <?php endif ?>
    </div>
</div>

<?php if ($template['status'] !== 'DRAFT'): ?>
    <div class="alert alert-primary d-flex gap-2"><?= qms_icon('bi-lock-fill', 'mt-1') ?>
        <div>This version is a controlled document and cannot be changed (reports refer to it). Use <strong>New version</strong> to change specifications<?= $template['status'] === 'PUBLISHED' ? '; part and machine mapping can still be edited' : '' ?>.</div></div>
<?php endif ?>

<div class="row g-3">
<div class="col-xl-8">
    <?php foreach ($structure as $section): $sid = (int) $section['id']; ?>
    <div class="card qms-section-card mb-3" id="s<?= $sid ?>">
        <div class="card-header">
            <div><?= e($section['title']) ?><?php if ($section['description'] !== null): ?><div class="small text-muted fw-normal"><?= e($section['description']) ?></div><?php endif ?></div>
            <?php if ($editable): ?>
            <div class="d-flex flex-wrap gap-1">
                <?= $button('section_move', ['section_id' => $sid, 'direction' => 'up'], qms_icon('bi-arrow-up'), 'btn btn-sm btn-outline-secondary', '', 'Move section up') ?>
                <?= $button('section_move', ['section_id' => $sid, 'direction' => 'down'], qms_icon('bi-arrow-down'), 'btn btn-sm btn-outline-secondary', '', 'Move section down') ?>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#se<?= $sid ?>"><?= qms_icon('bi-pencil') ?> Rename</button>
                <?= $button('section_delete', ['section_id' => $sid], qms_icon('bi-trash'), 'btn btn-sm btn-outline-danger', 'Delete this section and its parameters?', 'Delete section') ?>
            </div>
            <?php endif ?>
        </div>
        <?php if ($editable): ?>
        <div class="collapse border-bottom" id="se<?= $sid ?>">
            <form class="p-3 row g-2 align-items-end" method="post" action="<?= url($self) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="section_save"><input type="hidden" name="section_id" value="<?= $sid ?>">
                <div class="col-md-5"><label class="form-label" for="st-<?= $sid ?>">Title</label><input class="form-control" id="st-<?= $sid ?>" name="title" maxlength="120" value="<?= e($section['title']) ?>" required></div>
                <div class="col-md-5"><label class="form-label" for="sd-<?= $sid ?>">Description</label><input class="form-control" id="sd-<?= $sid ?>" name="description" maxlength="255" value="<?= e($section['description'] ?? '') ?>"></div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Save</button></div>
            </form>
        </div>
        <?php endif ?>
        <div class="qms-table-wrap">
        <table class="table table-sm align-middle">
            <thead><tr><th>#</th><th>Parameter</th><th>Type</th><th>Specification</th><th>Method</th><th>Gauge</th><th class="text-center">Obs.</th><?php if ($editable): ?><th></th><?php endif ?></tr></thead>
            <tbody>
            <?php if ($section['parameters'] === []): ?><tr><td colspan="8" class="text-muted small p-3">No parameters yet.</td></tr><?php endif ?>
            <?php foreach ($section['parameters'] as $pi => $p): $pid = (int) $p['id']; ?>
                <tr class="qms-param-row" id="p<?= $pid ?>" data-param="<?= e((string) json_encode($p, JSON_UNESCAPED_UNICODE)) ?>">
                    <td class="text-muted"><?= $pi + 1 ?></td>
                    <td><strong><?= e($p['name']) ?></strong>
                        <?php if ($p['specification_text'] !== null): ?><div class="small text-muted"><?= e($p['specification_text']) ?></div><?php endif ?>
                        <?php if ((int) $p['is_mandatory'] === 0): ?><span class="qms-badge qms-badge--muted">optional</span><?php endif ?>
                        <?php if ($p['prefill_source'] !== 'NONE'): ?><span class="qms-badge qms-badge--approved">pre-filled</span><?php endif ?></td>
                    <td class="small"><?= e(OBSERVATION_TYPES[$p['observation_type']] ?? $p['observation_type']) ?></td>
                    <td class="num"><?= e(template_spec_text($p)) ?></td>
                    <td class="small"><?= e($p['method_label'] ?? '—') ?></td>
                    <td class="small"><?= e($p['gauge_type_name'] ?? '—') ?><?= (int) $p['gauge_required'] === 1 ? '<br><span class="qms-badge qms-badge--pending">Gauge ID required</span>' : '' ?></td>
                    <td class="text-center num"><?= (int) $p['observation_count'] ?></td>
                    <?php if ($editable): ?>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" type="button" data-edit-param aria-label="Edit parameter"><?= qms_icon('bi-pencil') ?></button>
                        <?= $button('param_move', ['param_id' => $pid, 'direction' => 'up'], qms_icon('bi-arrow-up'), 'btn btn-sm btn-outline-secondary', '', 'Move up') ?>
                        <?= $button('param_move', ['param_id' => $pid, 'direction' => 'down'], qms_icon('bi-arrow-down'), 'btn btn-sm btn-outline-secondary', '', 'Move down') ?>
                        <?= $button('param_delete', ['param_id' => $pid], qms_icon('bi-trash'), 'btn btn-sm btn-outline-danger', 'Delete this parameter?', 'Delete parameter') ?>
                    </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php if ($editable): ?>
            <div class="card-body pt-2"><button class="btn btn-outline-primary" type="button" data-add-param="<?= $sid ?>"><?= qms_icon('bi-plus-lg') ?> Add parameter</button></div>
        <?php endif ?>
    </div>
    <?php endforeach ?>

    <?php if ($editable): ?>
    <div class="card"><div class="card-header">Add section</div><div class="card-body">
        <form class="row g-2 align-items-end" method="post" action="<?= url($self) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="section_add">
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
            <form method="post" action="<?= url($self) ?>" novalidate data-layout="<?= e($template['layout']) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="header">
                <?php require QMS_ROOT . '/includes/views/template_header_fields.php'; ?>
                <button class="btn btn-primary mt-3" type="submit"><?= qms_icon('bi-check2') ?> Save header</button>
            </form>
            <hr>
            <?= $button('delete', [], qms_icon('bi-trash') . ' Delete draft', 'btn btn-outline-danger btn-sm', 'Delete this draft version completely?') ?>
        <?php else: ?>
            <dl class="qms-dl">
                <dt>Document no.</dt><dd><?= e($template['format_doc_no']) ?></dd>
                <dt>Revision no.</dt><dd><?= e($template['format_rev_no']) ?></dd>
                <dt>Make date</dt><dd><?= e(plant_date($template['format_made_date'])) ?></dd>
                <dt>Revision date</dt><dd><?= e(plant_date($template['format_rev_date'])) ?: '—' ?></dd>
                <?php if ($template['planned_rounds_per_shift'] !== null): ?><dt>Inspections / shift</dt><dd><?= (int) $template['planned_rounds_per_shift'] ?></dd><?php endif ?>
                <dt>Used by</dt><dd><?= $usage ?> report<?= $usage === 1 ? '' : 's' ?></dd>
            </dl>
        <?php endif ?>
    </div></div>

    <div class="card mb-3" id="mapping"><div class="card-header">Applies to</div><div class="card-body">
        <?php $mapEditable = $canManage && $template['status'] !== 'RETIRED'; ?>
        <form method="post" action="<?= url($self) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mapping">
            <p class="small text-muted">No ticks = all parts / all machines. The most specific published template wins (part + machine &gt; part &gt; machine &gt; all).</p>
            <?php foreach (['parts' => ['Parts', $parts, 'part_number', 'part_name'], 'machines' => ['Machines', $machines, 'machine_code', 'machine_name']] as $key => [$label, $rows, $codeCol, $nameCol]): ?>
                <label class="form-label mt-2"><?= $label ?> <span class="text-muted small">(<?= count($mapping[$key]) === 0 ? 'all' : count($mapping[$key]) . ' selected' ?>)</span></label>
                <input class="form-control form-control-sm mb-1" type="search" placeholder="Filter <?= strtolower($label) ?>…" data-filter-list="#map-<?= $key ?>" aria-label="Filter <?= strtolower($label) ?>">
                <div class="border rounded p-2 qms-scroll-y" id="map-<?= $key ?>">
                    <?php foreach ($rows as $row): $checked = in_array((int) $row['id'], $mapping[$key], true); ?>
                        <?php if ((int) $row['is_active'] === 1 || $checked): ?>
                        <div class="form-check" data-filter-text="<?= e(strtolower($row[$codeCol] . ' ' . $row[$nameCol])) ?>">
                            <input class="form-check-input" type="checkbox" name="<?= $key ?>[]" value="<?= (int) $row['id'] ?>" id="<?= $key ?>-<?= (int) $row['id'] ?>"<?= $checked ? ' checked' : '' ?><?= $mapEditable ? '' : ' disabled' ?>>
                            <label class="form-check-label" for="<?= $key ?>-<?= (int) $row['id'] ?>"><strong><?= e($row[$codeCol]) ?></strong> <span class="small text-muted"><?= e($row[$nameCol]) ?></span></label>
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
                <a href="<?= url('template.php?id=' . (int) $ver['id']) ?>"<?= (int) $ver['id'] === (int) $template['id'] ? ' aria-current="page" class="fw-bold"' : '' ?>>Version <?= (int) $ver['version'] ?></a>
                <span class="qms-badge qms-badge--<?= TEMPLATE_STATUS_TONES[$ver['status']] ?>"><?= e($ver['status']) ?></span>
            </li>
        <?php endforeach ?>
    </ul></div>
</div>
</div>

<?php if ($editable): ?>
<div class="modal fade" id="paramModal" tabindex="-1" aria-labelledby="paramModalTitle" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
<form method="post" action="<?= url($self) ?>" novalidate id="paramForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="param_save">
    <input type="hidden" name="id" value="0">
    <div class="modal-header"><h2 class="modal-title fs-5" id="paramModalTitle">Parameter</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label required" for="pf-section">Section</label>
                <select class="form-select" id="pf-section" name="section_id" required>
                    <?php foreach ($structure as $section): ?><option value="<?= (int) $section['id'] ?>"><?= e($section['title']) ?></option><?php endforeach ?>
                </select></div>
            <div class="col-md-6"><label class="form-label required" for="pf-library">Library parameter</label>
                <select class="form-select" id="pf-library" name="parameter_id" required>
                    <option value="">Choose…</option>
                    <?php foreach ($library as $lib): ?>
                        <option value="<?= (int) $lib['id'] ?>" data-defaults="<?= e((string) json_encode($lib, JSON_UNESCAPED_UNICODE)) ?>"><?= e($lib['name']) ?> (<?= e($lib['code']) ?>)</option>
                    <?php endforeach ?>
                </select>
                <div class="form-text">Missing? Add it under Master data → Parameter library.</div></div>
            <div class="col-md-6"><label class="form-label" for="pf-name">Name on the sheet</label><input class="form-control" id="pf-name" name="name" maxlength="150"></div>
            <div class="col-md-6"><label class="form-label" for="pf-spec">Specification text</label><input class="form-control" id="pf-spec" name="specification_text" maxlength="150" placeholder="e.g. 6.50 ±0.10, 3/4-14 NPSM"></div>
            <div class="col-md-4"><label class="form-label required" for="pf-type">Observation type</label>
                <select class="form-select" id="pf-type" name="observation_type" required>
                    <?php foreach (OBSERVATION_TYPES as $value => $label): ?><option value="<?= $value ?>"><?= e($label) ?></option><?php endforeach ?>
                </select></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-unit">Unit</label>
                <select class="form-select" id="pf-unit" name="unit_id"><option value="">—</option>
                    <?php foreach ($units as $uid => $label): ?><option value="<?= (int) $uid ?>"><?= e($label) ?></option><?php endforeach ?></select></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-decimals">Decimals</label>
                <input class="form-control" id="pf-decimals" name="decimal_places" type="number" min="0" max="6" value="2"></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-lsl">LSL</label><input class="form-control num" id="pf-lsl" name="lsl" inputmode="decimal"></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-nominal">Nominal</label><input class="form-control num" id="pf-nominal" name="nominal" inputmode="decimal"></div>
            <div class="col-md-4" data-for-types="NUMERIC PERCENTAGE"><label class="form-label" for="pf-usl">USL</label><input class="form-control num" id="pf-usl" name="usl" inputmode="decimal"></div>
            <div class="col-md-6" data-for-types="DATE"><label class="form-label" for="pf-daterule">Date rule</label>
                <select class="form-select" id="pf-daterule" name="date_rule"><option value="NONE">No rule</option><option value="ON_OR_AFTER_INSPECTION_DATE">Must not be before the inspection date (e.g. PM due)</option></select></div>
            <div class="col-md-6"><label class="form-label" for="pf-method">Inspection method</label>
                <select class="form-select" id="pf-method" name="inspection_method_id"><option value="">—</option>
                    <?php foreach ($methods as $mid => $label): ?><option value="<?= (int) $mid ?>"><?= e($label) ?></option><?php endforeach ?></select></div>
            <div class="col-md-6"><label class="form-label" for="pf-gauge">Gauge / instrument</label>
                <select class="form-select" id="pf-gauge" name="gauge_type_id"><option value="">—</option>
                    <?php foreach ($gaugeTypes as $gid => $label): ?><option value="<?= (int) $gid ?>"><?= e($label) ?></option><?php endforeach ?></select></div>
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
<?php require QMS_ROOT . '/includes/layout/footer.php';

<?php
/**
 * Report header ("Report details"). Fields follow the report type configuration
 * (REQUIRED / OPTIONAL / HIDDEN). Variables: $report, $shifts, $employees, $canEdit.
 */
defined('QMS') || exit;

$dis = $canEdit ? '' : ' disabled';
$req = static fn (string $mode): string => $mode === 'REQUIRED' ? ' required' : '';
$employeeSelect = static function (string $column, string $label, string $mode, ?int $selected) use ($employees, $dis, $req): string {
    $html = '<label class="form-label' . $req($mode) . '" for="h-' . $column . '">' . e($label) . '</label>'
        . '<select class="form-select form-select-lg" id="h-' . $column . '" data-header-field="' . $column . '"' . $dis . '><option value="">' . ($mode === 'REQUIRED' ? 'Choose' : 'Not recorded') . '</option>';
    foreach ($employees as $emp) {
        $html .= '<option value="' . (int) $emp['id'] . '"' . ($selected === (int) $emp['id'] ? ' selected' : '') . '>'
            . e($emp['full_name'] . ' (' . $emp['employee_code'] . ')') . ($emp['skill_level'] !== null ? ' · skill ' . (int) $emp['skill_level'] : '') . '</option>';
    }

    return $html . '</select><div class="invalid-feedback d-block" data-error-for="' . $column . '"></div>';
};
?>
<div class="qms-head-grid mb-3">
    <div class="item"><div class="k">Part</div><div class="v"><?= e($report['part_number']) ?> · <?= e($report['part_name']) ?></div>
        <?php if ($report['drawing_number'] !== null): ?><div class="small text-muted">Drawing <?= e($report['drawing_number']) ?><?= $report['drawing_revision'] !== null ? ' rev ' . e($report['drawing_revision']) : '' ?></div><?php endif ?></div>
    <div class="item"><div class="k">Machine</div><div class="v"><?= e($report['machine_code']) ?> · <?= e($report['machine_name']) ?></div></div>
    <div class="item"><div class="k">Production date</div><div class="v"><?= e(plant_date($report['inspection_date'])) ?></div></div>
    <div class="item"><div class="k">Format</div><div class="v"><?= e($report['format_doc_no']) ?> Rev <?= e($report['format_rev_no']) ?></div>
        <div class="small text-muted">Template <?= e($report['template_code']) ?> v<?= (int) $report['template_version'] ?></div></div>
    <?php if ($report['report_no'] !== null): ?>
        <div class="item"><div class="k">Report no.</div><div class="v"><?= e($report['report_no']) ?><?= (int) $report['revision_no'] > 0 ? ' Rev ' . (int) $report['revision_no'] : '' ?></div></div>
    <?php endif ?>
</div>
<div class="row g-3">
    <?php if ($report['header_shift'] !== 'HIDDEN'): ?>
        <div class="col-md-4">
            <label class="form-label<?= $req($report['header_shift']) ?>" for="h-shift_id">Shift</label>
            <select class="form-select form-select-lg" id="h-shift_id" data-header-field="shift_id"<?= $dis ?>>
                <option value="">Choose</option>
                <?php foreach ($shifts as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $report['shift_id'] === (int) $s['id'] ? ' selected' : '' ?>>Shift <?= e($s['code']) ?> (<?= e(substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5)) ?>)</option>
                <?php endforeach ?>
            </select>
            <div class="invalid-feedback d-block" data-error-for="shift_id"></div>
        </div>
    <?php endif ?>
    <?php if ($report['header_operator'] !== 'HIDDEN'): ?>
        <div class="col-md-4"><?= $employeeSelect('operator_employee_id', 'Operator', $report['header_operator'], $report['operator_employee_id'] === null ? null : (int) $report['operator_employee_id']) ?></div>
    <?php endif ?>
    <?php if ($report['header_setter'] !== 'HIDDEN'): ?>
        <div class="col-md-4"><?= $employeeSelect('setter_employee_id', 'Setter', $report['header_setter'], $report['setter_employee_id'] === null ? null : (int) $report['setter_employee_id']) ?></div>
    <?php endif ?>
    <?php if ($report['header_timing'] !== 'HIDDEN'): ?>
        <?php foreach (['received_at' => 'Received time', 'finish_at' => 'Finish time'] as $column => $label): ?>
            <div class="col-sm-6 col-lg-4">
                <label class="form-label<?= $req($report['header_timing']) ?>" for="h-<?= $column ?>"><?= $label ?></label>
                <div class="input-group input-group-lg">
                    <input class="form-control" type="time" id="h-<?= $column ?>" data-header-field="<?= $column ?>"
                           value="<?= $report[$column] === null ? '' : e(plant_dt($report[$column], 'H:i')) ?>"<?= $dis ?>>
                    <?php if ($canEdit): ?><button class="btn btn-outline-secondary" type="button" data-now-for="h-<?= $column ?>">Now</button><?php endif ?>
                </div>
                <div class="invalid-feedback d-block" data-error-for="<?= $column ?>"></div>
            </div>
        <?php endforeach ?>
    <?php endif ?>
    <div class="col-12">
        <label class="form-label" for="h-remarks">Report remarks</label>
        <textarea class="form-control" id="h-remarks" rows="2" maxlength="1000" data-header-field="remarks"<?= $dis ?>><?= e((string) ($report['remarks'] ?? '')) ?></textarea>
        <div class="invalid-feedback d-block" data-error-for="remarks"></div>
    </div>
</div>

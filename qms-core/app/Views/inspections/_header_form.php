<?php
/**
 * Report header ("Report details" step). Fields are shown according to the
 * report type configuration (REQUIRED / OPTIONAL / HIDDEN).
 *
 * @var array<string, mixed>       $report
 * @var list<array<string, mixed>> $shifts
 * @var list<array<string, mixed>> $employees
 * @var bool                       $canEdit
 */
$dis = $canEdit ? '' : ' disabled';
$req = static fn (string $mode): string => $mode === 'REQUIRED' ? ' required' : '';
$employeeSelect = static function (string $column, string $label, string $mode, ?int $selected, array $employees, string $dis) use ($req): string {
    $html = '<label class="form-label' . $req($mode) . '" for="h-' . $column . '">' . esc($label) . '</label>'
        . '<select class="form-select form-select-lg" id="h-' . $column . '" data-header-field="' . $column . '"' . $dis . '><option value="">' . ($mode === 'REQUIRED' ? 'Choose' : 'Not recorded') . '</option>';
    foreach ($employees as $e) {
        $html .= '<option value="' . (int) $e['id'] . '"' . ($selected === (int) $e['id'] ? ' selected' : '') . '>'
            . esc($e['full_name'] . ' (' . $e['employee_code'] . ')') . ($e['skill_level'] !== null ? ' · skill ' . (int) $e['skill_level'] : '') . '</option>';
    }

    return $html . '</select><div class="invalid-feedback d-block" data-error-for="' . $column . '"></div>';
};
?>
<div class="qms-head-grid mb-3">
    <div class="item"><div class="k">Part</div><div class="v"><?= esc($report['part_number']) ?> · <?= esc($report['part_name']) ?></div>
        <?php if ($report['drawing_number'] !== null): ?><div class="small text-muted">Drawing <?= esc($report['drawing_number']) ?><?= $report['drawing_revision'] !== null ? ' rev ' . esc($report['drawing_revision']) : '' ?></div><?php endif ?></div>
    <div class="item"><div class="k">Machine</div><div class="v"><?= esc($report['machine_code']) ?> · <?= esc($report['machine_name']) ?></div></div>
    <div class="item"><div class="k">Production date</div><div class="v"><?= esc(plant_date($report['inspection_date'])) ?></div></div>
    <div class="item"><div class="k">Format</div><div class="v"><?= esc($report['format_doc_no']) ?> Rev <?= esc($report['format_rev_no']) ?></div>
        <div class="small text-muted">Template <?= esc($report['template_code']) ?> v<?= (int) $report['template_version'] ?></div></div>
    <?php if ($report['report_no'] !== null): ?>
        <div class="item"><div class="k">Report no.</div><div class="v"><?= esc($report['report_no']) ?><?= (int) $report['revision_no'] > 0 ? ' Rev ' . (int) $report['revision_no'] : '' ?></div></div>
    <?php endif ?>
</div>
<div class="row g-3">
    <?php if ($report['header_shift'] !== 'HIDDEN'): ?>
        <div class="col-md-4">
            <label class="form-label<?= $req($report['header_shift']) ?>" for="h-shift_id">Shift</label>
            <select class="form-select form-select-lg" id="h-shift_id" data-header-field="shift_id"<?= $dis ?>>
                <option value="">Choose</option>
                <?php foreach ($shifts as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $report['shift_id'] === (int) $s['id'] ? ' selected' : '' ?>>Shift <?= esc($s['code']) ?> (<?= esc(substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5)) ?>)</option>
                <?php endforeach ?>
            </select>
            <div class="invalid-feedback d-block" data-error-for="shift_id"></div>
        </div>
    <?php endif ?>
    <?php if ($report['header_operator'] !== 'HIDDEN'): ?>
        <div class="col-md-4"><?= $employeeSelect('operator_employee_id', 'Operator', $report['header_operator'], $report['operator_employee_id'] === null ? null : (int) $report['operator_employee_id'], $employees, $dis) ?></div>
    <?php endif ?>
    <?php if ($report['header_setter'] !== 'HIDDEN'): ?>
        <div class="col-md-4"><?= $employeeSelect('setter_employee_id', 'Setter', $report['header_setter'], $report['setter_employee_id'] === null ? null : (int) $report['setter_employee_id'], $employees, $dis) ?></div>
    <?php endif ?>
    <?php if ($report['header_timing'] !== 'HIDDEN'): ?>
        <?php foreach (['received_at' => 'Received time', 'finish_at' => 'Finish time'] as $column => $label): ?>
            <div class="col-sm-6 col-lg-4">
                <label class="form-label<?= $req($report['header_timing']) ?>" for="h-<?= $column ?>"><?= $label ?></label>
                <div class="input-group input-group-lg">
                    <input class="form-control" type="time" id="h-<?= $column ?>" data-header-field="<?= $column ?>"
                           value="<?= $report[$column] === null ? '' : esc(plant_dt($report[$column], 'H:i'), 'attr') ?>"<?= $dis ?>>
                    <?php if ($canEdit): ?><button class="btn btn-outline-secondary" type="button" data-now-for="h-<?= $column ?>">Now</button><?php endif ?>
                </div>
                <div class="invalid-feedback d-block" data-error-for="<?= $column ?>"></div>
            </div>
        <?php endforeach ?>
    <?php endif ?>
    <div class="col-12">
        <label class="form-label" for="h-remarks">Report remarks</label>
        <textarea class="form-control" id="h-remarks" rows="2" maxlength="1000" data-header-field="remarks"<?= $dis ?>><?= esc((string) ($report['remarks'] ?? '')) ?></textarea>
        <div class="invalid-feedback d-block" data-error-for="remarks"></div>
    </div>
</div>

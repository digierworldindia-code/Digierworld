<?php
/**
 * Header fields of the new / edit template forms.
 * Variables: $template (row or null), $errors.
 */
defined('QMS') || exit;

$hv = static fn (string $k, string $default = ''): string => (string) (old($k) ?? ($template[$k] ?? $default));
?>
<div class="row g-3">
    <div class="col-md-8"><label class="form-label required" for="name">Template name</label>
        <input class="form-control<?= field_invalid($errors, 'name') ?>" id="name" name="name" maxlength="150" value="<?= e($hv('name')) ?>" required>
        <?= field_error($errors, 'name') ?></div>
    <div class="col-md-4"><label class="form-label required" for="format_doc_no">Document number</label>
        <input class="form-control<?= field_invalid($errors, 'format_doc_no') ?>" id="format_doc_no" name="format_doc_no" maxlength="40" placeholder="QA/F/01" value="<?= e($hv('format_doc_no')) ?>" required>
        <?= field_error($errors, 'format_doc_no') ?></div>
    <div class="col-md-4"><label class="form-label required" for="format_rev_no">Revision number</label>
        <input class="form-control<?= field_invalid($errors, 'format_rev_no') ?>" id="format_rev_no" name="format_rev_no" maxlength="10" value="<?= e($hv('format_rev_no', '00')) ?>" required>
        <?= field_error($errors, 'format_rev_no') ?></div>
    <div class="col-md-4"><label class="form-label required" for="format_made_date">Make date</label>
        <input class="form-control<?= field_invalid($errors, 'format_made_date') ?>" type="date" id="format_made_date" name="format_made_date" value="<?= e($hv('format_made_date')) ?>" required>
        <?= field_error($errors, 'format_made_date') ?></div>
    <div class="col-md-4"><label class="form-label" for="format_rev_date">Revision date</label>
        <input class="form-control<?= field_invalid($errors, 'format_rev_date') ?>" type="date" id="format_rev_date" name="format_rev_date" value="<?= e($hv('format_rev_date')) ?>">
        <?= field_error($errors, 'format_rev_date') ?></div>
    <div class="col-md-4" data-grid-only><label class="form-label" for="planned_rounds_per_shift">Planned inspections per shift</label>
        <input class="form-control<?= field_invalid($errors, 'planned_rounds_per_shift') ?>" type="number" min="1" max="24" id="planned_rounds_per_shift" name="planned_rounds_per_shift" value="<?= e($hv('planned_rounds_per_shift', '4')) ?>">
        <?= field_error($errors, 'planned_rounds_per_shift') ?><div class="form-text">In-Process Inspection only (inspection times per shift).</div></div>
    <div class="col-12"><label class="form-label" for="notes">Notes</label>
        <textarea class="form-control<?= field_invalid($errors, 'notes') ?>" id="notes" name="notes" rows="2" maxlength="2000"><?= e($hv('notes')) ?></textarea>
        <?= field_error($errors, 'notes') ?></div>
</div>

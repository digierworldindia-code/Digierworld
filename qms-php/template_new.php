<?php
/**
 * Create a new template (version 1, draft).
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('template.manage');
require_once QMS_ROOT . '/includes/templates.php';

if (is_post()) {
    try {
        $id = template_create(post_fields(TEMPLATE_HEADER_FIELDS), $user);
    } catch (Throwable $e) {
        back_with_error($e, 'template_new.php');
    }
    done('Template created. Add sections and parameters, map it to parts / machines, then publish it.', 'template.php?id=' . $id);
}

$reportTypes  = template_report_types();
$errors       = form_errors();
$template     = null;
$page_title   = 'New template';
$page_scripts = ['template-editor.js'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>New template</h1><p class="qms-sub">Document control fields are printed on every report made with this template.</p></div>
    <a class="btn btn-outline-secondary" href="<?= url('templates.php') ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<div class="card col-xl-9"><div class="card-body">
<form method="post" action="<?= url('template_new.php') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label required" for="report_type_id">Report type</label>
            <select class="form-select form-select-lg<?= field_invalid($errors, 'report_type_id') ?>" id="report_type_id" name="report_type_id" required data-layout-select>
                <option value="">Choose…</option>
                <?php foreach ($reportTypes as $rt): ?>
                    <option value="<?= (int) $rt['id'] ?>" data-layout="<?= e($rt['layout']) ?>"<?= (string) old('report_type_id') === (string) $rt['id'] ? ' selected' : '' ?>><?= e($rt['name']) ?></option>
                <?php endforeach ?>
            </select><?= field_error($errors, 'report_type_id') ?></div>
        <div class="col-md-6"><label class="form-label required" for="template_code">Template code</label>
            <input class="form-control form-control-lg<?= field_invalid($errors, 'template_code') ?>" id="template_code" name="template_code" maxlength="40" placeholder="e.g. PPI-BF1001" value="<?= e((string) old('template_code', '')) ?>" required>
            <?= field_error($errors, 'template_code') ?><div class="form-text">Stays the same for all versions.</div></div>
    </div>
    <?php require QMS_ROOT . '/includes/views/template_header_fields.php'; ?>
    <button class="btn btn-primary btn-lg mt-4" type="submit" data-once><?= qms_icon('bi-check2') ?> Create template</button>
</form>
</div></div>
<?php require QMS_ROOT . '/includes/layout/footer.php';

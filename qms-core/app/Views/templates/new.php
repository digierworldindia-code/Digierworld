<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>New template</h1><p class="qms-sub">Document control fields are printed on every report made with this template.</p></div>
    <a class="btn btn-outline-secondary" href="<?= site_url('templates') ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<div class="card col-xl-9"><div class="card-body">
<form method="post" action="<?= site_url('templates') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label required" for="report_type_id">Report type</label>
            <select class="form-select form-select-lg<?= field_invalid($errors, 'report_type_id') ?>" id="report_type_id" name="report_type_id" required data-layout-select>
                <option value="">Choose…</option>
                <?php foreach ($reportTypes as $rt): ?>
                    <option value="<?= $rt['id'] ?>" data-layout="<?= esc($rt['layout'], 'attr') ?>"<?= (string) old('report_type_id') === (string) $rt['id'] ? ' selected' : '' ?>><?= esc($rt['name']) ?></option>
                <?php endforeach ?>
            </select><?= field_error($errors, 'report_type_id') ?></div>
        <div class="col-md-6"><label class="form-label required" for="template_code">Template code</label>
            <input class="form-control form-control-lg<?= field_invalid($errors, 'template_code') ?>" id="template_code" name="template_code" maxlength="40" placeholder="e.g. PPI-BF1001" value="<?= esc(old('template_code') ?? '', 'attr') ?>" required>
            <?= field_error($errors, 'template_code') ?><div class="form-text">Stays the same for all versions.</div></div>
    </div>
    <?= view('templates/_header_fields', ['template' => null, 'errors' => $errors, 'disabled' => false]) ?>
    <button class="btn btn-primary btn-lg mt-4" type="submit" data-once><?= qms_icon('bi-check2') ?> Create template</button>
</form>
</div></div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script type="module" src="<?= qms_asset('assets/js/template-editor.js') ?>"></script>
<?= $this->endSection() ?>

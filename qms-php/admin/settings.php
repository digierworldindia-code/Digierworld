<?php
/**
 * System settings: company, numbering, regional, workflow, security, gauges,
 * inspection, Google Sheets. POST action: group (save one tab), logo,
 * report_type (prefix and approval stages), google_test.
 */
require dirname(__DIR__) . '/includes/init.php';
$user = require_permission('setting.manage');
require_once QMS_ROOT . '/includes/settings_admin.php';
require_once QMS_ROOT . '/includes/numbering.php';
require_once QMS_ROOT . '/includes/sheets_worker.php';

if (is_post()) {
    $tab = array_key_exists(post('group'), SETTINGS_GROUPS) ? post('group') : 'company';
    try {
        switch (post('action')) {
            case 'logo':
                $tab = 'company';
                settings_save_logo($_FILES['logo'] ?? null, $user);
                done('Logo updated.', 'admin/settings.php?tab=company');
            case 'report_type':
                $tab = 'workflow';
                settings_save_report_type(get_int('id') ?: (ctype_digit(post('id')) ? (int) post('id') : 0), $_POST, $user);
                done('Report type saved. Reports already in progress keep their current stage.', 'admin/settings.php?tab=workflow');
            case 'google_test':
                $tab = 'google';
                $id  = setting_str('google.spreadsheet_id');
                if ($id === '') {
                    fail_field('google.spreadsheet_id', 'Enter and save the spreadsheet ID first.');
                }
                try {
                    $title = gs_spreadsheet_title($id);
                } catch (QmsError $e) {
                    throw $e;
                } catch (Throwable $e) {
                    fail('Connection failed: ' . mb_substr(gs_sanitize($e), 0, 300));
                }
                audit_log('TEST', 'sync', null, null, ['spreadsheet' => $id, 'result' => 'ok']);
                done("Connected. Spreadsheet title: \"{$title}\".", 'admin/settings.php?tab=google');
            default:
                settings_update($tab, settings_validate($tab, $_POST), (int) $user['id']);
                done(SETTINGS_GROUPS[$tab] . ' settings saved.', 'admin/settings.php?tab=' . $tab);
        }
    } catch (Throwable $e) {
        back_with_error($e, 'admin/settings.php?tab=' . $tab);
    }
}

$active      = array_key_exists(get('tab'), SETTINGS_GROUPS) ? get('tab') : 'company';
$errors      = form_errors();
$reportTypes = db_all('SELECT * FROM report_types ORDER BY id');
$google      = gs_credentials();
$nextNumber  = number_preview();

$page_title = 'Settings';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Settings</h1><p class="qms-sub">Company profile, numbering, workflow, security and integrations. Every change is audited.</p></div>
</div>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
    <?php foreach (SETTINGS_GROUPS as $key => $label): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-nowrap<?= $active === $key ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $key ?>" type="button" role="tab"
                    aria-controls="tab-<?= $key ?>" aria-selected="<?= $active === $key ? 'true' : 'false' ?>"><?= e($label) ?></button>
        </li>
    <?php endforeach ?>
</ul>

<div class="tab-content">
<?php foreach (SETTINGS_GROUPS as $key => $groupLabel): ?>
    <div class="tab-pane<?= $active === $key ? ' show active' : '' ?>" id="tab-<?= $key ?>" role="tabpanel">
        <div class="row g-3">
        <div class="col-xl-8">
        <div class="card"><div class="card-body">
            <form method="post" action="<?= url('admin/settings.php?tab=' . $key) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="group"><input type="hidden" name="group" value="<?= $key ?>">
                <?php foreach (settings_group($key) as $row):
                    if ($row['value_type'] === 'FILE') {
                        continue;
                    }
                    $name  = settings_field($row['setting_key']);
                    $value = old($name) ?? $row['setting_value'];
                    $sid   = 's-' . $name; ?>
                    <div class="mb-3">
                    <?php if ($row['value_type'] === 'BOOLEAN'): ?>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="<?= $sid ?>" name="<?= $name ?>" value="1"<?= (string) $value === '1' ? ' checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="<?= $sid ?>"><?= e($row['label']) ?></label>
                        </div>
                    <?php else: ?>
                        <label class="form-label" for="<?= $sid ?>"><?= e($row['label']) ?></label>
                        <?php if (isset(SETTINGS_OPTIONS[$row['setting_key']])): ?>
                            <select class="form-select<?= field_invalid($errors, $row['setting_key']) ?>" id="<?= $sid ?>" name="<?= $name ?>">
                                <?php foreach (SETTINGS_OPTIONS[$row['setting_key']] as $optValue => $optLabel): ?>
                                    <option value="<?= e($optValue) ?>"<?= (string) $value === (string) $optValue ? ' selected' : '' ?>><?= e($optLabel) ?></option>
                                <?php endforeach ?>
                            </select>
                        <?php elseif ($row['setting_key'] === 'company.address'): ?>
                            <textarea class="form-control<?= field_invalid($errors, $row['setting_key']) ?>" id="<?= $sid ?>" name="<?= $name ?>" rows="3" maxlength="500"><?= e((string) $value) ?></textarea>
                        <?php else: ?>
                            <input class="form-control<?= field_invalid($errors, $row['setting_key']) ?>" id="<?= $sid ?>" name="<?= $name ?>"
                                   type="<?= $row['value_type'] === 'INTEGER' ? 'number' : 'text' ?>" value="<?= e((string) $value) ?>">
                        <?php endif ?>
                    <?php endif ?>
                    <?= field_error($errors, $row['setting_key']) ?>
                    <?php if (($row['description'] ?? '') !== ''): ?><div class="form-text"><?= e($row['description']) ?></div><?php endif ?>
                    </div>
                <?php endforeach ?>
                <button class="btn btn-primary" type="submit" data-once><?= qms_icon('bi-check2') ?> Save <?= e(strtolower($groupLabel)) ?></button>
            </form>
        </div></div>
        </div>

        <div class="col-xl-4">
        <?php if ($key === 'company'): ?>
            <div class="card"><div class="card-header">Company logo</div><div class="card-body">
                <?php if (setting_str('company.logo') !== ''): ?>
                    <img class="qms-logo-preview mb-3" src="<?= url('logo.php') ?>" alt="Current logo">
                <?php endif ?>
                <form method="post" action="<?= url('admin/settings.php?tab=company') ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="logo">
                    <input class="form-control mb-2<?= field_invalid($errors, 'logo') ?>" type="file" name="logo" accept="image/png,image/jpeg" required aria-label="Logo file">
                    <?= field_error($errors, 'logo') ?>
                    <div class="form-text mb-2">PNG or JPEG, max <?= UPLOAD_LOGO_MAX_KB ?> KB. It is re-encoded and resized to fit 600 × 200 px.</div>
                    <button class="btn btn-outline-primary" type="submit"><?= qms_icon('bi-upload') ?> Upload logo</button>
                </form>
            </div></div>
        <?php elseif ($key === 'documents'): ?>
            <div class="card"><div class="card-header">Next report number</div><div class="card-body">
                <p class="mb-1">With the current settings the next report of the first report type will look like:</p>
                <p class="fs-5 fw-bold num mb-0"><?= e($nextNumber) ?></p>
                <div class="form-text">Report prefixes are set per report type under <em>Approval workflow</em>.</div>
            </div></div>
        <?php elseif ($key === 'workflow'): ?>
            <div class="card"><div class="card-header">How signing works</div><div class="card-body small">
                <p>Submit → Production verification → Quality verification → QA approval. Stages switched off for a report type are skipped.</p>
                <p class="mb-0">Nobody can verify a report they submitted. With <em>Distinct signers</em> on, one person signs at most one stage of a report.</p>
            </div></div>
        <?php elseif ($key === 'google'): ?>
            <div class="card"><div class="card-header">Service account</div><div class="card-body">
                <dl class="qms-dl small mb-3">
                    <dt>Key file</dt><dd><?= $google['configured'] ? '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-check-circle') . ' found</span>' : '<span class="qms-badge qms-badge--fail">' . qms_icon('bi-x-circle') . ' not configured</span>' ?></dd>
                    <?php if ($google['email'] !== null): ?><dt>Share the sheet with</dt><dd class="text-break"><code><?= e($google['email']) ?></code></dd><?php endif ?>
                </dl>
                <p class="small text-muted"><?= e($google['hint']) ?></p>
                <form method="post" action="<?= url('admin/settings.php?tab=google') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="google_test">
                    <button class="btn btn-outline-primary" type="submit"><?= qms_icon('bi-plug') ?> Test connection</button>
                </form>
            </div></div>
        <?php endif ?>
        </div>
        </div>

        <?php if ($key === 'workflow'): ?>
        <div class="card mt-3"><div class="card-header">Report types: number prefix and approval stages</div>
        <div class="qms-table-wrap"><table class="table">
            <thead><tr><th>Report type</th><th>Prefix</th><th class="text-center">Production verification</th><th class="text-center">Quality verification</th><th class="text-center">QA approval</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($reportTypes as $rt): $fid = 'rt-' . (int) $rt['id']; ?>
                <tr>
                    <td><strong><?= e($rt['name']) ?></strong><div class="small text-muted"><?= e($rt['code']) ?> · <?= e(str_replace('_', ' ', strtolower($rt['layout']))) ?></div></td>
                    <td><input form="<?= $fid ?>" class="form-control" name="doc_prefix" maxlength="10" value="<?= e($rt['doc_prefix']) ?>" aria-label="Prefix for <?= e($rt['name']) ?>"></td>
                    <?php foreach (['requires_production_verification' => 'Production verification', 'requires_quality_verification' => 'Quality verification', 'requires_qa_approval' => 'QA approval'] as $col => $colLabel): ?>
                        <td class="text-center"><input form="<?= $fid ?>" class="form-check-input" type="checkbox" name="<?= $col ?>" value="1"<?= (int) $rt[$col] === 1 ? ' checked' : '' ?> aria-label="<?= e($colLabel . ' – ' . $rt['name']) ?>"></td>
                    <?php endforeach ?>
                    <td class="text-end">
                        <form id="<?= $fid ?>" method="post" action="<?= url('admin/settings.php?tab=workflow') ?>"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="report_type"><input type="hidden" name="id" value="<?= (int) $rt['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary" type="submit"><?= qms_icon('bi-check2') ?> Save</button></form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div></div>
        <?php endif ?>
    </div>
<?php endforeach ?>
</div>
<?php require QMS_ROOT . '/includes/layout/footer.php';

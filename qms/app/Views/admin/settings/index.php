<?php
/**
 * @var array<string, array{label: string, rows: list<array<string, mixed>>}> $groups
 * @var array<string, array<string, string>> $options
 * @var string $active
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $reportTypes
 * @var array<string, mixed> $google
 * @var string $nextNumber
 */
$field = static fn (string $key): string => str_replace('.', '__', $key);
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Settings</h1><p class="qms-sub">Company profile, numbering, workflow, security and integrations. Every change is audited.</p></div>
</div>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
    <?php foreach ($groups as $key => $group): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-nowrap<?= $active === $key ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $key ?>" type="button" role="tab"
                    aria-controls="tab-<?= $key ?>" aria-selected="<?= $active === $key ? 'true' : 'false' ?>"><?= esc($group['label']) ?></button>
        </li>
    <?php endforeach ?>
</ul>

<div class="tab-content">
<?php foreach ($groups as $key => $group): ?>
    <div class="tab-pane<?= $active === $key ? ' show active' : '' ?>" id="tab-<?= $key ?>" role="tabpanel">
        <div class="row g-3">
        <div class="col-xl-8">
        <div class="card"><div class="card-body">
            <form method="post" action="<?= site_url('admin/settings/' . $key) ?>" novalidate>
                <?= csrf_field() ?>
                <?php foreach ($group['rows'] as $row):
                    if ($row['value_type'] === 'FILE') { continue; }
                    $name  = $field($row['setting_key']);
                    $value = old($name) ?? $row['setting_value'];
                    $id    = 's-' . $name; ?>
                    <div class="mb-3">
                    <?php if ($row['value_type'] === 'BOOLEAN'): ?>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="<?= $id ?>" name="<?= $name ?>" value="1"<?= (string) $value === '1' ? ' checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="<?= $id ?>"><?= esc($row['label']) ?></label>
                        </div>
                    <?php else: ?>
                        <label class="form-label" for="<?= $id ?>"><?= esc($row['label']) ?></label>
                        <?php if (isset($options[$row['setting_key']])): ?>
                            <select class="form-select<?= field_invalid($errors, $row['setting_key']) ?>" id="<?= $id ?>" name="<?= $name ?>">
                                <?php foreach ($options[$row['setting_key']] as $optValue => $optLabel): ?>
                                    <option value="<?= esc($optValue, 'attr') ?>"<?= (string) $value === (string) $optValue ? ' selected' : '' ?>><?= esc($optLabel) ?></option>
                                <?php endforeach ?>
                            </select>
                        <?php elseif ($row['setting_key'] === 'company.address'): ?>
                            <textarea class="form-control<?= field_invalid($errors, $row['setting_key']) ?>" id="<?= $id ?>" name="<?= $name ?>" rows="3" maxlength="500"><?= esc((string) $value) ?></textarea>
                        <?php else: ?>
                            <input class="form-control<?= field_invalid($errors, $row['setting_key']) ?>" id="<?= $id ?>" name="<?= $name ?>"
                                   type="<?= $row['value_type'] === 'INTEGER' ? 'number' : 'text' ?>" value="<?= esc((string) $value, 'attr') ?>">
                        <?php endif ?>
                    <?php endif ?>
                    <?= field_error($errors, $row['setting_key']) ?>
                    <?php if (($row['description'] ?? '') !== ''): ?><div class="form-text"><?= esc($row['description']) ?></div><?php endif ?>
                    </div>
                <?php endforeach ?>
                <button class="btn btn-primary" type="submit" data-once><?= qms_icon('bi-check2') ?> Save <?= esc(strtolower($group['label'])) ?></button>
            </form>
        </div></div>
        </div>

        <div class="col-xl-4">
        <?php if ($key === 'company'): ?>
            <div class="card"><div class="card-header">Company logo</div><div class="card-body">
                <?php if ((string) qms_setting('company.logo', '') !== ''): ?>
                    <img class="qms-logo-preview mb-3" src="<?= site_url('media/logo') ?>" alt="Current logo">
                <?php endif ?>
                <form method="post" action="<?= site_url('admin/settings/logo') ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input class="form-control mb-2" type="file" name="logo" accept="image/png,image/jpeg" required>
                    <div class="form-text mb-2">PNG or JPEG, max <?= (int) config('Qms')->logoMaxKb ?> KB. It is re-encoded and resized to fit 600 × 200 px.</div>
                    <button class="btn btn-outline-primary" type="submit"><?= qms_icon('bi-upload') ?> Upload logo</button>
                </form>
            </div></div>
        <?php elseif ($key === 'documents'): ?>
            <div class="card"><div class="card-header">Next report number</div><div class="card-body">
                <p class="mb-1">With the current settings the next Setup Change Approval will look like:</p>
                <p class="fs-5 fw-bold num mb-0"><?= esc($nextNumber) ?></p>
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
                    <?php if ($google['email'] !== null): ?><dt>Share the sheet with</dt><dd class="text-break"><code><?= esc($google['email']) ?></code></dd><?php endif ?>
                </dl>
                <p class="small text-muted"><?= esc($google['hint']) ?></p>
                <form method="post" action="<?= site_url('admin/settings/google-test') ?>">
                    <?= csrf_field() ?>
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
            <?php foreach ($reportTypes as $rt): $fid = 'rt-' . $rt['id']; ?>
                <tr>
                    <td><strong><?= esc($rt['name']) ?></strong><div class="small text-muted"><?= esc($rt['code']) ?> · <?= esc(str_replace('_', ' ', strtolower($rt['layout']))) ?></div></td>
                    <td><input form="<?= $fid ?>" class="form-control" name="doc_prefix" maxlength="10" value="<?= esc($rt['doc_prefix'], 'attr') ?>" aria-label="Prefix for <?= esc($rt['name'], 'attr') ?>"></td>
                    <?php foreach (['requires_production_verification', 'requires_quality_verification', 'requires_qa_approval'] as $col): ?>
                        <td class="text-center"><input form="<?= $fid ?>" class="form-check-input" type="checkbox" name="<?= $col ?>" value="1"<?= (int) $rt[$col] === 1 ? ' checked' : '' ?> aria-label="<?= esc($col, 'attr') ?>"></td>
                    <?php endforeach ?>
                    <td class="text-end">
                        <form id="<?= $fid ?>" method="post" action="<?= site_url('admin/settings/report-types/' . $rt['id']) ?>"><?= csrf_field() ?>
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
<?= $this->endSection() ?>

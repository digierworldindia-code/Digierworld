<?php
/**
 * One-time setup form (shared hosting): database, site address, company and
 * the first administrator.
 *
 * @var list<array{label: string, ok: bool, detail: string, required: bool}> $checks
 * @var bool                  $blocked
 * @var bool                  $keyReady
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<string>          $log
 * @var list<string>          $timezones
 */
$field = static function (string $name, string $label, string $help = '', string $type = 'text', array $attrs = []) use ($values, $errors): string {
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . esc($k, 'attr') . '="' . esc((string) $v, 'attr') . '"';
    }
    $value = $type === 'password' ? '' : ($values[$name] ?? '');

    return '<div class="mb-3"><label class="form-label" for="' . esc($name, 'attr') . '">' . esc($label) . '</label>'
        . '<input class="form-control' . field_invalid($errors, $name) . '" id="' . esc($name, 'attr') . '" name="' . esc($name, 'attr') . '" type="' . esc($type, 'attr') . '" value="' . esc($value, 'attr') . '"' . $extra . '>'
        . field_error($errors, $name)
        . ($help !== '' ? '<div class="form-text">' . esc($help) . '</div>' : '') . '</div>';
};
?>
<?= $this->extend('install/_layout') ?>
<?= $this->section('content') ?>
<p class="text-muted">This page runs once. It creates the database tables, the configuration file (<code>.env</code>) and the first administrator.</p>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold"><?= qms_icon('bi-list-check') ?> Server check</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($checks as $check): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                <span><?= $check['ok'] ? qms_icon('bi-check-circle-fill', 'text-success') : qms_icon($check['required'] ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill', $check['required'] ? 'text-danger' : 'text-warning') ?> <?= esc($check['label']) ?></span>
                <span class="small text-muted"><?= esc($check['detail']) ?></span>
            </li>
        <?php endforeach ?>
    </ul>
</div>

<?php if ($blocked): ?>
    <div class="alert alert-danger"><?= qms_icon('bi-x-octagon-fill') ?> Fix the items marked in red, then reload this page.</div>
<?php elseif (! $keyReady): ?>
    <div class="alert alert-danger"><?= qms_icon('bi-x-octagon-fill') ?> The folder <code>storage/</code> is not writable, so the setup key cannot be created.</div>
<?php else: ?>
    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger" role="alert"><?= qms_icon('bi-x-octagon-fill') ?> <?= esc($errors['general']) ?></div>
    <?php elseif ($errors !== []): ?>
        <div class="alert alert-danger" role="alert"><?= qms_icon('bi-x-octagon-fill') ?> Please correct the highlighted fields.</div>
    <?php endif ?>
    <?php if ($log !== []): ?>
        <div class="alert alert-secondary small"><ul class="mb-0"><?php foreach ($log as $line): ?><li><?= esc($line) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>

    <form method="post" action="" autocomplete="off" novalidate>
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><?= qms_icon('bi-key') ?> 1. Setup key</div>
            <div class="card-body">
                <p class="small">Open <code>storage/setup-key.txt</code> in your hosting file manager (or over SSH) and paste its content. This proves you control the server.</p>
                <?= $field('setup_key', 'Setup key', '', 'text', ['required' => 'required', 'maxlength' => 64, 'spellcheck' => 'false']) ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><?= qms_icon('bi-database') ?> 2. MySQL database</div>
            <div class="card-body">
                <p class="small">Create an empty MySQL 8 database and a user with all privileges on it (cPanel → MySQL Databases), then enter them here.</p>
                <div class="row">
                    <div class="col-md-8"><?= $field('db_host', 'Host', 'Usually localhost', 'text', ['required' => 'required', 'maxlength' => 255]) ?></div>
                    <div class="col-md-4"><?= $field('db_port', 'Port', '', 'text', ['inputmode' => 'numeric', 'maxlength' => 5]) ?></div>
                </div>
                <?= $field('db_name', 'Database name', '', 'text', ['required' => 'required', 'maxlength' => 64]) ?>
                <?= $field('db_user', 'User name', '', 'text', ['required' => 'required', 'maxlength' => 80]) ?>
                <?= $field('db_pass', 'Password', '', 'password', ['maxlength' => 200, 'autocomplete' => 'new-password']) ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><?= qms_icon('bi-building') ?> 3. Site and company</div>
            <div class="card-body">
                <?= $field('app_url', 'Site address (URL)', 'The address users type, with https:// when the site has an SSL certificate.', 'url', ['required' => 'required', 'maxlength' => 255]) ?>
                <?= $field('company_name', 'Company name', 'Printed on every report. Can be changed later in Settings.', 'text', ['required' => 'required', 'maxlength' => 150]) ?>
                <div class="mb-3">
                    <label class="form-label" for="timezone">Plant time zone</label>
                    <select class="form-select<?= field_invalid($errors, 'timezone') ?>" id="timezone" name="timezone">
                        <?php foreach ($timezones as $zone): ?>
                            <option value="<?= esc($zone, 'attr') ?>"<?= $zone === $values['timezone'] ? ' selected' : '' ?>><?= esc($zone) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?= field_error($errors, 'timezone') ?>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="demo" name="demo" value="1"<?= $values['demo'] === '1' ? ' checked' : '' ?>>
                    <label class="form-check-label" for="demo">Load demo data (sample machines, part, gauges and the three report templates) – for trying the system, not for production</label>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><?= qms_icon('bi-person-gear') ?> 4. First administrator</div>
            <div class="card-body">
                <?= $field('admin_username', 'Username', '', 'text', ['required' => 'required', 'maxlength' => 50, 'autocapitalize' => 'none']) ?>
                <?= $field('admin_name', 'Full name (optional)', '', 'text', ['maxlength' => 100]) ?>
                <?= $field('admin_email', 'E-mail (optional)', '', 'email', ['maxlength' => 150]) ?>
                <?= $field('admin_password', 'Password', 'At least 10 characters with three of: lower case, upper case, digits, symbols. Not a common password.', 'password', ['required' => 'required', 'maxlength' => 128, 'autocomplete' => 'new-password']) ?>
                <?= $field('admin_password_confirm', 'Repeat password', '', 'password', ['required' => 'required', 'maxlength' => 128, 'autocomplete' => 'new-password']) ?>
            </div>
        </div>

        <button class="btn btn-primary btn-lg" type="submit" data-once><?= qms_icon('bi-rocket-takeoff') ?> Install QMS</button>
    </form>
<?php endif ?>
<?= $this->endSection() ?>

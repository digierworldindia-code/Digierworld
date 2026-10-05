<?php
/**
 * Create or edit a master data row: master_edit.php?type=parts (new) or ?type=parts&id=5.
 * POST action=toggle retires / re-activates the row.
 */
require __DIR__ . '/includes/init.php';
require_once QMS_ROOT . '/includes/masters.php';
$user = require_permission(...MASTER_EDIT_PERMISSIONS);

$type = get('type');
$def  = master_definition($type);
require_permission($def['permission']);
$id   = get_int('id');
$list = 'masters.php?type=' . urlencode($type);

if (is_post()) {
    $self = 'master_edit.php?type=' . urlencode($type) . ($id > 0 ? '&id=' . $id : '');
    if (post('action') === 'toggle') {
        $back = post('back');
        $back = preg_match('#^masters\.php\?[A-Za-z0-9_=&%+.\-]*$#', $back) ? $back : $list;
        try {
            $active = master_toggle($type, $id, $user);
        } catch (Throwable $e) {
            back_with_error($e, $back);
        }
        done(ucfirst($def['singular']) . ($active ? ' re-activated.' : ' retired. It stays on existing reports but cannot be chosen for new ones.'), $back);
    }

    try {
        $input = [];
        foreach (array_keys($def['fields']) as $name) {
            $input[$name] = $_POST[$name] ?? null;
        }
        if ($id > 0) {
            master_update($type, $id, $input, $user);
        } else {
            master_create($type, $input, $user);
        }
    } catch (Throwable $e) {
        back_with_error($e, $self);
    }
    done(ucfirst($def['singular']) . ($id > 0 ? ' saved.' : ' created.'), $list);
}

$row     = $id > 0 ? master_find($type, $id) : null;
$isNew   = $row === null;
$options = master_select_options($type);
$errors  = form_errors();

// A retired lookup value already stored on the row stays selectable.
if ($row !== null) {
    foreach ($def['fields'] as $name => $field) {
        if (isset($field['lookup']) && $row[$name] !== null && ! isset($options[$name][(string) $row[$name]])) {
            $all = master_lookup($field['lookup'], false);
            if (isset($all[(string) $row[$name]])) {
                $options[$name][(string) $row[$name]] = $all[(string) $row[$name]] . ' (retired)';
            }
        }
    }
}

$page_title = ($isNew ? 'New ' : 'Edit ') . $def['singular'];
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1><?= $isNew ? 'New ' . e($def['singular']) : e(ucfirst($def['singular'])) . ' ' . e((string) $row[$def['code']]) ?></h1>
        <p class="qms-sub"><?= e($def['help']) ?></p></div>
    <a class="btn btn-outline-secondary" href="<?= url($list) ?>"><?= qms_icon('bi-arrow-left') ?> Back</a>
</div>
<div class="card col-xl-8"><div class="card-body">
<form method="post" action="<?= e(url('master_edit.php?type=' . urlencode($type) . ($isNew ? '' : '&id=' . (int) $row['id']))) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
    <?php foreach ($def['fields'] as $name => $field):
        $fieldType = $field['type'] ?? 'text';
        $value     = old($name) ?? ($row[$name] ?? ($fieldType === 'bool' ? '1' : ''));
        if ($fieldType === 'bool' && old('_posted') !== null) {
            $value = old($name) ?? '0';
        }
        $required  = str_contains($field['rules'], 'required');
        $locked    = ! $isNew && $name === $def['code'];
        $fid       = 'f-' . $name; ?>
        <div class="<?= $fieldType === 'textarea' ? 'col-12' : 'col-md-6' ?>">
        <?php if ($fieldType === 'bool'): ?>
            <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" role="switch" id="<?= $fid ?>" name="<?= e($name) ?>" value="1"<?= (string) $value === '1' ? ' checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="<?= $fid ?>"><?= e($field['label']) ?></label>
            </div>
        <?php else: ?>
            <label class="form-label<?= $required ? ' required' : '' ?>" for="<?= $fid ?>"><?= e($field['label']) ?></label>
            <?php if ($fieldType === 'select'): ?>
                <select class="form-select form-select-lg<?= field_invalid($errors, $name) ?>" id="<?= $fid ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?>>
                    <option value=""><?= $required ? 'Choose…' : '—' ?></option>
                    <?php foreach ($options[$name] ?? [] as $optValue => $optLabel): ?>
                        <option value="<?= e((string) $optValue) ?>"<?= (string) $value === (string) $optValue ? ' selected' : '' ?>><?= e($optLabel) ?></option>
                    <?php endforeach ?>
                </select>
            <?php elseif ($fieldType === 'textarea'): ?>
                <textarea class="form-control<?= field_invalid($errors, $name) ?>" id="<?= $fid ?>" name="<?= e($name) ?>" rows="3"><?= e((string) $value) ?></textarea>
            <?php else: ?>
                <input class="form-control form-control-lg<?= field_invalid($errors, $name) ?>" id="<?= $fid ?>" name="<?= e($name) ?>"
                       type="<?= in_array($fieldType, ['date', 'time', 'number'], true) ? $fieldType : 'text' ?>"
                       value="<?= e($fieldType === 'time' ? substr((string) $value, 0, 5) : (string) $value) ?>"
                       <?= $required ? 'required' : '' ?> <?= $locked ? 'readonly' : '' ?>>
            <?php endif ?>
            <?php if ($locked): ?><div class="form-text">Codes cannot be changed.</div><?php endif ?>
        <?php endif ?>
        <?= field_error($errors, $name) ?>
        </div>
    <?php endforeach ?>
    </div>
    <input type="hidden" name="_posted" value="1">
    <button class="btn btn-primary btn-lg mt-4" type="submit" data-once><?= qms_icon('bi-check2') ?> <?= $isNew ? 'Create' : 'Save' ?></button>
</form>
</div></div>
<?php require QMS_ROOT . '/includes/layout/footer.php';

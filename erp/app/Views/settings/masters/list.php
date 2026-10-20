<?= $this->extend('layouts/app') ?>
<?php
$input = static function (string $field, array $f, $value, string $form): string {
    $type = $f[1];
    $id   = $form . '_' . $field;
    $common = 'form="' . $form . '" name="' . $field . '" id="' . $id . '" aria-label="' . esc($f[0], 'attr') . '"';

    return match ($type) {
        'bool'   => '<input type="checkbox" class="form-check-input" value="1" ' . $common . ($value ? ' checked' : '') . '>',
        'select' => (static function () use ($f, $value, $common) {
            $h = '<select class="form-select form-select-sm" ' . $common . '><option value="">—</option>';
            foreach ($f[3] as $k => $l) {
                $h .= '<option value="' . esc((string) $k) . '"' . ((string) $value === (string) $k ? ' selected' : '') . '>' . esc($l) . '</option>';
            }

            return $h . '</select>';
        })(),
        'int', 'decimal' => '<input class="u-mxw-110px form-control form-control-sm text-end" inputmode="decimal" ' . $common . ' value="' . esc((string) $value) . '">',
        default  => '<input class="form-control form-control-sm" ' . $common . ' value="' . esc((string) $value) . '">',
    };
};
?>
<?= $this->section('content') ?>
<div class="table-card"><div class="table-responsive">
<table class="table table-sm align-middle small mb-0">
    <thead><tr><?php foreach ($def['fields'] as $f): ?><th><?= esc($f[0]) ?></th><?php endforeach ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $form = 'f' . $r['id']; ?>
        <tr><?php foreach ($def['fields'] as $field => $f): ?><td><?= $input($field, $f, $r[$field] ?? null, $form) ?></td><?php endforeach ?>
            <td><form id="<?= $form ?>" method="post" action="<?= site_url('settings/masters/' . $table . '/' . $r['id']) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Save</button></form></td></tr>
    <?php endforeach ?>
    <tr><?php foreach ($def['fields'] as $field => $f): ?><td><?= $input($field, $f, $f[1] === 'bool' && $field === 'is_active' ? 1 : null, 'fnew') ?></td><?php endforeach ?>
        <td><form id="fnew" method="post" action="<?= site_url('settings/masters/' . $table) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Add</button></form></td></tr>
    </tbody>
</table>
</div></div>
<p class="small text-muted mt-2">Entries are deactivated rather than deleted so historical records keep their references.</p>
<?= $this->endSection() ?>

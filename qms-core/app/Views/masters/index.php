<?php
/**
 * @var string $slug
 * @var array<string, mixed> $def
 * @var list<array<string, mixed>> $rows
 * @var array<string, array<string, string>> $options
 */
$display = static function (array $def, array $options, string $column, array $row): string {
    $field = $def['fields'][$column];
    $value = $row[$column] ?? null;
    if ($value === null || $value === '') {
        return '<span class="text-muted">—</span>';
    }

    return match ($field['type'] ?? 'text') {
        'select' => esc($options[$column][(string) $value] ?? (string) $value),
        'bool'   => (int) $value === 1 ? 'Yes' : 'No',
        'date'   => esc(plant_date((string) $value)),
        'time'   => esc(substr((string) $value, 0, 5)),
        default  => esc((string) $value),
    };
};
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1><?= esc($def['title']) ?></h1><p class="qms-sub"><?= esc($def['help']) ?></p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= site_url('masters') ?>"><?= qms_icon('bi-collection') ?> All masters</a>
        <?php if ($canEdit): ?><a class="btn btn-primary btn-lg" href="<?= site_url('masters/' . $slug . '/new') ?>"><?= qms_icon('bi-plus-lg') ?> New <?= esc($def['singular']) ?></a><?php endif ?>
    </div>
</div>
<form class="qms-filter row g-2 align-items-end" method="get">
    <div class="col-md-6"><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= esc($q, 'attr') ?>"></div>
    <div class="col-md-4"><label class="form-label" for="status">Show</label>
        <select class="form-select" id="status" name="status">
            <?php foreach (['active' => 'Active', 'retired' => 'Retired', 'all' => 'All'] as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach ?>
        </select></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr>
        <?php foreach ($def['list'] as $column): ?><th><?= esc($def['fields'][$column]['label']) ?></th><?php endforeach ?>
        <th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="<?= count($def['list']) + 2 ?>" class="qms-empty"><?= qms_icon($def['icon']) ?>Nothing here yet.</td></tr><?php endif ?>
    <?php foreach ($rows as $row): ?>
        <tr>
            <?php foreach ($def['list'] as $i => $column): ?>
                <td><?= $i === 0 ? '<strong>' . $display($def, $options, $column, $row) . '</strong>' : $display($def, $options, $column, $row) ?></td>
            <?php endforeach ?>
            <td><?= active_badge($row['is_active']) ?></td>
            <td class="text-end text-nowrap">
                <?php if ($canEdit): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?= site_url('masters/' . $slug . '/' . $row['id'] . '/edit') ?>"><?= qms_icon('bi-pencil') ?> Edit</a>
                    <form class="d-inline" method="post" action="<?= site_url('masters/' . $slug . '/' . $row['id'] . '/toggle') ?>"
                          data-confirm="<?= (int) $row['is_active'] === 1 ? 'Retire this ' . esc($def['singular'], 'attr') . '? It stays on existing reports.' : 'Re-activate this ' . esc($def['singular'], 'attr') . '?' ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $row['is_active'] === 1 ? qms_icon('bi-archive') . ' Retire' : qms_icon('bi-arrow-counterclockwise') . ' Re-activate' ?></button>
                    </form>
                <?php endif ?>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['paging' => $paging]) ?>
<?= $this->endSection() ?>

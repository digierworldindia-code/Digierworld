<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/** @var string $key @var array $definition @var array $result @var array $filters @var array $page
 * @var list<array> $dealers @var list<array> $products @var list<string> $statuses */
$query = array_filter($filters, static fn ($v) => $v !== null && $v !== '');
$money = $definition['money'] ?? [];
?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/reports') ?>">Reports</a></div>
    <h1><?= esc($definition['title']) ?></h1><p><?= esc($definition['description']) ?> · <?= number_format($result['total']) ?> row(s)</p></div>
    <?php if ($ctx->can('report:export')): ?>
    <div class="d-flex gap-2">
        <a class="btn btn-light" href="<?= site_url('admin/reports/' . $key . '/export/csv') . ($query ? '?' . http_build_query($query) : '') ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a class="btn btn-light" href="<?= site_url('admin/reports/' . $key . '/export/xlsx') . ($query ? '?' . http_build_query($query) : '') ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    </div>
    <?php endif ?>
</div>

<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <?php if (in_array('from', $definition['filters'], true)): ?>
        <div><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= esc($filters['from'] ?? '', 'attr') ?>"></div>
        <div><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= esc($filters['to'] ?? '', 'attr') ?>"></div>
        <?php endif ?>
        <?php if ($dealers !== []): ?>
        <div><label class="form-label" for="dealer">Dealer</label><select class="form-select" id="dealer" name="dealer" data-autosubmit><option value="">All dealers</option>
            <?php foreach ($dealers as $d): ?><option value="<?= $d['id'] ?>"<?= ($filters['dealer'] ?? null) === $d['id'] ? ' selected' : '' ?>><?= esc($d['business_name']) ?></option><?php endforeach ?></select></div>
        <?php endif ?>
        <?php if ($products !== []): ?>
        <div><label class="form-label" for="product">Product</label><select class="form-select" id="product" name="product" data-autosubmit><option value="">All products</option>
            <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"<?= ($filters['product'] ?? null) === $p['id'] ? ' selected' : '' ?>><?= esc($p['name']) ?></option><?php endforeach ?></select></div>
        <?php endif ?>
        <?php if ($statuses !== []): ?>
        <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><option value="">Any status</option>
            <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= ($filters['status'] ?? null) === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
        <?php endif ?>
        <div><button class="btn btn-light" type="submit">Apply</button> <?php if ($query): ?><a class="btn btn-link" href="<?= site_url('admin/reports/' . $key) ?>">Clear</a><?php endif ?></div>
    </form>

    <div class="table-responsive"><table class="table">
        <thead><tr><?php foreach ($definition['columns'] as $column => $label): ?>
            <th<?= in_array($column, $money, true) || str_contains($column, 'units') || str_contains($column, 'score') ? ' class="num"' : '' ?>><?= esc($label) ?></th>
        <?php endforeach ?></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
        <tr>
            <?php foreach ($definition['columns'] as $column => $label): ?>
                <?php $value = $row[$column] ?? null; ?>
                <?php if (in_array($column, $money, true)): ?>
                    <td class="num"><?= $value === null ? '—' : inr((float) $value) ?></td>
                <?php elseif (is_numeric($value) && ! in_array($column, ['claim_number', 'serial_number', 'invoice_number', 'code', 'month'], true)): ?>
                    <td class="num"><?= esc(rtrim(rtrim(number_format((float) $value, 1, '.', ','), '0'), '.')) ?></td>
                <?php elseif (in_array($column, ['status', 'risk_level', 'warranty_status'], true) && $value !== null): ?>
                    <td><?= pill((string) $value) ?></td>
                <?php elseif (str_ends_with($column, '_on') || $column === 'manufactured_on'): ?>
                    <td class="small text-nowrap"><?= esc(local_date($value)) ?></td>
                <?php elseif ($column === 'month'): ?>
                    <td class="small text-nowrap"><?= esc($value ? date('M Y', strtotime($value . '-01')) : '—') ?></td>
                <?php else: ?>
                    <td class="small"><?= esc((string) ($value ?? '—')) ?></td>
                <?php endif ?>
            <?php endforeach ?>
        </tr>
        <?php endforeach ?>
        <?php if ($result['rows'] === []): ?><tr><td colspan="<?= count($definition['columns']) ?>" class="table-empty">Nothing matches these filters.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $page]) ?>
</div>
<p class="small text-muted">Exports include every matching row, not just this page, and are recorded in the audit trail.</p>
<?= $this->endSection() ?>

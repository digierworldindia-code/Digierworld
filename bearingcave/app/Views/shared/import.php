<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $editable = in_array($job['status'], ['uploaded', 'mapped', 'validated'], true); ?>
<?= view('components/page_header', ['title' => $job['original_name'], 'subtitle' => (int) $job['total_rows'] . ' data rows · target supplier: ' . ($company['legal_name'] ?? '—'), 'breadcrumbs' => ['Imports' => $base, 'Job' => null]]) ?>
<div class="d-flex gap-2 mb-3 small"><?php foreach (['uploaded' => '1. Upload', 'mapped' => '2. Map columns', 'validated' => '3. Preview', 'completed' => '4. Imported'] as $s => $l): ?><span class="badge-soft <?= $job['status'] === $s ? 'info' : 'neutral' ?>"><?= $l ?></span><?php endforeach ?><?= in_array($job['status'], ['cancelled', 'failed'], true) ? status_badge($job['status']) : '' ?></div>
<?php if ($editable): ?>
<div class="bc-card mb-4"><div class="bc-card-header"><h2>Map your columns</h2><span class="small text-muted">Matching headings were mapped automatically</span></div><div class="bc-card-body">
    <form method="post" action="<?= site_url($base . '/' . $job['uuid'] . '/map') ?>">
        <?= csrf_field() ?>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Your column</th><th>Sample values</th><th style="min-width:240px">Maps to</th></tr></thead>
            <tbody><?php foreach ($headers as $i => $h): ?><tr>
                <td class="fw-semibold"><?= esc($h) ?></td>
                <td class="small text-muted"><?= esc(implode(' · ', array_filter(array_map(static fn ($r) => mb_strimwidth((string) ((json_decode($r['data'], true)['raw'] ?? json_decode($r['data'], true))[$i] ?? ''), 0, 24, '…'), $sample)))) ?></td>
                <td><select class="form-select form-select-sm" name="map[<?= $i ?>]" aria-label="Target for <?= esc($h, 'attr') ?>"><option value="">— Ignore —</option><?php foreach ($fields as $k => [$label, $req]): ?><option value="<?= $k ?>"<?= ($map[$i] ?? '') === $k ? ' selected' : '' ?>><?= esc($label) ?><?= $req ? ' *' : '' ?></option><?php endforeach ?></select></td>
            </tr><?php endforeach ?></tbody>
        </table></div>
        <button class="btn btn-primary" type="submit">Validate &amp; preview</button>
    </form>
</div></div>
<?php endif ?>
<?php if (in_array($job['status'], ['validated', 'completed'], true)): ?>
<div id="preview" class="row g-3 mb-4">
    <?php foreach ([['Valid rows', $job['valid_rows'], 'success'], ['Rows with errors', $job['error_rows'], 'danger'], ['Duplicates', $job['duplicate_rows'], 'warning'], ['Imported', $job['imported_rows'], 'info']] as [$l, $v, $t]): ?>
    <div class="col-6 col-md-3"><div class="bc-card bc-kpi"><div class="kpi-value"><?= (int) $v ?></div><div class="kpi-label"><span class="badge-soft <?= $t ?>"><?= $l ?></span></div></div></div>
    <?php endforeach ?>
</div>
<?php if ($job['status'] === 'validated'): ?>
<div class="bc-card mb-4"><div class="bc-card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div class="small">Only <strong><?= (int) $job['valid_rows'] ?></strong> valid rows will be imported. Rows with errors and duplicate SKUs are skipped — fix them in your file and upload again.</div>
    <form method="post" action="<?= site_url($base . '/' . $job['uuid'] . '/confirm') ?>" class="d-flex flex-wrap gap-2 align-items-center">
        <?= csrf_field() ?>
        <div class="form-check me-2"><input class="form-check-input" type="checkbox" name="publish" value="1" id="pub" checked><label class="form-check-label small" for="pub">Submit for publishing after import</label></div>
        <button class="btn btn-light btn-sm" type="submit" name="action" value="cancel" data-confirm="Cancel this import?">Cancel import</button>
        <button class="btn btn-primary btn-sm" type="submit" name="action" value="import" data-confirm="Import <?= (int) $job['valid_rows'] ?> products now?"<?= (int) $job['valid_rows'] === 0 ? ' disabled' : '' ?>>Confirm import</button>
    </form>
</div></div>
<?php endif ?>
<?php foreach (['Errors' => $errors, 'Duplicates' => $duplicates] as $label => $rows): if (! $rows) { continue; } ?>
<div class="bc-card mb-4"><div class="bc-card-header"><h2><?= $label ?> (first <?= count($rows) ?>)</h2></div><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Row</th><th>SKU</th><th>Part number</th><th>Problem</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $d = json_decode($r['data'], true)['mapped'] ?? []; ?><tr><td><?= (int) $r['row_number'] ?></td><td class="part-no"><?= esc($d['sku'] ?? '') ?></td><td class="part-no"><?= esc($d['part_number'] ?? '') ?></td><td class="text-danger"><?= esc(implode('; ', (array) json_decode((string) $r['errors'], true))) ?></td></tr><?php endforeach ?>
</tbody></table></div></div>
<?php endforeach ?>
<?php if ($valid): ?>
<div class="bc-card"><div class="bc-card-header"><h2><?= $job['status'] === 'completed' ? 'Imported rows (sample)' : 'Valid rows (preview)' ?></h2></div><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Row</th><th>SKU</th><th>Part number</th><th>Name</th><th class="text-end">Qty</th><th>Received</th><th>Price</th></tr></thead><tbody>
    <?php foreach ($valid as $r): $d = json_decode($r['data'], true)['mapped'] ?? []; ?><tr><td><?= (int) $r['row_number'] ?></td><td class="part-no"><?= esc($d['sku'] ?? '') ?></td><td class="part-no"><?= esc($d['part_number'] ?? '') ?></td><td><?= esc($d['name'] ?? '') ?></td><td class="text-end"><?= esc($d['quantity'] ?? '') ?></td><td><?= esc($d['received_on'] ?? '') ?></td><td><?= esc(($d['sale_mode'] ?? '') === 'lot' ? ($d['lot_price'] ?? '') . ' / lot' : ($d['unit_price'] ?? '') . ' / pc') ?> <?= esc($d['currency'] ?? '') ?></td></tr><?php endforeach ?>
</tbody></table></div></div>
<?php endif ?>
<?php endif ?>
<?= $this->endSection() ?>

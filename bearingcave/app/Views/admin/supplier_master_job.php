<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $opts = array_column($fields, 'source_heading', 'field_key'); ?>
<?= view('components/page_header', ['title' => $job['original_name'], 'subtitle' => (int) $job['total_rows'] . ' rows · ' . count($headers) . ' columns', 'breadcrumbs' => ['Supplier master' => 'admin/supplier-master', 'Import' => null]]) ?>
<?php if (in_array($job['status'], ['uploaded', 'mapped', 'validated'], true)): ?>
<div class="bc-card mb-4"><div class="bc-card-header"><h2>Map columns</h2><span class="small text-muted">Unmapped columns are registered as new fields — nothing is dropped</span></div><div class="bc-card-body">
<form method="post" action="<?= site_url('admin/supplier-master/' . $job['uuid'] . '/map') ?>"><?= csrf_field() ?><div class="row g-2">
<?php foreach ($headers as $i => $h): ?><div class="col-md-6 col-xl-4"><label class="form-label small" for="m<?= $i ?>"><?= esc($h) ?></label><select class="form-select form-select-sm" id="m<?= $i ?>" name="map[<?= $i ?>]"><option value="">→ New field “<?= esc(mb_strimwidth($h, 0, 30, '…')) ?>”</option><?php foreach ($opts as $k => $l): ?><option value="<?= $k ?>"<?= ($map[$i] ?? '') === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div><?php endforeach ?>
</div><button class="btn btn-primary mt-3" type="submit">Validate</button></form></div></div>
<?php endif ?>
<?php if ($job['status'] === 'validated'): ?>
<div class="bc-card mb-4"><div class="bc-card-body d-flex flex-wrap justify-content-between align-items-center gap-2"><span class="small"><?= (int) $job['valid_rows'] ?> new · <?= (int) $job['duplicate_rows'] ?> existing/duplicate · <?= (int) $job['error_rows'] ?> errors</span>
<form method="post" action="<?= site_url('admin/supplier-master/' . $job['uuid'] . '/confirm') ?>" class="d-flex gap-2 align-items-center" data-confirm="Import these supplier records?"><?= csrf_field() ?><div class="form-check"><input class="form-check-input" type="checkbox" name="update_existing" value="1" id="ue"><label class="form-check-label small" for="ue">Update matching existing suppliers</label></div><button class="btn btn-primary btn-sm" type="submit">Import</button></form></div></div>
<?php endif ?>
<div class="bc-card"><div class="table-responsive" style="max-height:60vh"><table class="table table-bc mb-0 small"><thead class="sticky-top"><tr><th>Row</th><th>Supplier</th><th>Country</th><th>Status</th><th>Notes</th></tr></thead><tbody>
<?php foreach ($rows as $r): $d = json_decode($r['data'], true); $t = $d['targets'] ?? []; ?><tr><td><?= (int) $r['row_number'] ?></td><td><?= esc($t['companies.legal_name'] ?? (is_array($d) && isset($d[0]) ? (string) $d[0] : '')) ?></td><td><?= esc($t['companies.country_code'] ?? '') ?></td><td><?= status_badge($r['status']) ?></td><td class="<?= $r['status'] === 'error' ? 'text-danger' : 'text-muted' ?>"><?= esc(implode('; ', (array) json_decode((string) $r['errors'], true))) ?></td></tr><?php endforeach ?>
</tbody></table></div></div>
<?= $this->endSection() ?>

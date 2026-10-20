<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php if ($preview === null): ?>
<form method="post" action="<?= site_url('finance/reconciliation/import') ?>" enctype="multipart/form-data" class="u-mxw-720px card"><div class="card-body">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label required" for="account_id">Bank account</label><select class="form-select" id="account_id" name="account_id" required><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= (int) $a['id'] === $accId ? 'selected' : '' ?>><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div>
        <div class="col-md-6"><label class="form-label required" for="file">Statement file</label><input type="file" class="form-control" id="file" name="file" accept=".csv,.xlsx" required></div>
    </div>
    <p class="small text-muted mt-3 mb-0">Download the statement from net banking as CSV or Excel (.xlsx). Bank name/account rows at the top are fine — the transaction table is detected and you confirm the columns before anything is imported. Lines already imported are skipped automatically.</p>
</div><div class="card-footer text-end"><button class="btn btn-primary" type="submit">Upload &amp; preview</button></div></form>
<?php else: $p = $preview; $ncol = max(array_map('count', $p['rows'] ?: [[]])); ?>
<form method="get" action="<?= site_url('finance/reconciliation/map') ?>" class="card mb-3"><div class="card-body">
    <input type="hidden" name="file" value="<?= (int) $p['file'] ?>"><input type="hidden" name="account" value="<?= (int) $accId ?>">
    <div class="card-section-title">1. Check the columns — <?= esc($p['name']) ?></div>
    <div class="row g-2 small">
        <div class="col-md-3"><label class="form-label" for="header">Header row</label><select class="form-select form-select-sm" id="header" name="header"><?php foreach ($p['rows'] as $i => $r): ?><option value="<?= $i ?>" <?= $i === $p['header'] ? 'selected' : '' ?>>Row <?= $i + 1 ?>: <?= esc(mb_strimwidth(implode(' | ', array_filter($r)), 0, 60, '…')) ?></option><?php endforeach ?></select></div>
        <?php foreach (\App\Libraries\BankStatementParser::FIELDS as $f => $label): ?>
        <div class="col-6 col-md-3"><label class="form-label" for="map_<?= $f ?>"><?= esc($label) ?></label><select class="form-select form-select-sm" id="map_<?= $f ?>" name="map_<?= $f ?>"><option value="">— not in file —</option>
            <?php for ($c = 0; $c < $ncol; $c++): ?><option value="<?= $c ?>" <?= isset($p['map'][$f]) && $p['map'][$f] === $c ? 'selected' : '' ?>><?= esc(($p['header'] >= 0 ? ($p['rows'][$p['header']][$c] ?? '') : '') ?: 'Column ' . ($c + 1)) ?></option><?php endfor ?></select></div>
        <?php endforeach ?>
    </div>
    <button class="btn btn-outline-secondary btn-sm mt-2" type="submit">Re-read with these columns</button>
</div></form>

<div class="card mb-3"><div class="card-body">
    <div class="card-section-title">2. Preview — <?= count($p['parsed']['lines']) ?> transactions found</div>
    <?php if ($p['parsed']['errors'] !== []): ?><div class="alert alert-warning small"><?php foreach (array_slice($p['parsed']['errors'], 0, 10) as $e): ?><div><?= esc($e) ?></div><?php endforeach ?></div><?php endif ?>
    <div class="table-responsive"><table class="table table-sm small">
        <thead><tr><th>Date</th><th>Description</th><th>Reference</th><th class="num">Out</th><th class="num">In</th><th class="num">Balance</th></tr></thead><tbody>
        <?php foreach (array_slice($p['parsed']['lines'], 0, 15) as $l): ?><tr><td><?= dmy($l['txn_date']) ?></td><td><?= esc($l['description']) ?></td><td class="mono"><?= esc((string) $l['reference']) ?></td><td class="num"><?= (float) $l['debit'] ? inr($l['debit']) : '' ?></td><td class="num"><?= (float) $l['credit'] ? inr($l['credit']) : '' ?></td><td class="num"><?= $l['balance'] !== null ? inr($l['balance']) : '' ?></td></tr><?php endforeach ?>
        </tbody></table></div>
    <?php if (count($p['parsed']['lines']) > 15): ?><div class="small text-muted">…and <?= count($p['parsed']['lines']) - 15 ?> more.</div><?php endif ?>
</div></div>

<?php if ($p['parsed']['lines'] !== []): ?>
<form method="post" action="<?= site_url('finance/reconciliation/import/commit') ?>" class="text-end">
    <?= csrf_field() ?>
    <input type="hidden" name="file" value="<?= (int) $p['file'] ?>"><input type="hidden" name="account_id" value="<?= (int) $accId ?>"><input type="hidden" name="header" value="<?= (int) $p['header'] ?>">
    <?php foreach (array_keys(\App\Libraries\BankStatementParser::FIELDS) as $f): ?><input type="hidden" name="map_<?= $f ?>" value="<?= isset($p['map'][$f]) && $p['map'][$f] !== null ? (int) $p['map'][$f] : '' ?>"><?php endforeach ?>
    <a href="<?= site_url('finance/reconciliation/import?account=' . $accId) ?>" class="btn btn-outline-secondary">Start over</a>
    <button class="btn btn-primary" type="submit">Import <?= count($p['parsed']['lines']) ?> transactions</button>
</form>
<?php endif ?>
<?php endif ?>
<?= $this->endSection() ?>

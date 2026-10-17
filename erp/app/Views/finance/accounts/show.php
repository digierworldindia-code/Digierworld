<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('reports.export')): ?>
<div class="btn-group btn-group-sm"><a class="btn btn-outline-dark" href="<?= site_url('finance/accounts/' . $acc['id'] . '/export?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><a class="btn btn-outline-dark" href="<?= site_url('finance/accounts/' . $acc['id'] . '/export?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'csv'])) ?>">CSV</a></div>
<?php endif ?>
<?php if ($acc['account_kind'] !== 'cash' && can('finance.bank.import')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('finance/reconciliation/import?account=' . $acc['id']) ?>"><i class="bi bi-upload"></i> Import statement</a><?php endif ?>
<?php if (can('finance.transfer.create')): ?><a class="btn btn-gold btn-sm" href="<?= site_url('finance/transfers/new?from=' . $acc['id']) ?>"><i class="bi bi-arrow-left-right"></i> Transfer</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($acc['ownership'] === 'owner_personal'): ?><div class="alert alert-warning small"><strong>Owner personal account.</strong> Business receipts here are flagged for review. Accountant's classification: <?= esc((string) $acc['classification_note']) ?></div><?php endif ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-6 col-md-3"><label class="form-label small" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= esc($from) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= esc($to) ?>"></div>
    <div class="col-md-2 align-self-end"><button class="btn btn-dark btn-sm w-100">Show</button></div>
</form>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Opening (<?= dmy($from) ?>)</div><div class="kpi-value"><?= inr($opening) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Money in</div><div class="kpi-value text-success"><?= inr($inflow) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Money out</div><div class="kpi-value text-danger"><?= inr($outflow) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card accent"><div class="kpi-label">Closing (<?= dmy($to) ?>)</div><div class="kpi-value"><?= inr($closing) ?></div></div></div>
</div>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th>Reference</th><th class="num">Money in</th><th class="num">Money out</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <tr class="table-light"><td><?= dmy($from) ?></td><td></td><td colspan="4"><strong>Opening balance</strong></td><td class="num fw-semibold"><?= inr($opening) ?></td></tr>
    <?php foreach ($lines as $l): [$label, $url] = journal_source($l['source_type'], $l['source_id'] === null ? null : (int) $l['source_id'], (int) $l['entry_id']); ?>
    <tr>
        <td><?= dmy($l['entry_date']) ?></td>
        <td class="mono"><?= can('finance.ledger.view') ? '<a href="' . site_url('finance/ledger/entries/' . $l['entry_id']) . '">' . esc($l['entry_no']) . '</a>' : esc($l['entry_no']) ?></td>
        <td><?= $url ? '<a href="' . $url . '">' . esc($l['narration']) . '</a>' : esc($l['narration']) ?><div class="text-muted"><?= esc($label) ?><?= $l['contact_name'] ? ' · ' . esc($l['contact_name']) : '' ?></div></td>
        <td class="mono"><?= esc((string) $l['reference']) ?></td>
        <td class="num text-success"><?= (float) $l['debit'] > 0 ? inr($l['debit']) : '' ?></td>
        <td class="num text-danger"><?= (float) $l['credit'] > 0 ? inr($l['credit']) : '' ?></td>
        <td class="num"><?= inr($l['running']) ?></td>
    </tr>
    <?php endforeach ?>
    <?php if ($lines === []): ?><tr><td colspan="7" class="text-muted">No movements in this period.</td></tr><?php endif ?>
    <tr class="table-light"><td><?= dmy($to) ?></td><td></td><td colspan="4"><strong>Closing balance</strong></td><td class="num fw-semibold"><?= inr($closing) ?></td></tr>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>

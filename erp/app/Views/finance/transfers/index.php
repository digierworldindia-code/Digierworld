<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('reports.export')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('finance/transfers/export?' . http_build_query(array_filter($f) + ['format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><?php endif ?>
<?php if (can('finance.transfer.create')): ?>
<a href="<?= site_url('finance/transfers/new?preset=deposit') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-in-down"></i> Deposit cash</a>
<a href="<?= site_url('finance/transfers/new') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New transfer</a>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="alert alert-secondary border small"><i class="bi bi-info-circle"></i> Transfers move money between the company's own accounts (e.g. depositing collected cash into the bank). They are <strong>not income</strong> and never appear in sales, collections or GST.</div>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-3"><input type="search" name="q" value="<?= esc($f['q']) ?>" class="form-control form-control-sm" placeholder="Number, reference, notes" aria-label="Search"></div>
    <div class="col-6 col-md-2"><select name="kind" class="form-select form-select-sm" data-autosubmit aria-label="Kind"><option value="">Any kind</option><?php foreach ($kinds as $k => $l): ?><option value="<?= $k ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-3"><select name="account" class="form-select form-select-sm" data-autosubmit aria-label="Account"><option value="">Any account</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $f['account'] === (int) $a['id'] ? 'selected' : '' ?>><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><input type="date" name="from" value="<?= esc($f['from'] ?? '') ?>" class="form-control form-control-sm" aria-label="From"></div>
    <div class="col-6 col-md-2"><input type="date" name="to" value="<?= esc($f['to'] ?? '') ?>" class="form-control form-control-sm" aria-label="To"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filter</button></div>
    <div class="col-md-10 small text-md-end align-self-center">Total moved (active): <strong><?= inr($sum) ?></strong></div>
</form>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Number</th><th>Date</th><th>Kind</th><th>From</th><th>To</th><th class="num">Amount</th><th>Reference</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><a class="mono fw-semibold" href="<?= site_url('finance/transfers/' . $r['id']) ?>"><?= esc($r['transfer_number']) ?></a></td>
        <td><?= dmy($r['transfer_date']) ?></td><td><?= esc($kinds[$r['transfer_kind']] ?? $r['transfer_kind']) ?></td>
        <td><?= esc($r['from_name']) ?></td><td><?= esc($r['to_name']) ?></td><td class="num"><?= inr($r['amount']) ?></td>
        <td class="mono"><?= esc((string) $r['reference_no']) ?></td><td><?= status_badge($r['status']) ?></td>
    </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-arrow-left-right"></i>No transfers yet.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>

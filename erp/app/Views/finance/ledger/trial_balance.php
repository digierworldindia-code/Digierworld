<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('reports.export')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('finance/ledger/trial-balance?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?= view('finance/ledger/_tabs', ['active' => 'finance/ledger/trial-balance']) ?>
<?= view('finance/ledger/_range', ['from' => $from, 'to' => $to]) ?>
<?php $balanced = \App\Libraries\Dec::cmp($tot['dr'], $tot['cr']) === 0 && \App\Libraries\Dec::cmp($tot['pd'], $tot['pc']) === 0; ?>
<div class="alert <?= $balanced ? 'alert-success' : 'alert-danger' ?> small"><?= $balanced ? '<i class="bi bi-check-circle"></i> Debits equal credits.' : '<i class="bi bi-exclamation-triangle"></i> Trial balance does not tally — check Admin → Diagnostics.' ?></div>
<div class="table-card"><div class="table-responsive">
<table class="table align-middle small">
    <thead><tr><th>Code</th><th>Ledger</th><th>Group</th><th class="num">Period Dr</th><th class="num">Period Cr</th><th class="num">Closing Dr</th><th class="num">Closing Cr</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $c = (float) $r['closing']; ?>
    <tr><td class="mono"><?= esc($r['code']) ?></td><td><a href="<?= site_url('finance/ledger/accounts/' . $r['id'] . '?' . http_build_query(['from' => $from, 'to' => $to])) ?>"><?= esc($r['name']) ?></a></td><td><?= esc($groups[$r['account_group']]) ?></td>
        <td class="num"><?= (float) $r['debit'] ? inr($r['debit']) : '' ?></td><td class="num"><?= (float) $r['credit'] ? inr($r['credit']) : '' ?></td>
        <td class="num"><?= $c > 0 ? inr($r['closing']) : '' ?></td><td class="num"><?= $c < 0 ? inr(\App\Libraries\Dec::abs($r['closing'])) : '' ?></td></tr>
    <?php endforeach ?>
    <tr class="table-light fw-semibold"><td colspan="3">Total</td><td class="num"><?= inr($tot['pd']) ?></td><td class="num"><?= inr($tot['pc']) ?></td><td class="num"><?= inr($tot['dr']) ?></td><td class="num"><?= inr($tot['cr']) ?></td></tr>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>

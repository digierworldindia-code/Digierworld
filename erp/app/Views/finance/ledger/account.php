<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?= view('finance/ledger/_tabs', ['active' => 'finance/ledger/accounts']) ?>
<?= view('finance/ledger/_range', ['from' => $from, 'to' => $to, 'keep' => $contact ? ['contact' => $contact['id']] : []]) ?>
<?php if ($contact): ?><div class="alert alert-secondary border small">Showing only lines for <strong><?= esc($contact['name']) ?></strong>. <a href="<?= site_url('finance/ledger/accounts/' . $acc['id'] . '?' . http_build_query(['from' => $from, 'to' => $to])) ?>">Show all</a></div><?php endif ?>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th>Party</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <tr><td><?= dmy($from) ?></td><td></td><td colspan="4"><strong>Opening balance</strong></td><td class="num fw-semibold"><?= dr_cr($opening) ?></td></tr>
    <?php foreach ($lines as $l): [$label, $url] = journal_source($l['source_type'], $l['source_id'] === null ? null : (int) $l['source_id'], (int) $l['entry_id']); ?>
    <tr><td><?= dmy($l['entry_date']) ?></td><td><a class="mono" href="<?= site_url('finance/ledger/entries/' . $l['entry_id']) ?>"><?= esc($l['entry_no']) ?></a></td>
        <td><?= $url ? '<a href="' . $url . '">' . esc($l['narration']) . '</a>' : esc($l['narration']) ?></td><td><?= esc((string) $l['contact_name']) ?></td>
        <td class="num"><?= (float) $l['debit'] ? inr($l['debit']) : '' ?></td><td class="num"><?= (float) $l['credit'] ? inr($l['credit']) : '' ?></td><td class="num"><?= dr_cr($l['running']) ?></td></tr>
    <?php endforeach ?>
    <?php if ($lines === []): ?><tr><td colspan="7" class="text-muted">No movements in this period.</td></tr><?php endif ?>
    <tr><td><?= dmy($to) ?></td><td></td><td colspan="4"><strong>Closing balance</strong></td><td class="num fw-semibold"><?= dr_cr($closing) ?></td></tr>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>

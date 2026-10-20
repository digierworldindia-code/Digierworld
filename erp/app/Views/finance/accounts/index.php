<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('finance.transfer.create')): ?><a href="<?= site_url('finance/transfers/new?preset=deposit') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-in-down"></i> Deposit cash to bank</a><?php endif ?>
<?php if (can('settings.bank.edit')): ?><a href="<?= site_url('settings/banks/new') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add account</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3"><div class="kpi-card accent"><div class="kpi-label">Total book balance (active)</div><div class="kpi-value"><?= inr($total) ?></div><div class="kpi-sub">Cash + bank + UPI clearing, as per books</div></div></div>
</div>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Account</th><th>Type</th><th>Bank</th><th class="num">Book balance</th><th>Statement imported to</th><th class="num">Unmatched lines</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr class="<?= (int) $r['is_active'] === 1 ? '' : 'text-muted' ?>">
        <td><a class="fw-semibold" href="<?= site_url('finance/accounts/' . $r['id']) ?>"><?= esc($r['display_name']) ?></a> <span class="mono text-muted"><?= esc($r['code']) ?></span>
            <?php if ($r['ownership'] === 'owner_personal'): ?><span class="badge badge-warning">Owner personal</span><?php endif ?>
            <?php if ((int) $r['is_active'] !== 1): ?><span class="badge badge-neutral">Inactive</span><?php endif ?></td>
        <td><?= esc(['cash' => 'Cash', 'bank' => 'Bank', 'upi_clearing' => 'UPI clearing', 'card_clearing' => 'Card clearing', 'other' => 'Other'][$r['account_kind']] ?? $r['account_kind']) ?></td>
        <td><?= esc((string) $r['bank_name']) ?><?= $r['account_last4'] ? ' <span class="mono masked">••' . esc($r['account_last4']) . '</span>' : '' ?></td>
        <td class="num fw-semibold <?= (float) $r['balance'] < 0 ? 'text-danger' : '' ?>"><?= inr($r['balance']) ?></td>
        <td><?= $r['last_import'] ? dmy($r['last_import']) : ($r['account_kind'] === 'cash' ? '—' : '<span class="text-muted">never</span>') ?></td>
        <td class="num"><?= $r['unmatched'] > 0 ? '<a href="' . site_url('finance/reconciliation?account=' . $r['id']) . '">' . (int) $r['unmatched'] . '</a>' : '0' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('finance/accounts/' . $r['id']) ?>">Statement</a></td>
    </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-bank"></i>No accounts yet. Add your bank accounts in Settings → Bank &amp; Payment Profiles.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<p class="small text-muted mt-2">Book balance comes from the journal (opening balance + receipts − payments ± transfers). Compare it with the bank statement in Bank Reconciliation.</p>
<?= $this->endSection() ?>

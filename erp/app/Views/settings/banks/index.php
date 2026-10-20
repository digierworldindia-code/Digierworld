<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if ($canEdit): ?><a href="<?= site_url('settings/banks/new' . ($wizard ? '?wizard=1' : '')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add account</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($wizard): ?><?= view('settings/_wizard', ['current' => 'banks']) ?><?php endif ?>
<p class="small text-muted">Account numbers and CIF are encrypted at rest and masked everywhere. CIF is an internal field only — it is never printed on documents, exported, emailed, synced to Google Sheets or sent to AI.</p>
<div class="row g-3">
<?php foreach ($rows as $a): ?>
    <div class="col-md-6 col-xxl-4"><div class="card h-100 <?= $a['is_active'] ? '' : 'opacity-75' ?>"><div class="card-body">
        <div class="d-flex justify-content-between">
            <div><div class="fw-semibold"><?= $a['is_default_on_invoice'] ? '<i class="bi bi-star-fill text-gold" title="Default on invoices"></i> ' : '' ?><?= esc($a['display_name']) ?></div>
                <div class="small text-muted"><?= esc(ucwords(str_replace('_', ' ', $a['account_kind']))) ?> · ledger <?= esc($a['ledger_code']) ?></div></div>
            <div class="text-end">
                <?php if ($a['ownership'] === 'owner_personal'): ?><span class="badge badge-warning">Owner personal</span><?php elseif ($a['ownership'] === 'company'): ?><span class="badge badge-neutral">Company</span><?php else: ?><span class="badge badge-neutral">Other</span><?php endif ?>
                <?php if (! $a['is_active']): ?><span class="badge badge-neutral">Inactive</span><?php endif ?>
            </div>
        </div>
        <?php if ($a['account_kind'] !== 'cash'): ?>
        <dl class="row small mt-2 mb-0">
            <?php if ($a['bank_name']): ?><dt class="col-5">Bank</dt><dd class="col-7"><?= esc($a['bank_name']) ?></dd><?php endif ?>
            <?php if ($a['beneficiary_name']): ?><dt class="col-5">Beneficiary</dt><dd class="col-7"><?= esc($a['beneficiary_name']) ?></dd><?php endif ?>
            <?php if ($a['account_last4']): ?><dt class="col-5">Account no.</dt><dd class="col-7"><span class="mono masked" data-reveal-target="acct<?= $a['id'] ?>"><?= mask_tail($a['account_last4']) ?></span>
                <?php if ($canFull): ?><button type="button" class="btn btn-link btn-sm p-0 ms-1" data-reveal="<?= site_url('settings/banks/' . $a['id'] . '/reveal') ?>" data-field="account_number" data-target="acct<?= $a['id'] ?>">reveal</button><?php endif ?></dd><?php endif ?>
            <?php if ($a['ifsc']): ?><dt class="col-5">IFSC</dt><dd class="col-7 mono"><?= esc($a['ifsc']) ?></dd><?php endif ?>
            <?php if ($a['upi_id']): ?><dt class="col-5">UPI</dt><dd class="col-7"><?= esc($a['upi_id']) ?></dd><?php endif ?>
            <?php if ($a['cif_enc']): ?><dt class="col-5">CIF <i class="bi bi-lock-fill" title="Confidential"></i></dt><dd class="col-7"><span class="mono masked" data-reveal-target="cif<?= $a['id'] ?>">••••••</span>
                <?php if ($canCif): ?><button type="button" class="btn btn-link btn-sm p-0 ms-1" data-reveal="<?= site_url('settings/banks/' . $a['id'] . '/reveal') ?>" data-field="cif" data-target="cif<?= $a['id'] ?>">reveal</button><?php endif ?></dd><?php endif ?>
        </dl>
        <?php endif ?>
        <div class="small mt-2">
            <?= $a['show_on_invoice'] ? '<span class="badge badge-success">Printed on invoices</span>' : '<span class="badge badge-neutral">Not printed</span>' ?>
            <?php if ($a['ownership'] === 'owner_personal'): ?><?= $a['print_approved_by'] ? '<span class="badge badge-info">Print approved by owner</span>' : '<span class="badge badge-neutral">Print not approved</span>' ?><?php endif ?>
            <?php if ($a['requires_review']): ?><span class="badge badge-warning">Receipts flagged for review</span><?php endif ?>
        </div>
        <?php if ($a['classification_note']): ?><div class="small text-muted mt-1">Classification: <?= esc($a['classification_note']) ?></div><?php endif ?>
        <?php if ($canEdit): ?><a href="<?= site_url('settings/banks/' . $a['id']) ?>" class="btn btn-sm btn-outline-secondary mt-2">Edit</a><?php endif ?>
    </div></div></div>
<?php endforeach ?>
</div>
<?php if ($wizard): ?><div class="sticky-actions"><a href="<?= site_url('settings/tax?wizard=1') ?>" class="btn btn-primary">Continue to tax & finance →</a></div><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= asset('js/banks.js') ?>"></script><?= $this->endSection() ?>

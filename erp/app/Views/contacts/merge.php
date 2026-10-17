<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row"><div class="col-lg-7">
<div class="card"><div class="card-body">
    <p>Merge <strong><?= esc($c['contact_code'] . ' — ' . $c['name']) ?></strong> into another contact. All its invoices, receipts, ledger lines, expenses, leads, visits, addresses, people and files will move to the target. This contact is then archived (kept for audit). Finalized documents keep the customer details printed on them.</p>
    <form method="post" action="<?= site_url('contacts/' . $c['id'] . '/merge') ?>" data-confirm="Merge permanently? This cannot be undone automatically." data-confirm-danger>
        <?= csrf_field() ?>
        <?php foreach ($suggest as $s): ?>
            <div class="form-check border rounded p-2 ps-5 mb-2"><input class="form-check-input" type="radio" name="target_id" value="<?= $s['id'] ?>" id="t<?= $s['id'] ?>"><label class="form-check-label" for="t<?= $s['id'] ?>"><strong><?= esc($s['contact_code'] . ' — ' . $s['name']) ?></strong><br><span class="small text-muted">GSTIN <?= esc($s['gstin'] ?? '—') ?> · <?= esc($s['phone'] ?? '') ?> · <?= esc($s['city'] ?? '') ?></span></label></div>
        <?php endforeach ?>
        <?php if ($suggest === []): ?><div class="alert alert-light small">No duplicates detected by GSTIN or phone. Enter the target contact ID manually:</div><?php endif ?>
        <div class="input-group mb-3"><span class="input-group-text">Target contact ID</span><input type="number" class="form-control" name="target_id_manual" <?= $suggest === [] ? 'required' : '' ?> aria-label="Target contact ID"></div>
        <button class="btn btn-danger">Merge into selected contact</button>
    </form>
</div></div>
</div></div>
<?= $this->endSection() ?>

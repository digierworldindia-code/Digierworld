<?= $this->extend('layouts/app') ?>
<?php
$old = old('lines') ?? [[], [], []];
$opts = static function (int $sel) use ($ledgers): string {
    $h = '<option value="">— ledger —</option>';
    foreach ($ledgers as $l) {
        $h .= '<option value="' . $l['id'] . '"' . ($sel === (int) $l['id'] ? ' selected' : '') . '>' . esc($l['code'] . ' ' . $l['name']) . '</option>';
    }

    return $h;
};
?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url('finance/ledger/journal') ?>" data-journal-form>
<?= csrf_field() ?>
<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> Manual journals are for accountant adjustments only (e.g. depreciation, write-offs, capital introduced). Sales, receipts, transfers and expenses must be entered through their own screens so registers and GST reports stay correct.</div>
<div class="card mb-3"><div class="card-body">
    <div class="row g-3 mb-3">
        <div class="col-md-3"><label class="form-label required" for="entry_date">Date</label><input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= esc(old('entry_date') ?? date('Y-m-d')) ?>" required></div>
        <div class="col-md-3"><label class="form-label" for="reference">Reference</label><input class="form-control" id="reference" name="reference" value="<?= esc((string) old('reference')) ?>" maxlength="80"></div>
        <div class="col-md-6"><label class="form-label required" for="narration">Narration</label><input class="form-control" id="narration" name="narration" value="<?= esc((string) old('narration')) ?>" required minlength="10" maxlength="255"></div>
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th style="min-width:280px">Ledger</th><th>Memo</th><th class="num" style="width:150px">Debit</th><th class="num" style="width:150px">Credit</th></tr></thead>
        <tbody data-jv-body>
        <?php foreach ($old as $i => $l): ?>
        <tr><td><select class="form-select form-select-sm" name="lines[<?= $i ?>][account]" aria-label="Ledger"><?= $opts((int) ($l['account'] ?? 0)) ?></select></td>
            <td><input class="form-control form-control-sm" name="lines[<?= $i ?>][memo]" value="<?= esc((string) ($l['memo'] ?? '')) ?>" aria-label="Memo"></td>
            <td><input class="form-control form-control-sm text-end" name="lines[<?= $i ?>][debit]" inputmode="decimal" value="<?= esc((string) ($l['debit'] ?? '')) ?>" data-dr aria-label="Debit"></td>
            <td><input class="form-control form-control-sm text-end" name="lines[<?= $i ?>][credit]" inputmode="decimal" value="<?= esc((string) ($l['credit'] ?? '')) ?>" data-cr aria-label="Credit"></td></tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr><td colspan="2"><button type="button" class="btn btn-outline-dark btn-sm" data-jv-add><i class="bi bi-plus"></i> Line</button> <span class="small ms-2" data-jv-status></span></td><td class="num fw-semibold" data-jv-dr>0.00</td><td class="num fw-semibold" data-jv-cr>0.00</td></tr></tfoot>
    </table></div>
</div></div>
<div class="sticky-actions"><a href="<?= site_url('finance/ledger') ?>" class="btn btn-outline-secondary">Cancel</a><button type="submit" class="btn btn-dark" data-jv-submit>Post journal</button></div>
</form>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/journal-form.js') ?>"></script><?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<button class="btn btn-outline-dark btn-sm" type="button" data-print><i class="bi bi-printer"></i> Print</button>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form method="get" class="filter-bar row g-2 no-print">
    <div class="col-6 col-md-3"><input type="date" class="form-control form-control-sm" name="from" value="<?= esc($from) ?>" aria-label="From"></div>
    <div class="col-6 col-md-3"><input type="date" class="form-control form-control-sm" name="to" value="<?= esc($to) ?>" aria-label="To"></div>
    <div class="col-md-2"><button class="btn btn-dark btn-sm w-100">Show</button></div>
</form>
<div class="row g-3 mb-3">
    <?php foreach ([['Invoiced', $k['invoiced']], ['Cash memos', $k['cash_memos']], ['Collected (net)', $k['collected']], ['Expenses approved', $k['expenses']],
        ['Cash deposited to bank', $k['cash_deposited']], ['Cash in hand', $k['cash_balance']], ['Bank balance', $k['bank_balance']], ['Outstanding', $k['outstanding']]] as [$l, $v]): ?>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label"><?= esc($l) ?></div><div class="kpi-value"><?= inr($v) ?></div></div></div>
    <?php endforeach ?>
</div>
<div class="row g-3">
<div class="col-lg-6">
    <div class="table-card mb-3"><div class="card-header bg-white"><strong>Documents issued</strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($issued as $d): ?><tr><td class="mono"><?= esc($d['document_number']) ?></td><td><?= esc(doc_type_label($d['doc_type'])) ?></td><td><?= esc($d['bill_to_name']) ?></td><td class="num"><?= $d['doc_type'] === 'credit_note' ? '−' : '' ?><?= inr($d['grand_total']) ?></td></tr><?php endforeach ?>
        <?php if ($issued === []): ?><tr><td class="text-muted">None.</td></tr><?php endif ?>
    </tbody></table></div>
    <div class="table-card mb-3"><div class="card-header bg-white"><strong>Collections by mode</strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($byMode as $m): ?><tr><td><?= esc(ucwords(str_replace('_', ' ', $m['mode']))) ?></td><td class="num"><?= (int) $m['n'] ?></td><td class="num"><?= inr($m['amt']) ?></td></tr><?php endforeach ?>
        <?php if ($byMode === []): ?><tr><td class="text-muted">None.</td></tr><?php endif ?>
    </tbody></table></div>
</div>
<div class="col-lg-6">
    <div class="table-card mb-3"><div class="card-header bg-white"><strong>Top overdue</strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($overdue as $o): ?><tr><td><?= esc($o['name']) ?><div class="mono text-muted"><?= esc($o['document_number']) ?> · due <?= dmy($o['due_date']) ?></div></td><td class="num text-danger"><?= inr($o['balance_due']) ?></td></tr><?php endforeach ?>
        <?php if ($overdue === []): ?><tr><td class="text-muted">Nothing overdue.</td></tr><?php endif ?>
    </tbody></table></div>
    <div class="table-card"><div class="card-header bg-white"><strong>Needs attention</strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($pending as $l => $n): ?><tr><td><?= esc($l) ?></td><td class="num fw-semibold <?= $n ? 'text-danger' : '' ?>"><?= (int) $n ?></td></tr><?php endforeach ?>
    </tbody></table></div>
</div>
</div>
<p class="small text-muted mt-3">Generated <?= date('d-m-Y h:i A') ?> by <?= esc(current_user_name()) ?>. Internal — do not share outside authorised staff.</p>
<?= $this->endSection() ?>

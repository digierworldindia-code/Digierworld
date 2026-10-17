<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('finance.ledger.journal')): ?><a href="<?= site_url('finance/ledger/journal/new') ?>" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i> Manual journal</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?= view('finance/ledger/_tabs', ['active' => 'finance/ledger']) ?>
<?php
$extra = '<div class="col-md-3"><input type="search" name="q" value="' . esc($q) . '" class="form-control form-control-sm" placeholder="Voucher, narration, reference" aria-label="Search"></div>'
    . '<div class="col-md-3"><select name="source" class="form-select form-select-sm" aria-label="Source"><option value="">Any source</option>';
foreach ($sources as $s) {
    $extra .= '<option value="' . esc($s) . '"' . ($source === $s ? ' selected' : '') . '>' . esc(journal_source($s, null)[0]) . '</option>';
}
$extra .= '</select></div>';
?>
<?= view('finance/ledger/_range', ['from' => $from, 'to' => $to, 'extra' => $extra]) ?>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Voucher</th><th>Date</th><th>Narration</th><th>Source</th><th>Reference</th><th class="num">Amount</th><th>Posted by</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$label, $url] = journal_source($r['source_type'], $r['source_id'] === null ? null : (int) $r['source_id'], (int) $r['id']); ?>
    <tr class="<?= $r['reversed_by_id'] ? 'text-muted' : '' ?>">
        <td><a class="mono fw-semibold" href="<?= site_url('finance/ledger/entries/' . $r['id']) ?>"><?= esc($r['entry_no']) ?></a></td>
        <td><?= dmy($r['entry_date']) ?></td>
        <td><?= esc($r['narration']) ?><?= $r['reversed_by_id'] ? ' <span class="badge text-bg-secondary">reversed</span>' : '' ?><?= $r['reversal_of_id'] ? ' <span class="badge text-bg-light border">reversal</span>' : '' ?></td>
        <td><?= $url ? '<a href="' . $url . '">' . esc($label) . '</a>' : esc($label) ?></td>
        <td class="mono"><?= esc((string) $r['reference']) ?></td>
        <td class="num"><?= inr($r['total_debit']) ?></td>
        <td class="text-muted"><?= esc((string) $r['poster']) ?></td>
    </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-journal-text"></i>No journal entries in this period.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) ?>
<?= $this->endSection() ?>

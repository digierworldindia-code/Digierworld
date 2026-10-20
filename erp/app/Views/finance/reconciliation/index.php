<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('finance.bank.import')): ?><a href="<?= site_url('finance/reconciliation/import' . ($accId ? '?account=' . $accId : '')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-upload"></i> Import statement</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($accounts === []): ?>
<div class="empty-state"><i class="bi bi-bank"></i>Add a bank account in Settings → Bank &amp; Payment Profiles first.</div>
<?php else: ?>
<form method="get" class="filter-bar row g-2">
    <div class="col-md-4"><select class="form-select form-select-sm" name="account" data-autosubmit aria-label="Account"><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= (int) $a['id'] === $accId ? 'selected' : '' ?>><?= esc($a['display_name']) ?></option><?php endforeach ?></select></div>
    <input type="hidden" name="view" value="<?= esc($view) ?>">
</form>
<?php $s = $summary; ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Statement balance</div><div class="kpi-value"><?= $s['statement_balance'] !== null ? inr($s['statement_balance']) : '—' ?></div><div class="kpi-sub"><?= $s['statement_date'] ? 'as on ' . dmy($s['statement_date']) : 'no statement imported' ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Book balance (same date)</div><div class="kpi-value"><?= $s['book_balance'] !== null ? inr($s['book_balance']) : '—' ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card <?= $s['difference'] !== null && (float) $s['difference'] != 0 ? 'border-warning' : '' ?>"><div class="kpi-label">Difference</div><div class="kpi-value"><?= $s['difference'] !== null ? inr($s['difference']) : '—' ?></div><div class="kpi-sub">Explained by unmatched items below</div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">To review</div><div class="kpi-value"><?= (int) $s['unmatched_lines'] ?> <span class="fs-6 text-muted">lines</span> · <?= (int) $s['unmatched_book'] ?> <span class="fs-6 text-muted">book</span></div></div></div>
</div>
<ul class="nav nav-tabs mb-3 small">
<?php foreach (['unmatched' => 'Unmatched statement lines', 'book' => 'Unmatched book entries', 'matched' => 'Matched', 'ignored' => 'Ignored', 'imports' => 'Imports'] as $k => $l): ?>
    <li class="nav-item"><a class="nav-link <?= $view === $k ? 'active' : '' ?>" href="<?= site_url('finance/reconciliation?' . http_build_query(['account' => $accId, 'view' => $k])) ?>"><?= $l ?></a></li>
<?php endforeach ?>
</ul>

<?php if ($view === 'unmatched'): ?>
    <?php foreach ($lines as $l): $in = (float) $l['credit'] > 0; ?>
    <div class="card mb-2"><div class="card-body py-2">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div class="small"><strong><?= dmy($l['txn_date']) ?></strong> · <?= esc($l['description']) ?> <span class="mono text-muted"><?= esc((string) $l['reference']) ?></span></div>
            <div class="fw-semibold <?= $in ? 'text-success' : 'text-danger' ?>"><?= $in ? '+' . inr($l['credit']) : '−' . inr($l['debit']) ?></div>
        </div>
        <?php if ($canReconcile): ?>
        <form method="post" action="<?= site_url('finance/reconciliation/lines/' . $l['id'] . '/confirm') ?>" class="mt-2">
            <?= csrf_field() ?>
            <?php if ($l['suggestions'] === []): ?>
                <div class="small text-muted">No matching book entry found.
                    <?php if ($in && can('finance.receipt.create')): ?><a href="<?= site_url('finance/receipts/new') ?>">Record the receipt</a> first, then match it.<?php endif ?>
                    <?php if (! $in && can('finance.expense.create')): ?><a href="<?= site_url('finance/expenses/new') ?>">Record the expense</a> first, or book it below.<?php endif ?>
                </div>
            <?php else: ?>
                <div class="small text-muted mb-1">Suggested matches (tick one or more whose total equals the line):</div>
                <?php foreach ($l['suggestions'] as $i => $sg): ?>
                <div class="form-check small">
                    <input class="form-check-input" type="checkbox" name="targets[]" value="<?= esc($sg['type'] . ':' . $sg['id']) ?>" id="sg<?= $l['id'] . '_' . $i ?>" <?= $i === 0 && $sg['score'] >= 85 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="sg<?= $l['id'] . '_' . $i ?>"><?= esc($sg['label']) ?> <span class="mono"><?= esc($sg['number']) ?></span> · <?= dmy($sg['date']) ?> · <?= inr($sg['amount']) ?> <?= $sg['party'] !== '' ? '· ' . esc($sg['party']) : '' ?> <span class="badge <?= $sg['score'] >= 85 ? 'badge-success' : ($sg['score'] >= 60 ? 'badge-warning' : 'badge-neutral') ?>"><?= (int) $sg['score'] ?>%</span></label>
                </div>
                <?php endforeach ?>
                <button class="btn btn-primary btn-sm mt-1" type="submit">Confirm match</button>
            <?php endif ?>
        </form>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php if (can('finance.ledger.journal')): ?>
            <form method="post" action="<?= site_url('finance/reconciliation/lines/' . $l['id'] . '/book') ?>" class="d-flex gap-1 align-items-center">
                <?= csrf_field() ?>
                <select class="form-select form-select-sm" name="ledger_account_id" aria-label="Book to ledger" required>
                    <option value=""><?= $in ? 'Book as income (e.g. interest)…' : 'Book as expense (e.g. bank charges)…' ?></option>
                    <?php foreach ($ledgers as $lg): if ($lg['account_group'] !== ($in ? 'income' : 'expense')) { continue; } ?><option value="<?= $lg['id'] ?>"><?= esc($lg['code'] . ' ' . $lg['name']) ?></option><?php endforeach ?>
                </select>
                <button class="btn btn-outline-secondary btn-sm text-nowrap" type="submit">Book &amp; match</button>
            </form>
            <?php endif ?>
            <form method="post" action="<?= site_url('finance/reconciliation/lines/' . $l['id'] . '/ignore') ?>" class="d-flex gap-1 align-items-center">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm" name="reason" placeholder="Reason to ignore" aria-label="Reason to ignore" minlength="5" required>
                <button class="btn btn-outline-secondary btn-sm" type="submit">Ignore</button>
            </form>
        </div>
        <?php endif ?>
    </div></div>
    <?php endforeach ?>
    <?php if ($lines === []): ?><div class="empty-state"><i class="bi bi-check2-all"></i>No unmatched statement lines.</div><?php endif ?>
    <?= isset($total) ? view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) : '' ?>

<?php elseif ($view === 'book'): ?>
    <p class="small text-muted">Entries in the books for this account that are not yet on an imported statement (e.g. cheques not yet cleared, or entries posted to the wrong account).</p>
    <div class="table-card"><div class="table-responsive"><table class="table small align-middle">
        <thead><tr><th>Type</th><th>Number</th><th>Date</th><th>Party</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($book as $b): ?><tr><td><?= esc($b['label']) ?></td><td class="mono"><?= esc($b['number']) ?></td><td><?= dmy($b['date']) ?></td><td><?= esc($b['party']) ?></td><td class="mono"><?= esc($b['reference']) ?></td><td class="num"><?= inr($b['amount']) ?></td></tr><?php endforeach ?>
        <?php if ($book === []): ?><tr><td colspan="6" class="text-muted">Everything in the books is matched.</td></tr><?php endif ?>
        </tbody></table></div></div>

<?php elseif (in_array($view, ['matched', 'ignored'], true)): ?>
    <div class="table-card"><div class="table-responsive"><table class="table small align-middle">
        <thead><tr><th>Date</th><th>Description</th><th class="num">In</th><th class="num">Out</th><th><?= $view === 'matched' ? 'Matched to' : '' ?></th><th></th></tr></thead><tbody>
        <?php foreach ($lines as $l): ?>
        <tr><td><?= dmy($l['txn_date']) ?></td><td><?= esc($l['description']) ?></td><td class="num"><?= (float) $l['credit'] ? inr($l['credit']) : '' ?></td><td class="num"><?= (float) $l['debit'] ? inr($l['debit']) : '' ?></td>
            <td><?php foreach ($l['matches'] ?? [] as $m): ?><span class="badge badge-neutral"><?= esc(str_replace('_', ' ', $m['match_type'])) ?> #<?= (int) $m['match_id'] ?> · <?= inr($m['amount']) ?></span> <?php endforeach ?></td>
            <td class="text-end"><?php if ($canReconcile && $view === 'matched' && ! array_filter($l['matches'] ?? [], static fn ($m) => $m['match_type'] === 'journal')): ?>
                <form method="post" action="<?= site_url('finance/reconciliation/lines/' . $l['id'] . '/unmatch') ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm">Unmatch</button></form><?php endif ?></td></tr>
        <?php endforeach ?>
        <?php if ($lines === []): ?><tr><td colspan="6" class="text-muted">Nothing here.</td></tr><?php endif ?>
        </tbody></table></div></div>
    <?= isset($total) ? view('partials/pager', ['total' => $total, 'page' => $page, 'perPage' => $perPage]) : '' ?>

<?php else: ?>
    <div class="table-card"><div class="table-responsive"><table class="table small align-middle">
        <thead><tr><th>Imported</th><th>File</th><th>Period</th><th class="num">New</th><th class="num">Duplicates</th><th class="num">Errors</th><th>By</th></tr></thead><tbody>
        <?php foreach ($imports as $i): ?><tr><td><?= dmy($i['imported_at'], true) ?></td><td><?= esc($i['original_name']) ?></td><td><?= dmy($i['statement_from']) ?> – <?= dmy($i['statement_to']) ?></td><td class="num"><?= (int) $i['rows_imported'] ?></td><td class="num"><?= (int) $i['rows_duplicate'] ?></td><td class="num"><?= (int) $i['rows_error'] ?></td><td><?= esc((string) $i['full_name']) ?></td></tr><?php endforeach ?>
        <?php if ($imports === []): ?><tr><td colspan="7" class="text-muted">No statements imported yet.</td></tr><?php endif ?>
        </tbody></table></div></div>
<?php endif ?>
<?php endif ?>
<?= $this->endSection() ?>

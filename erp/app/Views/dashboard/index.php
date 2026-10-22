<?= $this->extend('layouts/app') ?>
<?php
$kpi = static function (string $label, string $value, string $tip, string $sub = '', bool $accent = false): string {
    return '<div class="kpi-card' . ($accent ? ' accent' : '') . '"><div class="kpi-label">' . esc($label)
        . ' <i class="bi bi-info-circle help-tip" tabindex="0" data-bs-toggle="tooltip" data-bs-title="' . esc($tip, 'attr') . '" aria-label="' . esc($tip, 'attr') . '"></i></div>'
        . '<div class="kpi-value">' . $value . '</div>' . ($sub !== '' ? '<div class="kpi-sub">' . $sub . '</div>' : '') . '</div>';
};
$f = $widgets['finance'] ?? null;
?>
<?= $this->section('pageActions') ?>
<form method="get" class="d-flex flex-wrap gap-2 align-items-center" action="<?= site_url($path ?? '/') ?>">
    <select name="period" class="u-w-auto form-select form-select-sm" data-autosubmit aria-label="Period">
        <?php foreach (['this_month' => 'This month', 'last_month' => 'Last month', 'this_fy' => 'This FY', 'last_fy' => 'Last FY', 'custom' => 'Custom range'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $period === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach ?>
    </select>
    <?php if ($period === 'custom'): ?>
        <input type="date" name="from" value="<?= esc($from) ?>" class="u-w-auto form-control form-control-sm" aria-label="From">
        <input type="date" name="to" value="<?= esc($to) ?>" class="u-w-auto form-control form-control-sm" aria-label="To">
        <button class="btn btn-sm btn-primary">Apply</button>
    <?php endif ?>
    <?php if (can('reports.finance.view')): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('reports/digest?from=' . $from . '&to=' . $to) ?>"><i class="bi bi-printer"></i> Daily digest</a>
    <?php endif ?>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="text-muted small mb-3">Period: <strong><?= esc($label) ?></strong> (<?= dmy($from) ?> – <?= dmy($to) ?>)</p>

<?php $pending = array_filter($checklist, static fn ($c) => ! $c['done']); if ($pending !== []): ?>
<div class="card mb-3 border-warning">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong><i class="bi bi-magic text-gold"></i> Finish your one-time setup</strong>
            <a href="<?= site_url('settings/setup') ?>" class="btn btn-sm btn-primary">Open Setup Wizard</a>
        </div>
        <div class="row g-2 small">
            <?php foreach ($checklist as $c): ?>
                <div class="col-md-6"><a href="<?= site_url($c['url']) ?>" class="text-decoration-none <?= $c['done'] ? 'text-success' : 'text-body' ?>"><i class="bi <?= $c['done'] ? 'bi-check-circle-fill' : 'bi-circle' ?> me-1"></i><?= esc($c['label']) ?></a></div>
            <?php endforeach ?>
        </div>
    </div>
</div>
<?php endif ?>

<?php if (($shortcuts ?? []) !== []): ?>
<div class="card-section-title">Go to</div>
<div class="row g-2 mb-3">
    <?php foreach ($shortcuts as $item): ?>
    <div class="col-6 col-md-4 col-xl-3"><a class="card h-100 text-decoration-none text-reset" href="<?= esc($item['href']) ?>"><div class="card-body py-2 d-flex align-items-center gap-2"><i class="bi bi-<?= esc($item['icon']) ?> text-gold"></i><span><?= esc($item['label']) ?></span></div></a></div>
    <?php endforeach ?>
</div>
<?php endif ?>

<?php if (($area ?? null) === 'billing'): ?>
<div class="card-section-title">Recent documents</div>
<div class="table-card mb-3"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Number</th><th>Type</th><th>Date</th><th>Customer</th><th class="text-end">Total</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $d): ?>
        <tr>
            <td><a class="mono" href="<?= site_url('billing/documents/' . $d['id']) ?>"><?= esc($d['document_number'] ?? $d['draft_ref'] ?? ('#' . $d['id'])) ?></a></td>
            <td><?= esc(doc_type_label($d['doc_type'])) ?></td>
            <td class="text-nowrap"><?= dmy($d['issue_date']) ?></td>
            <td><?= esc((string) $d['bill_to_name']) ?></td>
            <td class="num"><?= inr($d['grand_total']) ?></td>
            <td class="num"><?= $d['status'] === 'finalized' ? inr($d['balance_due']) : '—' ?></td>
            <td><?= status_badge($d['status']) ?><?= $d['status'] === 'finalized' && $d['payment_status'] ? ' ' . status_badge($d['payment_status']) : '' ?></td>
        </tr>
    <?php endforeach ?>
    <?php if ($recent === []): ?><tr><td colspan="7" class="text-muted">No documents yet.</td></tr><?php endif ?>
    </tbody>
</table></div></div>
<?php endif ?>

<?php if (($area ?? null) !== null && $widgets === [] && ($shortcuts ?? []) === []): ?>
<div class="card"><div class="card-body empty-state"><i class="bi bi-inbox"></i>Nothing to show here for your role yet.</div></div>
<?php endif ?>

<?php if ($f !== null): ?>
<div class="card-section-title">Billing & collections</div>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><?= $kpi('Invoiced', inr($f['invoiced']), 'Grand total of FINALIZED tax invoices and bills of supply issued in the period. Drafts, estimates and receipts are excluded.', 'Credit notes: ' . inr($f['credit_notes']), true) ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Cash memos', inr($f['cash_memos']), 'Grand total of finalized cash memos. Separate number series; taxed under the same GST rules and included in GST figures.') ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Collected', inr($f['collected']), 'Customer receipts minus refunds in the period. A receipt settles an invoice — it is never counted as a second sale. Cash deposits/transfers are excluded.', 'Receipts ' . inr($f['receipts']) . ' · Refunds ' . inr($f['refunds']), true) ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Outstanding dues', inr($f['outstanding']), 'Balance due on all finalized sale documents as of today (accrual basis).', 'Overdue: <span class="text-danger">' . inr($f['overdue']) . '</span> · Advances held: ' . inr($f['advances'])) ?></div>
</div>
<div class="card-section-title">GST (estimates — verify with your accountant before filing)</div>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><?= $kpi('Taxable sales value', inr($f['taxable_sales']), 'Taxable value of finalized tax invoices, cash memos and debit notes with a taxable treatment, minus credit notes. Exempt/nil/non-GST excluded.') ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Output GST', inr($f['output_tax']), 'Output tax on finalized outward supplies net of credit notes. Reverse-charge supplies are excluded (tax payable by recipient).', 'CGST ' . inr($f['cgst']) . ' · SGST ' . inr($f['sgst']) . ($f['utgst'] !== '0.00' ? ' · UTGST ' . inr($f['utgst']) : '') . ' · IGST ' . inr($f['igst'])) ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Eligible ITC', inr($f['itc']), 'Input tax credit only from APPROVED expenses whose ITC rule marks the credit eligible (accountant-configurable rules).') ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('GST liability (est.)', inr($f['gst_liability']), 'Output GST − eligible ITC for the period. An estimate for planning, not a return.', '', true) ?></div>
</div>
<div class="card-section-title">Cash, bank & spend</div>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><?= $kpi('Expenses', inr($f['expenses']), 'Approved expenses dated in the period: taxable value + GST that is NOT claimable as ITC (accrual basis).') ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Net cash movement', inr($f['net_cash_movement']), 'Change in all cash + bank ledgers during the period. Internal transfers (e.g. cash deposited to bank) net to zero.') ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Cash in hand', inr($f['cash_balance']), 'Cash ledger balance as at period end.', 'Deposited to bank: ' . inr($f['cash_deposited']) . ' <span class="badge badge-neutral">transfer, not income</span>') ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Bank & UPI balance', inr($f['bank_balance']), 'Bank, UPI clearing and other non-cash account balances as at period end (includes owner-clearing accounts shown separately in Bank / Cash Accounts).') ?></div>
</div>
<?php endif ?>

<?php if (isset($widgets['payroll'])): $p = $widgets['payroll']; ?>
<div class="card-section-title">Payroll</div>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><?= $kpi('Payroll (gross)', inr($p['gross']), 'Gross pay of finalized payroll runs for months starting in the period (salary expense accrual).') ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Net payable', inr($p['net']), 'Net pay of finalized runs in the period.') ?></div>
    <div class="col-6 col-xl-3"><?= $kpi('Unpaid salaries', inr($p['unpaid_all']), 'Balance still to be paid on all locked payroll runs.') ?></div>
</div>
<?php endif ?>

<?php if (isset($widgets['crm'])): $c = $widgets['crm']; ?>
<div class="card-section-title">CRM — <?= esc($c['scope_label']) ?></div>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-xl-2"><a class="text-decoration-none text-reset" href="<?= site_url('crm/today') ?>"><?= $kpi('Calls today', (string) $c['calls_today'], 'Calls logged today.', 'Connected: ' . $c['connected_today']) ?></a></div>
    <div class="col-6 col-md-4 col-xl-2"><a class="text-decoration-none text-reset" href="<?= site_url('crm/followups?status=due_today') ?>"><?= $kpi('Follow-ups due', (string) $c['due_today'], 'Pending follow-ups due today.') ?></a></div>
    <div class="col-6 col-md-4 col-xl-2"><a class="text-decoration-none text-reset" href="<?= site_url('crm/followups?status=overdue') ?>"><?= $kpi('Overdue', '<span class="text-danger">' . $c['overdue'] . '</span>', 'Pending follow-ups past their due time.') ?></a></div>
    <div class="col-6 col-md-4 col-xl-2"><a class="text-decoration-none text-reset" href="<?= site_url('crm/visits') ?>"><?= $kpi('Visits today', (string) $c['visits_today'], 'Scheduled visits today.', 'My open visits: ' . $c['owner_visits_open']) ?></a></div>
    <div class="col-6 col-md-4 col-xl-2"><?= $kpi('New leads', (string) $c['new_leads'], 'Leads created in the period.') ?></div>
    <div class="col-6 col-md-4 col-xl-2"><?= $kpi('Conversions', (string) $c['conversions'], 'Won leads converted to clients in the period. Conversion alone never creates a payroll payout.') ?></div>
</div>
<?php endif ?>

<?php if (isset($widgets['my_billing'])): ?>
<div class="card-section-title">My billing work</div>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><a class="text-decoration-none text-reset" href="<?= site_url('billing/documents?status=draft') ?>"><?= $kpi('My drafts', (string) $widgets['my_billing']['drafts'], 'Draft documents you created.') ?></a></div>
    <div class="col-6 col-xl-3"><?= $kpi('Finalized (period)', (string) $widgets['my_billing']['finalized'], 'Documents you created that were finalized in the period (count only).') ?></div>
</div>
<?php endif ?>

<?php if ($f !== null): ?>
<div class="row g-3 mb-3" data-dashboard-charts data-endpoint="<?= esc(site_url('dashboard/data?' . http_build_query(['period' => $period, 'from' => $from, 'to' => $to])), 'attr') ?>">
    <div class="col-xl-8"><div class="card h-100"><div class="card-header">Billed vs collected (12 months)</div><div class="card-body"><canvas id="chBilled" height="110" aria-label="Billed versus collected chart" role="img"></canvas></div></div></div>
    <div class="col-xl-4"><div class="card h-100"><div class="card-header">Output GST split</div><div class="card-body"><canvas id="chGst" height="180" aria-label="GST split chart" role="img"></canvas></div></div></div>
    <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-header">Payment methods</div><div class="card-body"><canvas id="chModes" height="180" role="img" aria-label="Payment methods"></canvas></div></div></div>
    <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-header">Expenses by category</div><div class="card-body"><canvas id="chExp" height="180" role="img" aria-label="Expenses by category"></canvas></div></div></div>
    <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-header">Receivables aging</div><div class="card-body"><canvas id="chAging" height="180" role="img" aria-label="Receivables aging"></canvas></div></div></div>
    <div class="col-md-6 col-xl-6"><div class="card h-100"><div class="card-header">Top services (taxable value)</div><div class="card-body"><canvas id="chSvc" height="150" role="img" aria-label="Top services"></canvas></div></div></div>
    <div class="col-xl-6"><div class="card h-100"><div class="card-header">Year-on-year (taxable value by month)</div><div class="card-body"><canvas id="chYoy" height="150" role="img" aria-label="Year on year"></canvas></div></div></div>
</div>
<?php endif ?>

<div class="row g-3">
    <?php if (isset($widgets['hr'])): $h = $widgets['hr']; ?>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">People & recruitment</div><div class="card-body small">
        <div class="row g-2 mb-2">
            <div class="col-4"><div class="fw-bold fs-5"><?= $h['active_staff'] ?></div>Active staff</div>
            <div class="col-4"><div class="fw-bold fs-5"><?= $h['open_vacancies'] ?></div>Open vacancies</div>
            <div class="col-4"><div class="fw-bold fs-5"><?= $h['pending_leaves'] ?></div>Leave requests</div>
        </div>
        <div class="mb-1 text-muted">Today's attendance</div>
        <?php foreach (['present' => 'success', 'absent' => 'danger', 'half_day' => 'warning', 'leave' => 'info', 'holiday' => 'neutral', 'week_off' => 'neutral'] as $st => $cls): ?>
            <span class="badge badge-<?= $cls ?> me-1"><?= ucwords(str_replace('_', ' ', $st)) ?>: <?= (int) ($h['attendance'][$st] ?? 0) ?></span>
        <?php endforeach ?>
        <div class="mt-2 mb-1 text-muted">Recruitment pipeline</div>
        <?php foreach ($h['pipeline'] as $st => $n): ?><span class="badge badge-neutral me-1"><?= esc(ucwords(str_replace('_', ' ', $st))) ?>: <?= (int) $n ?></span><?php endforeach ?>
        <?php if ($h['pipeline'] === []): ?><span class="text-muted">No active applicants.</span><?php endif ?>
    </div></div></div>
    <?php endif ?>

    <?php if (isset($widgets['sync'])): $s = $widgets['sync']; ?>
    <div class="col-lg-3"><div class="card h-100"><div class="card-header">Google Sheets sync</div><div class="card-body small">
        <?php if (! $s['configured']): ?>
            <span class="badge badge-neutral">Not configured</span><p class="mt-2 mb-0">Live sync is off. CRM data is safe in the database; export to Excel any time.</p>
        <?php else: ?>
            <span class="badge <?= $s['enabled'] ? 'badge-success' : 'badge-neutral' ?>"><?= $s['enabled'] ? 'Connected' : 'Disabled' ?></span>
            <div class="mt-2">Last success: <?= dmy($s['last_success'], true) ?></div>
            <div>Pending: <?= $s['pending'] ?> · Failed: <span class="<?= $s['failed'] ? 'text-danger fw-bold' : '' ?>"><?= $s['failed'] ?></span></div>
        <?php endif ?>
        <a href="<?= site_url('crm/sync') ?>" class="d-inline-block mt-2">Open sync status →</a>
    </div></div></div>
    <?php endif ?>

    <?php if (isset($widgets['tasks'])): ?>
    <div class="col-lg-<?= isset($widgets['sync']) ? '3' : '6' ?>"><div class="card h-100"><div class="card-header d-flex justify-content-between">My open tasks <a href="<?= site_url('crm/tasks') ?>" class="small">All tasks</a></div>
        <ul class="list-group list-group-flush small">
        <?php foreach ($widgets['tasks'] as $t): $overdue = $t['due_at'] && $t['due_at'] < date('Y-m-d H:i:s'); ?>
            <li class="list-group-item d-flex justify-content-between"><a href="<?= site_url('crm/tasks/' . $t['id']) ?>"><?= esc($t['title']) ?></a><span class="<?= $overdue ? 'text-danger' : 'text-muted' ?>"><?= $t['due_at'] ? dmy($t['due_at'], true) : '—' ?></span></li>
        <?php endforeach ?>
        <?php if ($widgets['tasks'] === []): ?><li class="list-group-item text-muted">Nothing pending. 🎉</li><?php endif ?>
        </ul>
    </div></div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($f !== null): ?>
<script src="<?= asset('vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= asset('js/dashboard.js') ?>"></script>
<?php endif ?>
<?= $this->endSection() ?>

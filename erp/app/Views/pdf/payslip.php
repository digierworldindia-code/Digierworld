<?php
/**
 * Payslip (A4). Shows only this employee's pay; bank account masked.
 *
 * @var array<string, mixed> $item @var array<string, mixed> $run @var array<string, mixed> $emp
 * @var array<string, mixed>|null $bank @var list<array<string, mixed>> $earnings $deductions $employer $payments
 * @var array<string, mixed> $supplier @var string|null $logo @var string $words @var int $version
 */
$money = static fn ($v) => number_format((float) $v, 2);
$rows  = max(count($earnings), count($deductions));
?>
<!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 12mm 14mm 16mm 14mm; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 9pt; color: #111; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .muted { color: #555; } .right { text-align: right; } .b { font-weight: bold; }
    .title { font-size: 13pt; font-weight: bold; letter-spacing: 1px; }
    .box { border: 1px solid #999; }
    .grid td, .grid th { padding: 4px 6px; border: 1px solid #bbb; }
    .grid th { background: #111; color: #fff; font-size: 8pt; text-align: left; }
    .net { background: #f3efe6; font-size: 11pt; }
</style></head><body>
<table><tr>
    <td style="width:60%"><?php if ($logo): ?><img src="<?= $logo ?>" style="max-height:52px;max-width:200px"><br><?php endif ?>
        <span class="b"><?= esc((string) ($supplier['trade_name'] ?: $supplier['legal_name'])) ?></span><?= $supplier['trade_name'] && $supplier['legal_name'] && $supplier['trade_name'] !== $supplier['legal_name'] ? '<br><span class="muted">' . esc($supplier['legal_name']) . '</span>' : '' ?><br><span class="muted"><?= esc(implode(', ', array_filter(array_merge($supplier['address'], [$supplier['state_name']])))) ?></span></td>
    <td class="right"><div class="title">PAYSLIP</div><div><?= esc(date('F Y', strtotime($run['period'] . '-01'))) ?></div><?= $version > 1 ? '<div class="muted">Revised payslip (version ' . (int) $version . ')</div>' : '' ?></td>
</tr></table>
<br>
<table class="grid">
    <tr><td style="width:25%" class="muted">Employee</td><td style="width:25%" class="b"><?= esc($emp['full_name']) ?></td><td style="width:25%" class="muted">Employee ID</td><td><?= esc($emp['employee_code']) ?></td></tr>
    <tr><td class="muted">Designation</td><td><?= esc((string) $emp['designation']) ?></td><td class="muted">Department</td><td><?= esc((string) $emp['department']) ?></td></tr>
    <tr><td class="muted">Date of joining</td><td><?= dmy($emp['date_of_joining']) ?></td><td class="muted">Pay period</td><td><?= dmy($run['period_start']) ?> – <?= dmy($run['period_end']) ?></td></tr>
    <tr><td class="muted">Payable days</td><td><?= (float) $item['payable_days'] ?> of <?= (float) $item['proration_days'] ?></td><td class="muted">Leave (paid / unpaid), absent</td><td><?= (float) $item['paid_leave_days'] ?> / <?= (float) $item['unpaid_leave_days'] ?>, <?= (float) $item['absent_days'] ?></td></tr>
    <tr><td class="muted">Bank</td><td colspan="3"><?= $bank ? esc((string) $bank['bank_name']) . ' · A/c ••••' . esc((string) $bank['account_last4']) . ' · ' . esc((string) $bank['ifsc']) : '—' ?></td></tr>
</table>
<br>
<table class="grid">
    <tr><th style="width:35%">Earnings</th><th style="width:15%" class="right">Amount (₹)</th><th style="width:35%">Deductions</th><th class="right">Amount (₹)</th></tr>
    <?php for ($i = 0; $i < $rows; $i++): $e = $earnings[$i] ?? null; $d = $deductions[$i] ?? null; ?>
    <tr><td><?= $e ? esc($e['label']) : '' ?></td><td class="right"><?= $e ? $money($e['amount']) : '' ?></td><td><?= $d ? esc($d['label']) : '' ?></td><td class="right"><?= $d ? $money($d['amount']) : '' ?></td></tr>
    <?php endfor ?>
    <tr class="b"><td>Gross earnings</td><td class="right"><?= $money($item['gross_pay']) ?></td><td>Total deductions</td><td class="right"><?= $money($item['total_deductions']) ?></td></tr>
    <tr class="net b"><td colspan="3">Net pay</td><td class="right" style="white-space:nowrap">₹ <?= $money($item['net_pay']) ?></td></tr>
    <tr><td colspan="4" class="muted"><?= esc($words) ?></td></tr>
</table>
<?php if ($employer): ?><p class="muted">Employer contributions (not deducted from pay): <?= esc(implode(', ', array_map(static fn ($c) => $c['label'] . ' ₹' . number_format((float) $c['amount'], 2), $employer))) ?></p><?php endif ?>
<?php if ($payments): ?><p>Paid: <?= esc(implode('; ', array_map(static fn ($p) => dmy($p['payment_date']) . ' ₹' . number_format((float) $p['amount'], 2) . ' (' . str_replace('_', ' ', $p['mode']) . ')', $payments))) ?><?= (float) $item['balance'] > 0 ? ' · Balance ₹' . number_format((float) $item['balance'], 2) : '' ?></p><?php endif ?>
<?php if ($item['adjustment_note']): ?><p class="muted">Correction note: <?= esc($item['adjustment_note']) ?></p><?php endif ?>
<p class="muted" style="margin-top:18px">This is a computer-generated payslip for the employee's personal records. Please report discrepancies to HR within 7 days.</p>
</body></html>

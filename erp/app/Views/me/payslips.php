<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="table-card" style="max-width:820px"><div class="table-responsive"><table class="table small align-middle mb-0">
    <thead><tr><th>Month</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net pay</th><th>Payment</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $p): ?>
    <tr><td><?= date('F Y', strtotime($p['period'] . '-01')) ?></td><td class="num"><?= inr($p['gross_pay']) ?></td><td class="num"><?= inr($p['total_deductions']) ?></td><td class="num fw-semibold"><?= inr($p['net_pay']) ?></td><td><?= status_badge($p['payment_status']) ?></td>
        <td><?= $p['stored_file_id'] ? '<a href="' . site_url('files/' . $p['stored_file_id']) . '"><i class="bi bi-file-earmark-pdf"></i> Payslip' . ((int) $p['version'] > 1 ? ' (rev. ' . (int) $p['version'] . ')' : '') . '</a>' : '<span class="text-muted">—</span>' ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-file-earmark-text"></i>Payslips appear here once a payroll month is finalized.</div></td></tr><?php endif ?>
</tbody></table></div></div>
<?= $this->endSection() ?>

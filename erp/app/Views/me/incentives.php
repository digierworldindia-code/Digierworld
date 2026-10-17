<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="table-card"><div class="table-responsive"><table class="table small align-middle mb-0">
    <thead><tr><th>Month</th><th>Policy</th><th>Source</th><th class="num">Basis</th><th class="num">Share</th><th class="num">Incentive</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td><?= esc($r['period']) ?></td><td><?= esc($r['policy']) ?></td><td><?= esc(str_replace('_', ' ', $r['source_type'])) ?><?= $r['company_name'] ? '<div class="text-muted">' . esc($r['company_name']) . '</div>' : '' ?></td>
        <td class="num"><?= inr($r['basis_amount']) ?><div class="text-muted"><?= esc(str_replace('_', ' ', $r['basis'])) ?></div></td><td class="num"><?= (float) $r['share_percent'] ?>%</td><td class="num fw-semibold"><?= inr($r['amount']) ?></td>
        <td><?= status_badge($r['status']) ?><?= $r['rejection_reason'] ? '<div class="text-muted">' . esc($r['rejection_reason']) . '</div>' : '' ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-trophy"></i>No incentive entries yet. Proposed incentives appear here; HR approves them before payroll.</div></td></tr><?php endif ?>
</tbody></table></div></div>
<?= $this->endSection() ?>

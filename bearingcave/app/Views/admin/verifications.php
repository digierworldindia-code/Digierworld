<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Verification queue']) ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['open' => 'Open', 'approved' => 'Approved', 'changes_requested' => 'Changes requested', 'rejected' => 'Rejected', 'draft' => 'Draft', 'all' => 'All'] as $k => $l): ?><a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>" href="<?= site_url('admin/verifications?status=' . $k) ?>"><?= $l ?></a><?php endforeach ?>
    <a class="btn btn-sm btn-light ms-auto" href="<?= site_url('admin/verifications?status=open&mine=1') ?>">Assigned to me</a>
</div>
<div class="bc-card">
<?php if (! $rows): ?><?= empty_state('bi-check2-all', 'No applications here') ?><?php else: ?>
<div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Company</th><th>Type</th><th>Country</th><th>Submitted</th><th>Progress</th><th>Current stage</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr>
    <td data-label="Company"><a class="fw-semibold" href="<?= site_url('admin/verifications/' . $a['id']) ?>"><?= esc($a['legal_name']) ?></a> <?= sample_badge($a) ?><div class="small text-muted">Cycle <?= (int) $a['cycle'] ?></div></td>
    <td data-label="Type"><?= esc(ucfirst($a['application_type'])) ?></td><td data-label="Country"><?= esc(country_name($a['country_code'])) ?></td><td data-label="Submitted" class="small"><?= fdt($a['submitted_at']) ?></td>
    <td data-label="Progress" style="min-width:120px"><div class="progress" style="height:6px" role="progressbar" aria-label="Stages complete" aria-valuenow="<?= (int) $a['done_stages'] ?>" aria-valuemin="0" aria-valuemax="<?= (int) $a['total_stages'] ?>"><div class="progress-bar bg-success" style="width:<?= $a['total_stages'] ? round(100 * $a['done_stages'] / $a['total_stages']) : 0 ?>%"></div></div><span class="small text-muted"><?= (int) $a['done_stages'] ?>/<?= (int) $a['total_stages'] ?></span></td>
    <td data-label="Stage" class="small"><?= esc(\App\Services\VerificationService::STAGES[$a['current_stage']] ?? '—') ?></td><td data-label="Status"><?= status_badge($a['status']) ?></td>
</tr><?php endforeach ?></tbody></table></div>
<?php endif ?>
</div>
<?= $this->endSection() ?>

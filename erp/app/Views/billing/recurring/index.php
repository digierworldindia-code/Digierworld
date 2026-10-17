<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<a href="<?= site_url('billing/recurring/new') ?>" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i> New retainer</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="alert alert-light border small"><i class="bi bi-info-circle"></i> Retainers create <strong>drafts</strong> on their due date. Nothing is numbered, posted or sent until someone reviews and finalizes the draft.</div>
<div class="table-card"><div class="table-responsive">
<table class="table table-hover align-middle small">
    <thead><tr><th>Retainer</th><th>Client</th><th>Type</th><th>Frequency</th><th class="num">Amount (before GST)</th><th>Next draft</th><th>Progress</th><th>Last generated</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr class="<?= (int) $r['is_active'] === 1 ? '' : 'text-muted' ?>">
        <td><a class="fw-semibold" href="<?= site_url('billing/recurring/' . $r['id']) ?>"><?= esc($r['name']) ?></a><?= (int) $r['is_active'] === 1 ? '' : ' <span class="badge text-bg-secondary">ended</span>' ?></td>
        <td><?= esc($r['client']) ?></td><td><?= esc(doc_type_label($r['doc_type'])) ?></td><td><?= esc(ucwords(str_replace('_', ' ', $r['frequency']))) ?></td>
        <td class="num"><?= inr($r['amount']) ?></td>
        <td class="<?= (int) $r['is_active'] === 1 && $r['next_run_date'] <= date('Y-m-d') ? 'text-danger fw-semibold' : '' ?>"><?= (int) $r['is_active'] === 1 ? dmy($r['next_run_date']) : '—' ?></td>
        <td><?= (int) $r['installments_done'] ?><?= $r['installments_total'] ? ' / ' . (int) $r['installments_total'] : '' ?><?= $r['end_date'] ? '<div class="text-muted">ends ' . dmy($r['end_date']) . '</div>' : '' ?></td>
        <td><?php if ($r['last_generated_document_id']): ?><a class="mono" href="<?= site_url('billing/documents/' . $r['last_generated_document_id']) ?>"><?= esc($r['document_number'] ?? $r['draft_ref']) ?></a> <?= status_badge($r['last_status']) ?><?php endif ?></td>
    </tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-arrow-repeat"></i>No retainers yet.</div></td></tr><?php endif ?>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>

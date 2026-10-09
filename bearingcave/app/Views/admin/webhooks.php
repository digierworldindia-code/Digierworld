<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Payment webhook events', 'subtitle' => 'Each provider event is processed exactly once (idempotent) and kept for reconciliation.', 'breadcrumbs' => ['Payments' => 'admin/payments', 'Webhooks' => null]]) ?>
<div class="bc-card"><?php if (! $rows): ?><?= empty_state('bi-broadcast', 'No webhook events received') ?><?php else: ?><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Received</th><th>Provider</th><th>Event ID</th><th>Type</th><th>Signature</th><th>Status</th><th>Error</th></tr></thead><tbody>
<?php foreach ($rows as $w): ?><tr><td><?= fdt($w['created_at']) ?></td><td><?= esc($w['provider']) ?></td><td class="part-no"><?= esc($w['event_id']) ?></td><td><?= esc($w['event_type'] ?? '') ?></td><td><?= $w['signature_valid'] ? status_badge('valid') : status_badge('failed') ?></td><td><?= status_badge($w['status']) ?></td><td><?= esc($w['error'] ?? '') ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></div>
<?= $this->endSection() ?>

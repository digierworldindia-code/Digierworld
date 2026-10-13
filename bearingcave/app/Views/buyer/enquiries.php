<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'My enquiries', 'subtitle' => 'Questions you sent about specific listings, and the replies. Listings of unverified suppliers are answered through BearingCave.']) ?>
<div class="bc-card">
<?php if (! $rows): ?><?= empty_state('bi-chat-left-text', 'No enquiries', 'Ask a supplier a question from any product page.') ?><?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($rows as $e): ?>
        <div class="list-group-item">
            <div class="d-flex justify-content-between flex-wrap gap-2"><a class="fw-semibold" href="<?= site_url('product/' . $e['slug']) ?>"><span class="part-no"><?= esc($e['part_number']) ?></span> — <?= esc($e['name']) ?></a><?= status_badge($e['status']) ?></div>
            <?php $viaPlatform = $e['routing'] === 'platform' && $e['forwarded_at'] === null; ?>
            <div class="small text-muted"><?= fdt($e['created_at']) ?><?= $e['quantity'] ? ' · Qty ' . number_format((int) $e['quantity']) : '' ?> · <?= $e['routing'] === 'direct' ? 'Sent to the supplier' : ($e['forwarded_at'] ? 'Passed to the supplier by BearingCave' : 'Handled by BearingCave') ?></div>
            <div class="chat-msg mine mt-2 small"><?= esc($e['message']) ?></div>
            <?php if ($e['response']): ?><div class="chat-msg mt-2 small"><strong><?= $viaPlatform ? 'BearingCave reply' : 'Supplier reply' ?> (<?= fdt($e['responded_at']) ?>):</strong><br><?= esc($e['response']) ?></div><?php endif ?>
            <?php if ($e['status'] === 'responded'): ?><a class="btn btn-sm btn-outline-primary mt-2" href="<?= site_url('buyer/rfqs/new?part_number=' . rawurlencode($e['part_number'])) ?>">Request a formal quotation</a><?php endif ?>
        </div>
    <?php endforeach ?>
    </div>
<?php endif ?>
</div>
<?= $this->endSection() ?>

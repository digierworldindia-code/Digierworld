<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Product enquiries', 'subtitle' => 'Buyer questions about your listings. Buyer identities are relayed confidentially by BearingCave.']) ?>
<div class="bc-card">
<?php if (! $rows): ?><?= empty_state('bi-chat-left-text', 'No enquiries yet') ?><?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($rows as $e): ?>
        <div class="list-group-item">
            <div class="d-flex justify-content-between flex-wrap gap-2"><div><a class="fw-semibold" href="<?= site_url('product/' . $e['slug']) ?>"><span class="part-no"><?= esc($e['part_number']) ?></span> — <?= esc($e['name']) ?></a><div class="small text-muted">Buyer from <?= esc(country_name($e['buyer_country'])) ?><?= $e['buyer_verification'] === 'verified' ? ' · <i class="bi bi-patch-check-fill text-primary" aria-hidden="true"></i> verified buyer' : '' ?> · <?= fdt($e['created_at']) ?><?= $e['quantity'] ? ' · Qty ' . number_format((int) $e['quantity']) : '' ?></div></div><?= status_badge($e['status']) ?></div>
            <div class="chat-msg mt-2 small"><?= esc($e['message']) ?></div>
            <?php if ($e['response']): ?><div class="chat-msg mine mt-2 small"><strong>Your reply:</strong> <?= esc($e['response']) ?></div>
            <?php elseif (service('companyContext')->can('rfq.manage')): ?>
                <form method="post" action="<?= site_url('supplier/enquiries/' . $e['id'] . '/respond') ?>" class="mt-2"><?= csrf_field() ?><label class="visually-hidden" for="r<?= $e['id'] ?>">Reply</label><textarea id="r<?= $e['id'] ?>" class="form-control form-control-sm mb-2" name="response" rows="2" required placeholder="Reply to the buyer (do not share direct contact details on confidential listings)"></textarea><button class="btn btn-sm btn-primary" type="submit">Send reply</button></form>
            <?php endif ?>
        </div>
    <?php endforeach ?>
    </div>
<?php endif ?>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Disputes', 'subtitle' => 'Raise a dispute from an order page. BearingCave support coordinates communication and documentation.']) ?>
<div class="bc-card">
<?php if (! $disputes): ?><?= empty_state('bi-shield-check', 'No disputes', 'If something goes wrong with an order, open the order and choose “Raise a dispute”.', '<a class="btn btn-light" href="' . site_url($area . '/orders') . '">View orders</a>') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>Dispute</th><th>Subject</th><th>Category</th><th>Raised by</th><th>Status</th><th>Updated</th></tr></thead>
        <tbody><?php foreach ($disputes as $d): ?><tr>
            <td data-label="Dispute"><a class="part-no" href="<?= site_url($area . '/disputes/' . $d['id']) ?>"><?= esc($d['dispute_number']) ?></a></td><td data-label="Subject"><?= esc($d['subject']) ?></td>
            <td data-label="Category" class="small"><?= esc($categories[$d['category']] ?? $d['category']) ?></td><td data-label="Raised by"><?= (int) $d['raised_by_company_id'] === (int) $company['id'] ? 'You' : 'Counterparty' ?></td>
            <td data-label="Status"><?= status_badge($d['status'], $statuses[$d['status']] ?? null) ?></td><td data-label="Updated" class="small"><?= fdt($d['updated_at']) ?></td>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?= $pager->links() ?>
<?php endif ?>
</div>
<?= $this->endSection() ?>

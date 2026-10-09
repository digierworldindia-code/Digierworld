<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Disputes']) ?>
<div class="d-flex flex-wrap gap-2 mb-3"><?php foreach (['open' => 'Open', 'resolved' => 'Resolved', 'rejected' => 'Rejected', 'closed' => 'Closed', '' => 'All'] as $k => $l): ?><a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>" href="<?= site_url('admin/disputes?status=' . $k) ?>"><?= $l ?></a><?php endforeach ?></div>
<div class="bc-card"><?php if (! $rows): ?><?= empty_state('bi-shield-check', 'No disputes') ?><?php else: ?><div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Dispute</th><th>Raised by</th><th>Against</th><th>Category</th><th>Opened</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $d): ?><tr><td data-label="Dispute"><a class="part-no" href="<?= site_url('admin/disputes/' . $d['id']) ?>"><?= esc($d['dispute_number']) ?></a><div class="small"><?= esc($d['subject']) ?></div></td><td data-label="Raised by" class="small"><?= esc($d['raised_by_name']) ?></td><td data-label="Against" class="small"><?= esc($d['against_name']) ?></td><td data-label="Category" class="small"><?= esc($categories[$d['category']] ?? '') ?></td><td data-label="Opened" class="small"><?= fdate($d['created_at']) ?></td><td data-label="Status"><?= status_badge($d['status'], $statuses[$d['status']]) ?></td></tr><?php endforeach ?>
</tbody></table></div><?= $pager->links() ?><?php endif ?></div>
<?= $this->endSection() ?>

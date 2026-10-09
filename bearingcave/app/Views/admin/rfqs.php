<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'RFQs & supplier matching']) ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['open' => 'Needs review', 'distributed' => 'Distributed', 'evaluation' => 'Quoting', 'awarded' => 'Awarded', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected', '' => 'All'] as $k => $l): ?><a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>" href="<?= site_url('admin/rfqs?status=' . $k) ?>"><?= $l ?></a><?php endforeach ?>
    <form class="ms-auto d-flex gap-1" method="get"><input type="hidden" name="status" value="<?= esc($status, 'attr') ?>"><label class="visually-hidden" for="rq">Search</label><input id="rq" class="form-control form-control-sm" name="q" placeholder="RFQ no., title, buyer" value="<?= esc((string) service('request')->getGet('q'), 'attr') ?>"><button class="btn btn-sm btn-light">Search</button></form>
</div>
<div class="bc-card"><?php if (! $rows): ?><?= empty_state('bi-inbox', 'No RFQs') ?><?php else: ?>
<div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>RFQ</th><th>Buyer</th><th>Destination</th><th>Deadline</th><th class="text-center">Matches</th><th class="text-center">Quotes</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td data-label="RFQ"><a class="part-no fw-semibold" href="<?= site_url('admin/rfqs/' . $r['id']) ?>"><?= esc($r['rfq_number']) ?></a><div class="small"><?= esc($r['title']) ?></div></td><td data-label="Buyer" class="small"><?= esc($r['legal_name']) ?> <?= sample_badge($r) ?></td><td data-label="Destination"><?= esc(country_name($r['destination_country'])) ?></td><td data-label="Deadline" class="small"><?= fdt($r['deadline_at']) ?></td><td data-label="Matches" class="text-center"><?= (int) $r['matches'] ?></td><td data-label="Quotes" class="text-center"><?= (int) $r['quotes'] ?></td><td data-label="Status"><?= status_badge($r['status'], $statuses[$r['status']] ?? null) ?></td></tr><?php endforeach ?>
</tbody></table></div><?= $pager->links() ?><?php endif ?></div>
<?= $this->endSection() ?>

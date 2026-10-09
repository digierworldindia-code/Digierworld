<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Quotations / bids', 'subtitle' => 'All supplier quotations including superseded revisions (visible to BearingCave staff only).']) ?>
<div class="bc-card"><?php if (! $rows): ?><?= empty_state('bi-file-earmark-text', 'No quotations yet') ?><?php else: ?><div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Quotation</th><th>RFQ</th><th>Supplier</th><th>Rev</th><th class="text-end">Total</th><th>Submitted</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $q): ?><tr class="<?= $q['is_current'] ? '' : 'text-muted' ?>"><td data-label="Quotation" class="part-no"><?= esc($q['quotation_number']) ?></td><td data-label="RFQ"><a href="<?= site_url('admin/rfqs/' . $q['rfq_id']) ?>"><?= esc($q['rfq_number']) ?></a></td><td data-label="Supplier" class="small"><?= esc($q['supplier']) ?></td><td data-label="Rev"><?= (int) $q['revision'] ?></td><td data-label="Total" class="text-end"><?= money($q['total_amount'], $q['currency']) ?></td><td data-label="Submitted" class="small"><?= fdt($q['submitted_at']) ?></td><td data-label="Status"><?= status_badge($q['status']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></div>
<?= $this->endSection() ?>

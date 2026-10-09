<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'RFQs & quotations', 'subtitle' => 'Your requests for quotation and the confidential offers received.', 'actions' => '<a class="btn btn-primary btn-sm" href="' . site_url('buyer/rfqs/new') . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New RFQ</a>']) ?>
<form class="d-flex gap-2 mb-3" method="get"><label class="visually-hidden" for="st">Status</label><select id="st" name="status" class="form-select form-select-sm" style="max-width:220px" onchange="this.form.submit()"><option value="">All statuses</option><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= service('request')->getGet('status') === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></form>
<div class="bc-card">
<?php if (! $rfqs): ?><?= empty_state('bi-file-earmark-text', 'No RFQs found', 'Create an RFQ for any part — even if it is not listed.', '<a class="btn btn-primary btn-sm" href="' . site_url('buyer/rfqs/new') . '">Create RFQ</a>') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>RFQ</th><th>Title</th><th>Destination</th><th>Deadline</th><th class="text-center">Offers</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rfqs as $r): $offers = db_connect()->table('quotations')->where('rfq_id', $r['id'])->where('is_current', 1)->whereNotIn('status', ['draft', 'withdrawn'])->countAllResults(); ?><tr>
            <td data-label="RFQ"><a class="part-no" href="<?= site_url('buyer/rfqs/' . $r['id']) ?>"><?= esc($r['rfq_number']) ?></a></td><td data-label="Title"><?= esc($r['title']) ?></td>
            <td data-label="Destination"><?= esc(country_name($r['destination_country'])) ?></td><td data-label="Deadline" class="small"><?= fdt($r['deadline_at']) ?></td>
            <td data-label="Offers" class="text-center"><?= $offers ?></td><td data-label="Status"><?= status_badge($r['status'], $statuses[$r['status']] ?? null) ?></td>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?= $pager->links() ?>
<?php endif ?>
</div>
<?= $this->endSection() ?>

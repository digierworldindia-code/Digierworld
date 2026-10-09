<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Inspection', 'subtitle' => 'In-house and third-party inspection requests for your orders and products.', 'actions' => service('companyContext')->can('services.manage') ? '<a class="btn btn-primary btn-sm" href="' . site_url($area . '/inspections/new') . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Request inspection</a>' : '']) ?>
<div class="bc-card">
<?php if (! $rows): ?><?= empty_state('bi-clipboard-check', 'No inspection requests', 'Request an inspection before goods are dispatched to verify quantity, packaging and authenticity.') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>Request</th><th>Type</th><th>Location</th><th class="text-end">Fee</th><th>Status</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?><tr>
            <td data-label="Request"><a class="part-no" href="<?= site_url($area . '/inspections/' . $r['id']) ?>"><?= esc($r['request_number']) ?></a><?= (int) $r['company_id'] !== (int) $company['id'] ? ' <span class="small text-muted">(by counterparty)</span>' : '' ?></td>
            <td data-label="Type"><?= $r['inspection_type'] === 'in_house' ? 'In-house' : 'Third-party' ?></td>
            <td data-label="Location"><?= esc(trim(($r['location_city'] ? $r['location_city'] . ', ' : '') . country_name($r['location_country']))) ?></td>
            <td data-label="Fee" class="text-end"><?= $r['quoted_fee'] !== null ? money($r['quoted_fee'], $r['currency']) : ($r['estimated_fee'] !== null ? money($r['estimated_fee'], $r['currency']) . ' <span class="small text-muted">est.</span>' : '—') ?></td>
            <td data-label="Status"><?= status_badge($r['status'], $statuses[$r['status']] ?? null) ?></td><td data-label="Created" class="small"><?= fdate($r['created_at']) ?></td>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?= $pager->links() ?>
<?php endif ?>
</div>
<?= $this->endSection() ?>

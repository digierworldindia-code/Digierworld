<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Logistics', 'subtitle' => 'Shipping requests, quotations and shipment tracking.', 'actions' => service('companyContext')->can('services.manage') ? '<a class="btn btn-primary btn-sm" href="' . site_url($area . '/logistics/new') . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Request logistics</a>' : '']) ?>
<div class="bc-card">
<?php if (! $rows): ?><?= empty_state('bi-truck', 'No logistics requests', 'Request BearingCave-managed, confidential or consolidated shipping for your orders.') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>Request</th><th>Mode</th><th>Route</th><th class="text-end">Fee</th><th>Status</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?><tr>
            <td data-label="Request"><a class="part-no" href="<?= site_url($area . '/logistics/' . $r['id']) ?>"><?= esc($r['request_number']) ?></a></td>
            <td data-label="Mode" class="small"><?= esc($modes[$r['mode']] ?? $r['mode']) ?></td>
            <td data-label="Route" class="small"><?= $r['mode'] === 'confidential' && (int) $r['company_id'] !== (int) $company['id'] ? 'Confidential origin' : esc(country_name($r['pickup_country'])) ?> → <?= esc(country_name($r['destination_country'])) ?></td>
            <td data-label="Fee" class="text-end"><?= $r['quoted_fee'] !== null ? money($r['quoted_fee'], $r['currency']) : '—' ?></td>
            <td data-label="Status"><?= status_badge($r['status'], $statuses[$r['status']] ?? null) ?></td><td data-label="Created" class="small"><?= fdate($r['created_at']) ?></td>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?= $pager->links() ?>
<?php endif ?>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'RFQ leads & bidding', 'subtitle' => 'RFQs BearingCave matched to your inventory, brands, categories or approved-vendor status.']) ?>
<?php if (! $entitled): ?>
    <div class="bc-card"><?= empty_state('bi-megaphone', 'Premium RFQ leads are a Verified Supplier feature', 'Complete verification and activate the Verified Supplier membership to receive matched RFQs and submit confidential bids.', '<a class="btn btn-primary btn-sm" href="' . site_url('supplier/membership') . '">View membership</a>') ?></div>
<?php else: ?>
<div class="bc-card">
<?php if (! $rows): ?><?= empty_state('bi-inbox', 'No RFQ invitations yet', 'Keep your inventory, brands and categories up to date to be matched with buyer RFQs.') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>RFQ</th><th>Title</th><th>Destination</th><th class="text-center">Items</th><th>Deadline</th><th>Match</th><th>Your response</th></tr></thead>
        <tbody><?php foreach ($rows as $r): $expired = strtotime($r['deadline_at']) < time(); ?><tr>
            <td data-label="RFQ"><a class="part-no" href="<?= site_url('supplier/rfqs/' . $r['rfq_id']) ?>"><?= esc($r['rfq_number']) ?></a></td><td data-label="Title"><?= esc($r['title']) ?></td>
            <td data-label="Destination"><?= esc(country_name($r['destination_country'])) ?></td><td data-label="Items" class="text-center"><?= (int) $r['item_count'] ?></td>
            <td data-label="Deadline" class="small <?= $expired ? 'text-muted' : '' ?>"><?= fdt($r['deadline_at']) ?><?= $expired ? ' (closed)' : '' ?></td>
            <td data-label="Match" class="small"><?= esc($r['match_score']) ?> pts</td>
            <td data-label="Your response"><?= status_badge($r['status'] === 'viewed' ? 'invited' : $r['status'], ['invited' => 'Not answered', 'viewed' => 'Not answered', 'quoted' => 'Quoted', 'declined' => 'Declined'][$r['status']] ?? null) ?></td>
        </tr><?php endforeach ?></tbody>
    </table></div>
<?php endif ?>
</div>
<?php endif ?>
<?= $this->endSection() ?>

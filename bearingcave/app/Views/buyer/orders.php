<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Orders']) ?>
<form class="d-flex gap-2 mb-3" method="get"><label class="visually-hidden" for="st">Status</label><select id="st" name="status" class="form-select form-select-sm" style="max-width:240px" onchange="this.form.submit()"><option value="">All statuses</option><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= service('request')->getGet('status') === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></form>
<div class="bc-card">
<?php if (! $orders): ?><?= empty_state('bi-box-seam', 'No orders', 'Orders appear here when you accept a quotation or order from your basket.') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>Order</th><th>Date</th><th class="text-end">Total</th><th>Payment</th><th>Shipment</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($orders as $o): ?><tr>
            <td data-label="Order"><a class="part-no" href="<?= site_url($area . '/orders/' . $o['id']) ?>"><?= esc($o['order_number']) ?></a></td><td data-label="Date" class="small"><?= fdate($o['created_at']) ?></td>
            <td data-label="Total" class="text-end"><?= money($o['total'], $o['currency']) ?></td><td data-label="Payment"><?= status_badge($o['payment_status']) ?></td>
            <td data-label="Shipment"><?= status_badge($o['shipment_status']) ?></td><td data-label="Status"><?= status_badge($o['status'], $statuses[$o['status']] ?? null) ?></td>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?= $pager->links() ?>
<?php endif ?>
</div>
<?= $this->endSection() ?>

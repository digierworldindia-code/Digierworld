<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $o = $order; $canManage = $side === 'staff' || service('companyContext')->can('orders.manage'); ?>
<?= view('components/page_header', ['title' => 'Order ' . $o['order_number'], 'subtitle' => 'Placed ' . date('d M Y', strtotime($o['created_at'])), 'breadcrumbs' => ['Orders' => ($side === 'staff' ? 'admin' : $area) . '/orders', $o['order_number'] => null], 'actions' => '<button class="btn btn-light btn-sm" onclick="window.print()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>']) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="bc-card mb-4"><div class="bc-card-body">
            <div class="row g-3">
                <div class="col-md-4"><div class="small-caps">Status</div><?= status_badge($o['status'], $statuses[$o['status']] ?? null) ?></div>
                <div class="col-md-4"><div class="small-caps">Payment</div><?= status_badge($o['payment_status']) ?></div>
                <div class="col-md-4"><div class="small-caps">Shipment</div><?= status_badge($o['shipment_status']) ?></div>
            </div>
            <hr>
            <dl class="dl-grid small mb-0">
                <dt><?= $side === 'buyer' ? 'Supplier' : ($side === 'supplier' ? 'Buyer' : 'Parties') ?></dt><dd><?= esc($counterparty) ?> <?= ! empty($counterpartyVerified) ? '<span class="badge-verified"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Verified</span>' : '' ?></dd>
                <dt>Source</dt><dd><?= $o['source'] === 'quotation' ? 'Accepted quotation' : 'Marketplace listing (basket)' ?></dd>
                <dt>Delivery</dt><dd><?= esc(country_name($o['delivery_country'])) ?><?= $side !== 'supplier' || $o['logistics_mode'] === 'supplier_direct' ? '<div class="text-muted">' . esc($o['delivery_address'] ?? '') . '</div>' : '<div class="text-muted">Address shared with logistics provider</div>' ?></dd>
                <dt>Shipping</dt><dd><?= esc(ucfirst(str_replace('_', ' ', $o['logistics_mode']))) ?><?= $o['incoterm'] ? ' · ' . esc($o['incoterm']) : '' ?></dd>
                <?php if ($o['buyer_po_reference']): ?><dt>Buyer PO</dt><dd><?= esc($o['buyer_po_reference']) ?></dd><?php endif ?>
                <dt>Commercial terms</dt><dd><?= esc($o['commercial_terms'] ?? '') ?><?= $o['payment_terms'] ? '<div>Payment terms: ' . esc($o['payment_terms']) . '</div>' : '' ?></dd>
                <?php if ($o['cancelled_reason']): ?><dt>Cancellation</dt><dd><?= esc($o['cancelled_reason']) ?></dd><?php endif ?>
            </dl>
        </div></div>
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Items</h2></div>
            <div class="table-responsive"><table class="table table-bc table-stack mb-0">
                <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Total</th></tr></thead>
                <tbody><?php foreach ($items as $it): ?><tr><td data-label="Item"><span class="part-no fw-semibold"><?= esc($it['part_number'] ?? '') ?></span> <?= esc($it['description']) ?><?= $it['stock_reserved'] ? ' <span class="badge-soft info">Stock reserved</span>' : '' ?></td><td data-label="Qty" class="text-end"><?= number_format((int) $it['quantity']) ?></td><td data-label="Unit price" class="text-end"><?= money($it['unit_price'], $o['currency'], 2) ?></td><td data-label="Total" class="text-end"><?= money($it['line_total'], $o['currency']) ?></td></tr><?php endforeach ?></tbody>
                <tfoot class="small">
                    <tr><td colspan="3" class="text-end">Subtotal</td><td class="text-end"><?= money($o['subtotal'], $o['currency']) ?></td></tr>
                    <?php if ((float) $o['inspection_fee'] > 0): ?><tr><td colspan="3" class="text-end">Inspection (invoiced separately)</td><td class="text-end"><?= money($o['inspection_fee'], $o['currency']) ?></td></tr><?php endif ?>
                    <?php if ((float) $o['logistics_fee'] > 0): ?><tr><td colspan="3" class="text-end">Logistics (invoiced separately)</td><td class="text-end"><?= money($o['logistics_fee'], $o['currency']) ?></td></tr><?php endif ?>
                    <tr class="fw-bold"><td colspan="3" class="text-end">Order total (goods)</td><td class="text-end"><?= money($o['total'], $o['currency']) ?></td></tr>
                </tfoot>
            </table></div>
        </div>
        <div class="bc-card"><div class="bc-card-header"><h2>History</h2></div><div class="bc-card-body">
            <ul class="timeline small mb-0"><?php foreach ($history as $h): ?><li><div class="fw-semibold"><?= esc($statuses[$h['to_status']] ?? $h['to_status']) ?></div><?php if ($h['note']): ?><div><?= esc($h['note']) ?></div><?php endif ?><div class="when"><?= fdt($h['created_at']) ?></div></li><?php endforeach ?></ul>
        </div></div>
    </div>
    <div class="col-xl-4">
        <?php if ($actions && $canManage): ?>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>Actions</h2></div><div class="bc-card-body d-grid gap-2">
            <?php foreach ($actions as $to => $label): ?>
                <form method="post" action="<?= site_url(($side === 'staff' ? 'admin' : $area) . '/orders/' . $o['id'] . '/status') ?>"<?= $to === 'cancelled' ? ' data-confirm="Cancel this order? Reserved stock will be released."' : '' ?>>
                    <?= csrf_field() ?><input type="hidden" name="to" value="<?= esc($to, 'attr') ?>">
                    <?php if ($to === 'cancelled' || $to === 'shipped' || $side === 'staff'): ?><label class="visually-hidden" for="note<?= $to ?>">Note</label><input id="note<?= $to ?>" class="form-control form-control-sm mb-1" name="note" placeholder="<?= $to === 'cancelled' ? 'Reason (required)' : 'Note (optional)' ?>"<?= $to === 'cancelled' ? ' required' : '' ?>><?php endif ?>
                    <button class="btn btn-sm w-100 <?= $to === 'cancelled' ? 'btn-outline-danger' : 'btn-primary' ?>" type="submit"><?= esc($label) ?></button>
                </form>
            <?php endforeach ?>
        </div></div>
        <?php endif ?>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>Invoices</h2></div><div class="bc-card-body small">
            <?php if (! $invoices): ?><p class="text-muted mb-0">A proforma invoice is issued when the order is confirmed.</p><?php endif ?>
            <?php foreach ($invoices as $inv): ?><div class="d-flex justify-content-between mb-1"><span><?php if ($side === 'buyer'): ?><a href="<?= site_url('buyer/billing/invoices/' . $inv['id']) ?>"><?= esc($inv['invoice_number']) ?></a><?php elseif ($side === 'staff'): ?><a href="<?= site_url('admin/invoices/' . $inv['id']) ?>"><?= esc($inv['invoice_number']) ?></a><?php else: ?><?= esc($inv['invoice_number']) ?><?php endif ?></span><span><?= money($inv['total'], $inv['currency']) ?> <?= status_badge($inv['status']) ?></span></div><?php endforeach ?>
        </div></div>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>Inspection</h2><?php if ($side !== 'staff' && ! in_array($o['status'], ['cancelled', 'completed'], true)): ?><a class="small" href="<?= site_url($area . '/inspections/new?order_id=' . $o['id']) ?>">Request</a><?php endif ?></div><div class="bc-card-body small">
            <?php if (! $inspections): ?><p class="text-muted mb-0">No inspection requested.</p><?php endif ?>
            <?php foreach ($inspections as $r): ?><div class="d-flex justify-content-between mb-1"><a href="<?= site_url(($side === 'staff' ? 'admin' : $area) . '/inspections/' . $r['id']) ?>"><?= esc($r['request_number']) ?></a><?= status_badge($r['status']) ?></div><?php endforeach ?>
        </div></div>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>Logistics</h2><?php if ($side !== 'staff' && in_array($o['status'], ['confirmed', 'awaiting_payment', 'paid', 'in_fulfilment'], true)): ?><a class="small" href="<?= site_url($area . '/logistics/new?order_id=' . $o['id']) ?>">Request</a><?php endif ?></div><div class="bc-card-body small">
            <?php if (! $logistics): ?><p class="text-muted mb-0">No logistics request yet.</p><?php endif ?>
            <?php foreach ($logistics as $r): ?><div class="d-flex justify-content-between mb-1"><a href="<?= site_url(($side === 'staff' ? 'admin' : $area) . '/logistics/' . $r['id']) ?>"><?= esc($r['request_number']) ?></a><?= status_badge($r['status']) ?></div><?php endforeach ?>
        </div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Disputes</h2><?php if ($side !== 'staff' && ! in_array($o['status'], ['pending_supplier_confirmation', 'cancelled'], true)): ?><a class="small text-danger" href="<?= site_url($area . '/disputes/new/' . $o['id']) ?>">Raise a dispute</a><?php endif ?></div><div class="bc-card-body small">
            <?php if (! $disputes): ?><p class="text-muted mb-0">No disputes.</p><?php endif ?>
            <?php foreach ($disputes as $d): ?><div class="d-flex justify-content-between mb-1"><a href="<?= site_url(($side === 'staff' ? 'admin' : $area) . '/disputes/' . $d['id']) ?>"><?= esc($d['dispute_number']) ?></a><?= status_badge($d['status']) ?></div><?php endforeach ?>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>

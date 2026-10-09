<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $base = 'admin/logistics/' . $r['id']; ?>
<?= view('components/page_header', ['title' => 'Logistics ' . $r['request_number'], 'subtitle' => $company['legal_name'] . ' · ' . ($modes[$r['mode']] ?? $r['mode']), 'breadcrumbs' => ['Logistics' => 'admin/logistics', $r['request_number'] => null]]) ?>
<?php if ($r['mode'] === 'confidential'): ?><div class="alert alert-info small"><i class="bi bi-incognito me-1" aria-hidden="true"></i>Confidential shipment: never disclose the pickup address or supplier identity to the buyer. BearingCave is shipper of record.</div><?php endif ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="bc-card mb-4"><div class="bc-card-body"><dl class="dl-grid small mb-0">
            <dt>Status</dt><dd><?= status_badge($r['status'], $statuses[$r['status']]) ?></dd>
            <?php if ($r['order_id']): ?><dt>Order</dt><dd><a href="<?= site_url('admin/orders/' . $r['order_id']) ?>">Order #<?= (int) $r['order_id'] ?></a></dd><?php endif ?>
            <dt>Pickup</dt><dd><?= esc(trim(($r['pickup_city'] ? $r['pickup_city'] . ', ' : '') . country_name($r['pickup_country']))) ?><div class="text-muted"><?= esc($r['pickup_address'] ?? '') ?> <?= esc($r['pickup_contact'] ?? '') ?></div></dd>
            <dt>Destination</dt><dd><?= esc(trim(($r['destination_city'] ? $r['destination_city'] . ', ' : '') . country_name($r['destination_country']))) ?><div class="text-muted"><?= esc($r['destination_address'] ?? '') ?> <?= esc($r['destination_contact'] ?? '') ?></div></dd>
            <dt>Cargo</dt><dd><?= esc($r['cargo_description'] ?? '—') ?> · <?= esc((string) ($r['weight_kg'] ?? '–')) ?> kg · <?= esc((string) ($r['volume_cbm'] ?? '–')) ?> m³ · <?= esc((string) ($r['packages'] ?? '–')) ?> pkgs · value <?= money($r['goods_value'], $r['currency']) ?></dd>
            <dt>Incoterm</dt><dd><?= esc($r['incoterm'] ?? '—') ?></dd><dt>Fee</dt><dd><?= $r['quoted_fee'] !== null ? money($r['quoted_fee'], $r['currency']) : '—' ?> · <?= esc($r['fee_basis'] ?? '') ?></dd>
            <?php if ($group): ?><dt>Consolidation group</dt><dd><?= esc($r['consolidation_ref']) ?>: <?php foreach ($group as $g): ?><a href="<?= site_url('admin/logistics/' . $g['id']) ?>"><?= esc($g['request_number']) ?></a> <?php endforeach ?></dd><?php endif ?>
        </dl></div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Shipments</h2></div><div class="bc-card-body">
            <?php if (! $shipments): ?><p class="small text-muted">No shipment booked.</p><?php endif ?>
            <?php foreach ($shipments as $s): ?><div class="border rounded p-3 mb-3"><div class="d-flex justify-content-between"><strong><?= esc($s['carrier']) ?> · <?= esc($s['tracking_number'] ?? '') ?></strong><?= status_badge($s['status'], $shipmentStatuses[$s['status']] ?? null) ?></div><div class="small text-muted mb-2">ETA <?= fdate($s['eta']) ?> · <?= esc($s['transport_mode']) ?><?= $s['delivered_on_time'] !== null ? ' · ' . ($s['delivered_on_time'] ? 'on time' : 'late') : '' ?></div>
                <ul class="timeline small"><?php foreach ($events[$s['id']] ?? [] as $e): ?><li><div class="fw-semibold"><?= esc($shipmentStatuses[$e['status']] ?? $e['status']) ?> <?= $e['location'] ? '— ' . esc($e['location']) : '' ?></div><div><?= esc($e['description'] ?? '') ?></div><div class="when"><?= fdt($e['event_at']) ?></div></li><?php endforeach ?></ul>
                <?php if ($s['status'] !== 'delivered'): ?><form method="post" action="<?= site_url('admin/logistics/shipments/' . $s['id']) ?>" class="row g-2"><?= csrf_field() ?><div class="col-md-4"><?= select_field('status', 'Status', $shipmentStatuses, null, ['class' => '']) ?></div><div class="col-md-4"><?= field('location', 'Location', null, ['class' => '']) ?></div><div class="col-md-4"><?= field('description', 'Note', null, ['class' => '']) ?></div><div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Add tracking event</button></div></form><?php endif ?>
            </div><?php endforeach ?>
        </div></div>
    </div>
    <div class="col-xl-5">
        <?php if (in_array($r['status'], ['requested', 'quoted'], true)): ?>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>Quotation</h2></div><div class="bc-card-body"><p class="small text-muted">Policy estimate: <?= $estimate['fee'] !== null ? money($estimate['fee'], $r['currency']) : '—' ?> — <?= esc($estimate['basis']) ?></p><form method="post" action="<?= site_url($base . '/quote') ?>" novalidate><?= csrf_field() ?><?= field('quoted_fee', 'Total fee (' . $r['currency'] . ')', $r['quoted_fee'] ?? $estimate['fee'], ['required' => true]) ?><?= field('fee_basis', 'Basis shown to requester', $r['fee_basis']) ?><button class="btn btn-sm btn-primary" type="submit">Send quotation</button></form></div></div>
        <?php endif ?>
        <?php if ($r['status'] === 'accepted' || ($r['mode'] === 'supplier_direct' && in_array($r['status'], ['requested', 'quoted'], true))): ?>
        <div class="bc-card"><div class="bc-card-header"><h2>Book shipment</h2></div><div class="bc-card-body"><form method="post" action="<?= site_url($base . '/book') ?>" novalidate><?= csrf_field() ?><?= field('carrier', 'Carrier', null, ['required' => true]) ?><?= field('tracking_number', 'Tracking number', null) ?><?= select_field('transport_mode', 'Mode', ['road' => 'Road', 'air' => 'Air', 'sea' => 'Sea', 'rail' => 'Rail', 'courier' => 'Courier'], 'road') ?><?= field('eta', 'ETA', null, ['type' => 'date']) ?><?= field('location', 'Pickup location (internal)', null) ?><button class="btn btn-sm btn-primary" type="submit">Book</button></form></div></div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

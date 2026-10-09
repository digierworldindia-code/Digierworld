<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Logistics ' . $r['request_number'], 'breadcrumbs' => ['Logistics' => $area . '/logistics', $r['request_number'] => null]]) ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="bc-card mb-4"><div class="bc-card-body">
            <dl class="dl-grid small mb-0">
                <dt>Status</dt><dd><?= status_badge($r['status'], $statuses[$r['status']]) ?></dd>
                <dt>Mode</dt><dd><?= esc($modes[$r['mode']] ?? $r['mode']) ?></dd>
                <dt>Pickup</dt><dd><?= $r['pickup_address'] === null && $r['pickup_city'] === null && ! $isRequester ? '<span class="text-muted">Confidential — handled by BearingCave</span>' : esc(trim(($r['pickup_city'] ? $r['pickup_city'] . ', ' : '') . country_name($r['pickup_country']))) . ($r['pickup_address'] ? '<div class="text-muted">' . esc($r['pickup_address']) . '</div>' : '') ?></dd>
                <dt>Destination</dt><dd><?= esc(trim(($r['destination_city'] ? $r['destination_city'] . ', ' : '') . country_name($r['destination_country']))) ?><?= $r['destination_address'] ? '<div class="text-muted">' . esc($r['destination_address']) . '</div>' : '' ?></dd>
                <dt>Cargo</dt><dd><?= esc($r['cargo_description'] ?? '—') ?> <?= $r['weight_kg'] ? '· ' . esc($r['weight_kg']) . ' kg' : '' ?> <?= $r['volume_cbm'] ? '· ' . esc($r['volume_cbm']) . ' m³' : '' ?></dd>
                <dt>Incoterm</dt><dd><?= esc($r['incoterm'] ?? '—') ?></dd>
                <dt>Fee</dt><dd><?= $r['quoted_fee'] !== null ? '<strong>' . money($r['quoted_fee'], $r['currency']) . '</strong>' : 'Awaiting quotation' ?> <span class="text-muted">— <?= esc($r['fee_basis'] ?? '') ?></span></dd>
            </dl>
        </div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Shipments &amp; tracking</h2></div><div class="bc-card-body">
            <?php if (! $shipments): ?><p class="text-muted small mb-0">No shipment booked yet.</p><?php endif ?>
            <?php foreach ($shipments as $s): ?>
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex flex-wrap justify-content-between gap-2"><div><strong><?= esc($s['carrier']) ?></strong> · <?= esc(ucfirst($s['transport_mode'])) ?><?= $s['tracking_number'] ? ' · <span class="part-no">' . esc($s['tracking_number']) . '</span>' : '' ?></div><?= status_badge($s['status'], $shipmentStatuses[$s['status']] ?? null) ?></div>
                    <div class="small text-muted mb-2">ETA <?= fdate($s['eta']) ?><?= $s['delivered_at'] ? ' · Delivered ' . fdt($s['delivered_at']) : '' ?></div>
                    <ul class="timeline small mb-0"><?php foreach ($events[$s['id']] ?? [] as $e): ?><li><div class="fw-semibold"><?= esc($shipmentStatuses[$e['status']] ?? $e['status']) ?><?= $e['location'] ? ' — ' . esc($e['location']) : '' ?></div><div><?= esc($e['description'] ?? '') ?></div><div class="when"><?= fdt($e['event_at']) ?></div></li><?php endforeach ?></ul>
                    <?php if ($canBook && $s['status'] !== 'delivered'): ?>
                    <form method="post" action="<?= site_url($area . '/shipments/' . $s['id'] . '/update') ?>" class="row g-2 mt-2">
                        <?= csrf_field() ?>
                        <div class="col-md-4"><?= select_field('status', 'Update status', $shipmentStatuses, null, ['class' => '']) ?></div>
                        <div class="col-md-4"><?= field('location', 'Location', null, ['class' => '']) ?></div>
                        <div class="col-md-4"><?= field('description', 'Note', null, ['class' => '']) ?></div>
                        <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Add tracking update</button></div>
                    </form>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
            <?php if ($canBook && ! $shipments && in_array($r['status'], ['requested', 'quoted', 'accepted'], true)): ?>
                <form method="post" action="<?= site_url($area . '/logistics/' . $r['id'] . '/book') ?>" class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-md-4"><?= field('carrier', 'Carrier', null, ['required' => true, 'class' => '']) ?></div>
                    <div class="col-md-4"><?= field('tracking_number', 'Tracking number', null, ['class' => '']) ?></div>
                    <div class="col-md-2"><?= select_field('transport_mode', 'Mode', ['road' => 'Road', 'air' => 'Air', 'sea' => 'Sea', 'rail' => 'Rail', 'courier' => 'Courier'], 'road', ['class' => '']) ?></div>
                    <div class="col-md-2"><?= field('eta', 'ETA', null, ['type' => 'date', 'class' => '']) ?></div>
                    <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Book shipment</button></div>
                </form>
            <?php endif ?>
        </div></div>
    </div>
    <div class="col-lg-4">
        <?php if ($isRequester && $r['status'] === 'quoted'): ?>
        <div class="bc-card"><div class="bc-card-body">
            <h2 class="h6">Respond to quotation</h2>
            <p class="small text-muted">Accepting issues an invoice for <?= money($r['quoted_fee'], $r['currency']) ?> (plus applicable tax).</p>
            <div class="d-flex gap-2"><?= post_button($area . '/logistics/' . $r['id'] . '/respond', 'Accept', 'btn btn-primary btn-sm', null, ['decision' => 'accept']) ?><?= post_button($area . '/logistics/' . $r['id'] . '/respond', 'Decline', 'btn btn-light btn-sm', 'Decline this quotation?', ['decision' => 'decline']) ?></div>
        </div></div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

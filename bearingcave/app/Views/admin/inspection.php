<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $base = 'admin/inspections/' . $r['id']; ?>
<?= view('components/page_header', ['title' => 'Inspection ' . $r['request_number'], 'subtitle' => $company['legal_name'], 'breadcrumbs' => ['Inspection' => 'admin/inspections', $r['request_number'] => null]]) ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="bc-card mb-4"><div class="bc-card-body"><dl class="dl-grid small mb-0">
            <dt>Status</dt><dd><?= status_badge($r['status'], $statuses[$r['status']]) ?></dd><dt>Type</dt><dd><?= $r['inspection_type'] === 'in_house' ? 'In-house' : 'Third-party' ?></dd>
            <dt>Services</dt><dd><?= esc(implode(', ', array_map(static fn ($s) => $scopes[$s] ?? $s, (array) json_decode((string) $r['scope'], true)))) ?></dd>
            <dt>Location</dt><dd><?= esc(trim(($r['location_city'] ? $r['location_city'] . ', ' : '') . country_name($r['location_country']))) ?></dd>
            <?php if ($r['order_id']): ?><dt>Order</dt><dd><a href="<?= site_url('admin/orders/' . $r['order_id']) ?>">Order #<?= (int) $r['order_id'] ?></a></dd><?php endif ?>
            <?php if ($r['product_id']): ?><dt>Product</dt><dd><a href="<?= site_url('admin/products/' . $r['product_id']) ?>">Product #<?= (int) $r['product_id'] ?></a></dd><?php endif ?>
            <dt>Invoice value</dt><dd><?= money($r['invoice_value'], $r['currency']) ?></dd><dt>Volume</dt><dd><?= $r['shipment_volume_cbm'] ? esc($r['shipment_volume_cbm']) . ' m³' : '—' ?></dd>
            <dt>Estimate</dt><dd><?= $r['estimated_fee'] !== null ? money($r['estimated_fee'], $r['currency']) : '—' ?> · <?= esc($r['fee_basis'] ?? '') ?></dd>
            <dt>Quoted fee</dt><dd><?= $r['quoted_fee'] !== null ? money($r['quoted_fee'], $r['currency']) : '—' ?></dd>
            <dt>Inspector</dt><dd><?= $r['inspector_user_id'] ? esc($inspectors[$r['inspector_user_id']] ?? '#' . $r['inspector_user_id']) : '' ?> <?= esc($r['third_party_agency'] ?? '') ?> <?= $r['scheduled_on'] ? '· ' . fdate($r['scheduled_on']) : '' ?></dd>
            <?php if ($r['notes']): ?><dt>Requester notes</dt><dd><?= esc($r['notes']) ?></dd><?php endif ?>
        </dl></div></div>
        <?php if ($report): ?><div class="bc-card mb-4"><div class="bc-card-header"><h2>Report</h2><?= status_badge($report['result']) ?></div><div class="bc-card-body small"><p><?= esc($report['summary']) ?></p><?php if ($report['findings']): ?><p class="text-muted"><?= esc($report['findings']) ?></p><?php endif ?><?php foreach ($documents as $d): ?><a href="<?= site_url('documents/' . $d['uuid']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> <?= esc($d['title']) ?></a><br><?php endforeach ?></div></div><?php endif ?>
    </div>
    <div class="col-xl-5">
        <?php if (in_array($r['status'], ['requested', 'quoted', 'declined'], true)): ?>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>1. Quotation</h2></div><div class="bc-card-body"><form method="post" action="<?= site_url($base . '/quote') ?>" novalidate><?= csrf_field() ?>
            <div class="row g-2"><div class="col-6"><?= field('quoted_fee', 'Fee (' . $r['currency'] . ')', $r['quoted_fee'] ?? $r['estimated_fee'], ['required' => true, 'class' => '']) ?></div><div class="col-6"><?= field('quote_valid_until', 'Valid until', date('Y-m-d', strtotime('+14 days')), ['type' => 'date', 'class' => '']) ?></div></div>
            <?= field('fee_basis', 'Fee basis (shown to requester)', $r['fee_basis']) ?><button class="btn btn-sm btn-primary" type="submit">Send quotation</button></form></div></div>
        <?php endif ?>
        <?php if (in_array($r['status'], ['accepted', 'assigned'], true)): ?>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>2. Assign inspector</h2></div><div class="bc-card-body"><form method="post" action="<?= site_url($base . '/assign') ?>"><?= csrf_field() ?>
            <?= select_field('inspector_user_id', 'In-house inspector', $inspectors, $r['inspector_user_id'], ['placeholder' => '—']) ?><?= field('third_party_agency', 'Or third-party agency', $r['third_party_agency']) ?><?= field('scheduled_on', 'Scheduled date', $r['scheduled_on'], ['type' => 'date']) ?><button class="btn btn-sm btn-primary" type="submit">Save assignment</button></form>
            <?php if ($r['status'] === 'assigned'): ?><div class="mt-2"><?= post_button($base . '/start', 'Mark inspection in progress', 'btn btn-sm btn-light') ?></div><?php endif ?></div></div>
        <?php endif ?>
        <?php if (in_array($r['status'], ['assigned', 'in_progress'], true)): ?>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>3. Upload report</h2></div><div class="bc-card-body"><form method="post" action="<?= site_url($base . '/report') ?>" enctype="multipart/form-data" novalidate><?= csrf_field() ?>
            <div class="row g-2"><div class="col-6"><?= select_field('result', 'Result', ['pass' => 'Pass', 'conditional' => 'Conditional', 'fail' => 'Fail'], null, ['required' => true, 'placeholder' => 'Select', 'class' => '']) ?></div><div class="col-6"><?= field('inspected_on', 'Inspected on', date('Y-m-d'), ['type' => 'date', 'required' => true, 'class' => '']) ?></div>
            <div class="col-6"><?= field('quantity_expected', 'Qty expected', null, ['type' => 'number', 'class' => '']) ?></div><div class="col-6"><?= field('quantity_verified', 'Qty verified', null, ['type' => 'number', 'class' => '']) ?></div></div>
            <?= check_field('packaging_ok', 'Packaging acceptable', true) ?><?= textarea_field('authenticity_notes', 'Batch authenticity notes', null, ['rows' => 2]) ?><?= textarea_field('summary', 'Summary (shared with parties)', null, ['rows' => 3, 'required' => true]) ?><?= textarea_field('findings', 'Detailed findings', null, ['rows' => 3]) ?>
            <label class="form-label" for="rep">Signed report / evidence (PDF or image)<span class="required-mark" aria-hidden="true">*</span></label><input id="rep" class="form-control form-control-sm mb-2" type="file" name="report" accept=".pdf,.jpg,.jpeg,.png" required>
            <button class="btn btn-sm btn-primary" type="submit">Upload report</button></form></div></div>
        <?php endif ?>
        <?php if ($r['status'] === 'report_uploaded'): ?><div class="bc-card mb-3"><div class="bc-card-body"><?= post_button($base . '/complete', 'Mark inspection completed', 'btn btn-primary btn-sm w-100') ?></div></div><?php endif ?>
        <?php if (in_array($r['status'], ['requested', 'quoted', 'accepted', 'declined'], true)): ?><div class="bc-card"><div class="bc-card-body"><form method="post" action="<?= site_url($base . '/cancel') ?>" class="d-flex gap-1" data-confirm="Cancel this inspection request?"><?= csrf_field() ?><input class="form-control form-control-sm" name="reason" placeholder="Reason" aria-label="Cancellation reason"><button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button></form></div></div><?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

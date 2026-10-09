<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $open = in_array($rfq['status'], ['submitted', 'under_review', 'distributed', 'evaluation'], true); $canAct = service('companyContext')->can('rfq.manage'); $canOrder = service('companyContext')->can('orders.manage'); ?>
<?= view('components/page_header', ['title' => $rfq['title'], 'subtitle' => $rfq['rfq_number'] . ' · deadline ' . date('d M Y H:i', strtotime($rfq['deadline_at'])), 'breadcrumbs' => ['RFQs' => 'buyer/rfqs', $rfq['rfq_number'] => null],
    'actions' => ($open && $canAct ? '<form method="post" action="' . site_url('buyer/rfqs/' . $rfq['id'] . '/cancel') . '" data-confirm="Cancel this RFQ? Suppliers will be notified.">' . csrf_field() . '<button class="btn btn-sm btn-outline-danger" type="submit">Cancel RFQ</button></form>' : '')]) ?>
<div class="row g-4">
    <div class="col-xl-4 order-xl-2">
        <div class="bc-card mb-3"><div class="bc-card-body">
            <dl class="dl-grid small mb-0">
                <dt>Status</dt><dd><?= status_badge($rfq['status'], $statuses[$rfq['status']]) ?></dd>
                <dt>Destination</dt><dd><?= esc(country_name($rfq['destination_country'])) ?></dd>
                <dt>Incoterm</dt><dd><?= esc($rfq['delivery_terms'] ?? '—') ?></dd>
                <dt>Required by</dt><dd><?= fdate($rfq['required_by']) ?></dd>
                <dt>Suppliers invited</dt><dd><?= (int) $invited ?></dd>
                <?php if ($rfq['admin_notes'] && in_array($rfq['status'], ['rejected'], true)): ?><dt>BearingCave note</dt><dd><?= esc($rfq['admin_notes']) ?></dd><?php endif ?>
            </dl>
        </div></div>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>Items requested</h2></div><ul class="list-group list-group-flush small">
            <?php foreach ($items as $it): ?><li class="list-group-item"><span class="part-no fw-semibold"><?= esc($it['part_number'] ?? '') ?></span> <?= esc($it['description']) ?><br><span class="text-muted">Qty <?= number_format((int) $it['quantity']) ?> <?= esc($it['unit']) ?><?= $it['target_unit_price'] !== null ? ' · target ' . money($it['target_unit_price'], $rfq['currency'], 2) : '' ?></span></li><?php endforeach ?>
        </ul></div>
        <?php if ($documents): ?><div class="bc-card"><div class="bc-card-body small"><?php foreach ($documents as $d): ?><a href="<?= site_url('documents/' . $d['uuid']) ?>"><i class="bi bi-paperclip" aria-hidden="true"></i> <?= esc($d['title']) ?></a><br><?php endforeach ?></div></div><?php endif ?>
    </div>
    <div class="col-xl-8 order-xl-1">
        <h2 class="h5 mb-3">Quotations <span class="text-muted fs-6">(<?= count($offers) ?>)</span></h2>
        <?php if (! $offers): ?>
            <div class="bc-card"><?= empty_state('bi-hourglass-split', in_array($rfq['status'], ['submitted', 'under_review'], true) ? 'Under review by BearingCave' : 'Waiting for quotations', in_array($rfq['status'], ['submitted', 'under_review'], true) ? 'We are reviewing your RFQ and matching it with verified suppliers.' : 'Invited suppliers can quote until the deadline. You will be notified of each offer.') ?></div>
        <?php else: ?>
            <div class="bc-card mb-3 table-responsive"><table class="table table-bc mb-0">
                <caption class="visually-hidden">Offer comparison</caption>
                <thead><tr><th>Supplier</th><th class="text-end">Total</th><th>Lead time</th><th>Terms</th><th>Logistics</th><th>Inspection</th><?= $advanced ? '<th>Performance</th>' : '' ?><th>Status</th></tr></thead>
                <tbody><?php $min = min(array_map(static fn ($o) => (float) $o['q']['total_amount'], $offers)); foreach ($offers as $o): $q = $o['q']; ?>
                    <tr><td><?= esc($o['label']) ?> <?= $o['verified'] ? '<i class="bi bi-patch-check-fill text-primary" title="Verified" aria-label="Verified"></i>' : '' ?><div class="small text-muted"><?= esc($q['quotation_number']) ?> · rev <?= (int) $q['revision'] ?></div></td>
                    <td class="text-end fw-semibold"><?= money($q['total_amount'], $q['currency']) ?><?= (float) $q['total_amount'] === $min ? '<div class="small text-success">Lowest</div>' : '' ?></td>
                    <td><?= $q['lead_time_days'] !== null ? (int) $q['lead_time_days'] . ' days' : '—' ?></td><td class="small"><?= esc(trim(($q['delivery_terms'] ?? '') . ' ' . ($q['payment_terms'] ?? '')) ?: '—') ?></td>
                    <td class="small"><?= esc(str_replace('_', ' ', $q['logistics_option'])) ?></td><td><?= $q['inspection_offered'] ? 'Offered' : '—' ?></td>
                    <?php if ($advanced): ?><td class="small"><?= $o['perf'] && $o['perf']['overall_score'] !== null ? esc($o['perf']['overall_score']) . '/100' : 'No data' ?></td><?php endif ?>
                    <td><?= status_badge($q['status']) ?></td></tr>
                <?php endforeach ?></tbody>
            </table></div>
            <?php foreach ($offers as $o): $q = $o['q']; $live = in_array($q['status'], ['submitted', 'shortlisted'], true); ?>
            <div class="bc-card mb-3">
                <div class="bc-card-header"><h3 class="h6 mb-0"><?= esc($o['label']) ?> — <?= money($q['total_amount'], $q['currency']) ?></h3><?= status_badge($q['status']) ?></div>
                <div class="bc-card-body">
                    <div class="table-responsive"><table class="table table-sm small"><thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Line total</th><th>Lead time</th></tr></thead><tbody>
                    <?php foreach ($o['items'] as $qi): ?><tr><td><span class="part-no"><?= esc($qi['part_number'] ?? '') ?></span> <?= esc($qi['description']) ?><?= $qi['notes'] ? '<div class="text-muted">' . esc($qi['notes']) . '</div>' : '' ?></td><td class="text-end"><?= number_format((int) $qi['quantity']) ?></td><td class="text-end"><?= money($qi['unit_price'], $q['currency'], 2) ?></td><td class="text-end"><?= money($qi['line_total'], $q['currency']) ?></td><td><?= $qi['lead_time_days'] !== null ? (int) $qi['lead_time_days'] . ' d' : '—' ?></td></tr><?php endforeach ?>
                    </tbody></table></div>
                    <?php if ($q['notes']): ?><p class="small"><strong>Supplier notes:</strong> <?= esc($q['notes']) ?></p><?php endif ?>
                    <p class="small text-muted">Valid until <?= fdate($q['valid_until']) ?></p>
                    <?php if ($o['messages']): ?><div class="d-grid gap-2 mb-3"><?php foreach ($o['messages'] as $m): ?><div class="chat-msg small <?= $m['sender_side'] === 'buyer' ? 'mine' : '' ?>"><strong><?= $m['sender_side'] === 'buyer' ? 'You' : 'Supplier' ?></strong> · <?= fdt($m['created_at']) ?><?= $m['proposed_total'] !== null ? ' · proposed total ' . money($m['proposed_total'], $q['currency']) : '' ?><div><?= esc($m['message']) ?></div></div><?php endforeach ?></div><?php endif ?>
                    <?php if ($live && $canAct): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($q['status'] === 'submitted'): ?><?= post_button('buyer/quotations/' . $q['id'] . '/shortlist', 'Shortlist', 'btn btn-sm btn-light') ?><?php endif ?>
                        <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#neg<?= $q['id'] ?>">Negotiate</button>
                        <?= post_button('buyer/quotations/' . $q['id'] . '/reject', 'Reject', 'btn btn-sm btn-outline-danger', 'Reject this quotation?') ?>
                        <?php if ($canOrder): ?><button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#acc<?= $q['id'] ?>">Accept offer</button><?php endif ?>
                    </div>
                    <form class="collapse mt-3" id="neg<?= $q['id'] ?>" method="post" action="<?= site_url('buyer/quotations/' . $q['id'] . '/message') ?>"><?= csrf_field() ?>
                        <div class="row g-2"><div class="col-md-8"><label class="form-label" for="m<?= $q['id'] ?>">Message to supplier</label><textarea class="form-control form-control-sm" id="m<?= $q['id'] ?>" name="message" rows="2" required></textarea></div><div class="col-md-4"><label class="form-label" for="pt<?= $q['id'] ?>">Counter-offer total (optional)</label><input class="form-control form-control-sm" id="pt<?= $q['id'] ?>" name="proposed_total" inputmode="decimal"></div></div>
                        <button class="btn btn-sm btn-primary mt-2" type="submit">Send</button></form>
                    <?php if ($canOrder): ?>
                    <form class="collapse mt-3 border-top pt-3" id="acc<?= $q['id'] ?>" method="post" action="<?= site_url('buyer/quotations/' . $q['id'] . '/accept') ?>"><?= csrf_field() ?>
                        <p class="small">Accepting creates a purchase order on these terms, reserves listed stock and issues a proforma invoice. Other offers will be declined.</p>
                        <?= textarea_field('delivery_address', 'Delivery address', $company['address_line1'] ? trim($company['address_line1'] . ', ' . $company['city']) : '', ['required' => true, 'rows' => 2]) ?>
                        <?= field('buyer_po_reference', 'Your PO reference (optional)', null) ?>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="accept_terms" value="1" id="at<?= $q['id'] ?>" required><label class="form-check-label small" for="at<?= $q['id'] ?>">I accept the supplier's quotation terms and BearingCave's order terms.</label></div>
                        <button class="btn btn-primary btn-sm" type="submit">Confirm &amp; create order</button></form>
                    <?php endif ?>
                    <?php endif ?>
                </div>
            </div>
            <?php endforeach ?>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

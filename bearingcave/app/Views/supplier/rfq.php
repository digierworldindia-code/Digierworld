<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $open = in_array($rfq['status'], ['distributed', 'evaluation'], true) && strtotime($rfq['deadline_at']) > time(); $revisable = ! $quote || in_array($quote['status'], ['submitted', 'shortlisted'], true); $canAct = service('companyContext')->can('rfq.manage'); ?>
<?= view('components/page_header', ['title' => $rfq['title'], 'subtitle' => $rfq['rfq_number'] . ' · ' . $buyerLabel, 'breadcrumbs' => ['RFQ leads' => 'supplier/rfqs', $rfq['rfq_number'] => null]]) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <?php if ($quote): ?><div class="alert alert-info small">You submitted <strong><?= esc($quote['quotation_number']) ?></strong> (revision <?= (int) $quote['revision'] ?>) — status <?= status_badge($quote['status']) ?>. <?= $open && $revisable ? 'You can revise it until the deadline; the buyer sees only your latest revision.' : '' ?></div><?php endif ?>
        <?php if ($match['status'] === 'declined'): ?><div class="alert alert-light border small">You declined this RFQ<?= $match['decline_reason'] ? ': ' . esc($match['decline_reason']) : '' ?>.</div><?php endif ?>
        <form method="post" action="<?= site_url('supplier/rfqs/' . $rfq['id'] . '/quote') ?>" novalidate>
            <?= csrf_field() ?>
            <div class="bc-card mb-4"><div class="bc-card-header"><h2>Items &amp; your prices</h2><span class="small text-muted">Leave a price empty to skip an item</span></div>
                <div class="table-responsive"><table class="table table-bc table-stack mb-0">
                    <thead><tr><th>Requested</th><th style="min-width:110px">Qty</th><th style="min-width:130px">Unit price (<?= esc($rfq['currency']) ?>)</th><th style="min-width:90px">Lead days</th><th style="min-width:180px">Your listing / note</th></tr></thead>
                    <tbody><?php foreach ($items as $it): $qi = $quoteItems[$it['id']] ?? null; ?><tr>
                        <td data-label="Requested"><span class="part-no fw-semibold"><?= esc($it['part_number'] ?? '') ?></span> <?= esc($it['description']) ?><div class="small text-muted">Qty <?= number_format((int) $it['quantity']) ?> <?= esc($it['unit']) ?><?= $it['brand_text'] ? ' · ' . esc($it['brand_text']) : '' ?><?= $it['specifications'] ? ' · ' . esc($it['specifications']) : '' ?></div></td>
                        <td data-label="Qty"><input class="form-control form-control-sm" type="number" min="1" name="lines[<?= $it['id'] ?>][quantity]" value="<?= esc((string) ($qi['quantity'] ?? $it['quantity']), 'attr') ?>" aria-label="Quantity"<?= $open ? '' : ' disabled' ?>></td>
                        <td data-label="Unit price"><input class="form-control form-control-sm" inputmode="decimal" name="lines[<?= $it['id'] ?>][unit_price]" value="<?= esc((string) ($qi['unit_price'] ?? ''), 'attr') ?>" aria-label="Unit price"<?= $open ? '' : ' disabled' ?>></td>
                        <td data-label="Lead days"><input class="form-control form-control-sm" type="number" min="0" name="lines[<?= $it['id'] ?>][lead_time_days]" value="<?= esc((string) ($qi['lead_time_days'] ?? ''), 'attr') ?>" aria-label="Lead time days"<?= $open ? '' : ' disabled' ?>></td>
                        <td data-label="Listing / note"><select class="form-select form-select-sm mb-1" name="lines[<?= $it['id'] ?>][product_id]" aria-label="Your listing"<?= $open ? '' : ' disabled' ?>><option value="">— Not from a listing —</option><?php foreach ($products as $pr): ?><option value="<?= $pr['id'] ?>"<?= (int) ($qi['product_id'] ?? 0) === (int) $pr['id'] || (! $qi && normalize_part($pr['part_number']) === ($it['part_number_norm'] ?? '-')) ? ' selected' : '' ?>><?= esc($pr['part_number'] . ' · ' . $pr['sku']) ?></option><?php endforeach ?></select>
                            <input class="form-control form-control-sm" name="lines[<?= $it['id'] ?>][notes]" value="<?= esc($qi['notes'] ?? '', 'attr') ?>" placeholder="Note" aria-label="Note"<?= $open ? '' : ' disabled' ?>></td>
                    </tr><?php endforeach ?></tbody>
                </table></div>
            </div>
            <?php if ($open && $revisable && $canBid && $canAct): ?>
            <div class="bc-card mb-4"><div class="bc-card-header"><h2>Commercial terms</h2></div><div class="bc-card-body"><div class="row g-3">
                <div class="col-md-3"><?= select_field('currency', 'Currency', currency_options(), $quote['currency'] ?? $rfq['currency'], ['required' => true, 'class' => '']) ?></div>
                <div class="col-md-3"><?= field('valid_until', 'Offer valid until', $quote['valid_until'] ?? date('Y-m-d', strtotime('+30 days')), ['type' => 'date', 'required' => true, 'class' => '']) ?></div>
                <div class="col-md-3"><?= select_field('delivery_terms', 'Incoterm', ['EXW' => 'EXW', 'FCA' => 'FCA', 'FOB' => 'FOB', 'CIF' => 'CIF', 'DAP' => 'DAP', 'DDP' => 'DDP'], $quote['delivery_terms'] ?? $rfq['delivery_terms'], ['class' => '', 'placeholder' => 'Select']) ?></div>
                <div class="col-md-3"><?= field('lead_time_days', 'Overall lead time (days)', $quote['lead_time_days'] ?? '', ['type' => 'number', 'class' => '']) ?></div>
                <div class="col-md-6"><?= field('payment_terms', 'Payment terms', $quote['payment_terms'] ?? '', ['class' => '', 'placeholder' => 'e.g. 100% advance against proforma']) ?></div>
                <div class="col-md-6"><?= select_field('logistics_option', 'Logistics', array_filter(['platform_managed' => 'BearingCave-managed', 'confidential' => 'Confidential (via BearingCave)', 'supplier_direct' => $direct ? 'I ship directly' : null, 'buyer_arranged' => 'Buyer collects']), $quote['logistics_option'] ?? 'platform_managed', ['class' => '']) ?></div>
                <div class="col-md-12"><?= check_field('inspection_offered', 'Goods are available for pre-dispatch inspection', (bool) ($quote['inspection_offered'] ?? 1)) ?></div>
                <div class="col-12"><?= textarea_field('notes', 'Notes to buyer', $quote['notes'] ?? '', ['rows' => 2, 'class' => '', 'help' => 'Do not include company names or contact details — offers are relayed confidentially.']) ?></div>
            </div>
            <button class="btn btn-primary mt-2" type="submit"><?= $quote ? 'Submit revised quotation' : 'Submit quotation' ?></button>
            </div></div>
            <?php endif ?>
        </form>
    </div>
    <div class="col-xl-4">
        <div class="bc-card mb-3"><div class="bc-card-body">
            <dl class="dl-grid small mb-0">
                <dt>Buyer</dt><dd><?= esc($buyerLabel) ?> <?= $buyerVerified ? '<i class="bi bi-patch-check-fill text-primary" title="Verified buyer" aria-label="Verified buyer"></i>' : '' ?></dd>
                <dt>Deliver to</dt><dd><?= esc(country_name($rfq['destination_country'])) ?></dd>
                <dt>Incoterm</dt><dd><?= esc($rfq['delivery_terms'] ?? 'Not specified') ?></dd>
                <dt>Required by</dt><dd><?= fdate($rfq['required_by']) ?></dd>
                <dt>Deadline</dt><dd><?= fdt($rfq['deadline_at']) ?></dd>
                <dt>Why you</dt><dd><?= esc(implode('; ', (array) json_decode((string) $match['match_reasons'], true))) ?></dd>
            </dl>
            <?php if ($rfq['delivery_requirements']): ?><p class="small mt-2 mb-0"><strong>Delivery requirements:</strong> <?= esc($rfq['delivery_requirements']) ?></p><?php endif ?>
            <?php if ($rfq['comments']): ?><p class="small mt-2 mb-0"><strong>Comments:</strong> <?= esc($rfq['comments']) ?></p><?php endif ?>
            <?php foreach ($documents as $d): ?><div class="small mt-2"><a href="<?= site_url('documents/' . $d['uuid']) ?>"><i class="bi bi-paperclip" aria-hidden="true"></i> <?= esc($d['title']) ?></a></div><?php endforeach ?>
        </div></div>
        <?php if ($open && ! $quote && in_array($match['status'], ['invited', 'viewed'], true) && $canAct): ?>
        <div class="bc-card mb-3"><div class="bc-card-body">
            <form method="post" action="<?= site_url('supplier/rfqs/' . $rfq['id'] . '/decline') ?>" data-confirm="Decline this RFQ?"><?= csrf_field() ?><label class="form-label" for="dr">Can't supply?</label><input id="dr" class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (optional)"><button class="btn btn-sm btn-outline-danger" type="submit">Decline RFQ</button></form>
        </div></div>
        <?php endif ?>
        <?php if ($quote): ?>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>Negotiation</h2></div><div class="bc-card-body d-grid gap-2">
            <?php if (! $messages): ?><p class="small text-muted mb-0">No messages.</p><?php endif ?>
            <?php foreach ($messages as $m): ?><div class="chat-msg small <?= $m['sender_side'] === 'supplier' ? 'mine' : '' ?>"><strong><?= $m['sender_side'] === 'supplier' ? 'You' : 'Buyer' ?></strong> · <?= fdt($m['created_at']) ?><?= $m['proposed_total'] !== null ? '<br>Counter-offer: ' . money($m['proposed_total'], $quote['currency']) : '' ?><div><?= esc($m['message']) ?></div></div><?php endforeach ?>
            <?php if ($revisable && $canAct): ?><form method="post" action="<?= site_url('supplier/rfqs/' . $rfq['id'] . '/message') ?>"><?= csrf_field() ?><label class="visually-hidden" for="sm">Message</label><textarea id="sm" class="form-control form-control-sm mb-2" name="message" rows="2" required></textarea><button class="btn btn-sm btn-light" type="submit">Send message</button></form><?php endif ?>
        </div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Revision history</h2></div><ul class="list-group list-group-flush small"><?php foreach ($history as $h): ?><li class="list-group-item d-flex justify-content-between"><span>Rev <?= (int) $h['revision'] ?> · <?= fdt($h['submitted_at']) ?></span><span><?= money($h['total_amount'], $h['currency']) ?> <?= status_badge($h['status']) ?></span></li><?php endforeach ?></ul></div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

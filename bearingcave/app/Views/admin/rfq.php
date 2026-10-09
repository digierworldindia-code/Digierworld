<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $active = in_array($rfq['status'], ['submitted', 'under_review', 'distributed', 'evaluation'], true); ?>
<?= view('components/page_header', ['title' => $rfq['title'], 'subtitle' => $rfq['rfq_number'] . ' · ' . $buyer['legal_name'] . ' (' . country_name($buyer['country_code']) . ')', 'breadcrumbs' => ['RFQs' => 'admin/rfqs', $rfq['rfq_number'] => null]]) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Requested items</h2><?= status_badge($rfq['status'], $statuses[$rfq['status']]) ?></div><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Part number</th><th>Description</th><th>Brand / category</th><th class="text-end">Qty</th><th class="text-end">Target</th></tr></thead><tbody>
            <?php foreach ($items as $it): ?><tr><td class="part-no"><?= esc($it['part_number'] ?? '—') ?></td><td><?= esc($it['description']) ?><?= $it['specifications'] ? '<div class="text-muted">' . esc($it['specifications']) . '</div>' : '' ?></td><td><?= esc(trim(($it['brand_id'] ? (brand_options()[$it['brand_id']] ?? '') : ($it['brand_text'] ?? '')) . ' / ' . ($it['category_id'] ? (category_options()[$it['category_id']] ?? '') : ''), ' /')) ?: '—' ?></td><td class="text-end"><?= number_format((int) $it['quantity']) ?> <?= esc($it['unit']) ?></td><td class="text-end"><?= $it['target_unit_price'] !== null ? money($it['target_unit_price'], $rfq['currency'], 2) : '—' ?></td></tr><?php endforeach ?>
        </tbody></table></div></div>

        <form method="post" action="<?= site_url('admin/rfqs/' . $rfq['id'] . '/distribute') ?>">
        <?= csrf_field() ?>
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Supplier matches</h2><?php if ($canManage && $active): ?><?= '' ?><?php endif ?></div>
            <?php if (! $matches): ?><?= empty_state('bi-diagram-3', 'No matching suppliers found', 'No verified supplier with premium RFQ access lists this part, brand or category, or holds an Approved Vendor List approval for it. You can invite a supplier manually below.') ?><?php else: ?>
            <div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><?php if ($canManage && $active): ?><th><span class="visually-hidden">Select</span></th><?php endif ?><th>Supplier</th><th class="text-end">Score</th><th>Why matched</th><th>Perf. / risk</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($matches as $m): ?><tr>
                <?php if ($canManage && $active): ?><td><?php if ($m['status'] === 'suggested' && $m['eligible']): ?><input class="form-check-input" type="checkbox" name="suppliers[]" value="<?= (int) $m['supplier_company_id'] ?>" aria-label="Invite <?= esc($m['legal_name'], 'attr') ?>" checked><?php endif ?></td><?php endif ?>
                <td data-label="Supplier"><a href="<?= site_url('admin/companies/' . $m['supplier_company_id']) ?>"><?= esc($m['legal_name']) ?></a> <?= sample_badge($m) ?><div class="small text-muted"><?= esc($m['public_alias']) ?> · <?= esc(country_name($m['country_code'])) ?><?= $m['eligible'] ? '' : ' · <span class="text-danger">not currently eligible</span>' ?></div></td>
                <td data-label="Score" class="text-end fw-semibold"><?= esc($m['match_score']) ?></td>
                <td data-label="Why" class="small"><?= esc(implode('; ', (array) json_decode((string) $m['match_reasons'], true))) ?></td>
                <td data-label="Perf." class="small"><?= $m['performance_score'] !== null ? esc($m['performance_score']) : '—' ?> / <?= $m['risk_level'] ? status_badge($m['risk_level']) : '—' ?></td>
                <td data-label="Status"><?= status_badge($m['status']) ?><?= $m['decline_reason'] ? '<div class="small text-muted">' . esc($m['decline_reason']) . '</div>' : '' ?></td>
            </tr><?php endforeach ?></tbody></table></div><?php endif ?>
            <?php if ($canManage && $active): ?>
            <div class="bc-card-body border-top d-flex flex-wrap gap-2 align-items-end">
                <div style="min-width:260px"><?= select_field('extra_supplier', 'Also invite (manual)', $eligibleSuppliers, null, ['placeholder' => '— none —', 'class' => '']) ?></div>
                <button class="btn btn-primary" type="submit" data-confirm="Send this RFQ to the selected suppliers? Buyer identity stays confidential.">Distribute to selected</button>
            </div>
            <?php endif ?>
        </div>
        </form>

        <div class="bc-card"><div class="bc-card-header"><h2>Quotations (confidential)</h2></div>
            <?php if (! $quotes): ?><?= empty_state('bi-hourglass', 'No quotations yet') ?><?php else: ?>
            <div class="table-responsive"><table class="table table-bc mb-0"><thead><tr><th>Supplier</th><th>Quotation</th><th class="text-end">Total</th><th>Lead time</th><th>Valid</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($quotes as $q): ?><tr><td><?= esc($q['supplier']['legal_name']) ?></td><td class="part-no small"><?= esc($q['quotation_number']) ?> r<?= (int) $q['revision'] ?></td><td class="text-end"><?= money($q['total_amount'], $q['currency']) ?></td><td><?= $q['lead_time_days'] !== null ? (int) $q['lead_time_days'] . ' d' : '—' ?></td><td class="small"><?= fdate($q['valid_until']) ?></td><td><?= status_badge($q['status']) ?></td></tr><?php endforeach ?>
            </tbody></table></div><?php endif ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="bc-card mb-3"><div class="bc-card-body"><dl class="dl-grid small mb-0">
            <dt>Buyer</dt><dd><a href="<?= site_url('admin/companies/' . $buyer['id']) ?>"><?= esc($buyer['legal_name']) ?></a> <?= verified_badge($buyer, false) ?></dd><dt>Destination</dt><dd><?= esc(country_name($rfq['destination_country'])) ?></dd><dt>Incoterm</dt><dd><?= esc($rfq['delivery_terms'] ?? '—') ?></dd>
            <dt>Required by</dt><dd><?= fdate($rfq['required_by']) ?></dd><dt>Deadline</dt><dd><?= fdt($rfq['deadline_at']) ?></dd><dt>Inspection</dt><dd><?= $rfq['inspection_required'] ? 'Wanted' : '—' ?></dd><dt>Logistics</dt><dd><?= $rfq['logistics_required'] ? 'Wanted' : '—' ?></dd>
            <?php if ($rfq['comments']): ?><dt>Comments</dt><dd><?= esc($rfq['comments']) ?></dd><?php endif ?>
        </dl><?php foreach ($documents as $d): ?><div class="small mt-2"><a href="<?= site_url('documents/' . $d['uuid']) ?>" target="_blank"><i class="bi bi-paperclip" aria-hidden="true"></i> <?= esc($d['title']) ?></a></div><?php endforeach ?></div></div>
        <?php if ($canManage): ?>
            <?php if (in_array($rfq['status'], ['submitted', 'under_review'], true)): ?>
            <div class="bc-card mb-3"><div class="bc-card-body"><h2 class="h6">Review</h2><form method="post" action="<?= site_url('admin/rfqs/' . $rfq['id'] . '/review') ?>"><?= csrf_field() ?><label class="form-label" for="rv">Notes (sent to buyer if rejected)</label><textarea id="rv" class="form-control form-control-sm mb-2" name="notes" rows="2"><?= esc($rfq['admin_notes'] ?? '') ?></textarea><div class="d-flex gap-2"><button class="btn btn-sm btn-light" type="submit" name="decision" value="accept">Mark reviewed</button><button class="btn btn-sm btn-outline-danger" type="submit" name="decision" value="reject">Reject RFQ</button></div></form></div></div>
            <?php endif ?>
            <?php if ($active): ?>
            <div class="bc-card mb-3"><div class="bc-card-body"><h2 class="h6">Matching</h2><p class="small text-muted">Re-run after suppliers update their inventory or approvals.</p><?= post_button('admin/rfqs/' . $rfq['id'] . '/match', 'Re-run matching', 'btn btn-sm btn-light') ?></div></div>
            <div class="bc-card"><div class="bc-card-body"><h2 class="h6">Deadline</h2><form method="post" action="<?= site_url('admin/rfqs/' . $rfq['id'] . '/deadline') ?>" class="d-flex gap-1"><?= csrf_field() ?><label class="visually-hidden" for="dl">New deadline</label><input id="dl" class="form-control form-control-sm" type="datetime-local" name="deadline_at" value="<?= date('Y-m-d\TH:i', strtotime($rfq['deadline_at'])) ?>"><button class="btn btn-sm btn-light" type="submit">Update</button></form></div></div>
            <?php endif ?>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

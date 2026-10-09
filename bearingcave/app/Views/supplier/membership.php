<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $verifiedCompany = $company['verification_status'] === 'verified'; ?>
<?= view('components/page_header', ['title' => 'Membership', 'subtitle' => 'Compliance approval and membership payment are separate: you need both for the Verified Supplier badge.']) ?>
<div class="row g-4 mb-4">
    <?php foreach ($plans as $plan): $e = $ents[$plan['id']] ?? []; $isCurrent = $current && (int) $current['id'] === (int) $plan['id']; ?>
    <div class="col-lg-6">
        <div class="plan-card<?= $plan['requires_verification'] ? ' featured' : '' ?>">
            <div class="d-flex justify-content-between"><h2 class="h5"><?= esc($plan['name']) ?></h2><?= $isCurrent ? status_badge('active', 'Current plan') : '' ?></div>
            <div class="price"><?= (float) $plan['annual_fee'] > 0 ? money($plan['annual_fee'], $plan['currency'], 0) . '<span class="fs-6 text-muted fw-normal"> / year + tax</span>' : 'Free' ?></div>
            <?php if ($plan['approval_status'] !== 'approved'): ?><div class="mb-2"><?= pending_badge('Plan terms pending client approval') ?></div><?php endif ?>
            <ul class="mt-3">
                <?php foreach ($features as $key => [$label]): if ($key === 'max_active_products') { continue; } $on = ! empty($e[$key]['is_enabled']); ?>
                <li><i class="bi <?= $on ? 'bi-check2' : 'bi-dash' ?>" aria-hidden="true"></i><span class="<?= $on ? '' : 'text-muted' ?>"><?= esc(preg_replace('/ \(limit.*\)$/', '', $label)) ?></span></li>
                <?php endforeach ?>
            </ul>
            <?php if ($plan['requires_verification'] && ! $isCurrent): ?>
                <div class="mt-3">
                <?php if (! $verifiedCompany): ?>
                    <a class="btn btn-primary w-100" href="<?= site_url('supplier/verification') ?>">Start verification</a>
                    <p class="small text-muted mt-2 mb-0">After management approval an annual invoice is issued; paying it activates this plan.</p>
                <?php elseif ($pending): ?>
                    <a class="btn btn-warning w-100" href="<?= site_url('supplier/billing/invoices/' . $pending['invoice_id']) ?>">Pay membership invoice</a>
                <?php else: ?>
                    <?= post_button('supplier/membership/subscribe', 'Activate Verified membership', 'btn btn-primary w-100') ?>
                <?php endif ?>
                </div>
            <?php elseif ($isCurrent && $active): ?>
                <p class="small mt-3 mb-2">Valid <?= fdate($active['starts_on']) ?> – <?= fdate($active['ends_on']) ?></p>
                <?php if (! $pending): ?><?= post_button('supplier/membership/renew', 'Renew for another year', 'btn btn-outline-primary btn-sm') ?><?php else: ?><a class="btn btn-sm btn-warning" href="<?= site_url('supplier/billing') ?>">Renewal invoice awaiting payment</a><?php endif ?>
            <?php endif ?>
        </div>
    </div>
    <?php endforeach ?>
</div>
<div class="bc-card"><div class="bc-card-header"><h2>Membership history</h2></div>
<?php if (! $history): ?><?= empty_state('bi-calendar', 'No subscriptions yet') ?><?php else: ?>
<div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Plan</th><th>Starts</th><th>Ends</th><th>Status</th><th>Invoice</th></tr></thead><tbody>
<?php foreach ($history as $h): ?><tr><td data-label="Plan"><?= esc($h['plan_name']) ?></td><td data-label="Starts"><?= fdate($h['starts_on']) ?></td><td data-label="Ends"><?= fdate($h['ends_on']) ?></td><td data-label="Status"><?= status_badge($h['status']) ?></td><td data-label="Invoice"><?= $h['invoice_id'] ? '<a href="' . site_url('supplier/billing/invoices/' . $h['invoice_id']) . '">View</a>' : '—' ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
</div>
<?= $this->endSection() ?>

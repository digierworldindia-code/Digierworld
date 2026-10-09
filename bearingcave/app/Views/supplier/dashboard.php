<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $done = count(array_filter($onboarding, static fn ($o) => $o[1])); $totalAge = max(1, (int) $ageing['lt6'] + (int) $ageing['m612'] + (int) $ageing['gt12']); ?>
<?= view('components/page_header', ['title' => 'Supplier dashboard', 'subtitle' => $company['legal_name'] . ' · ' . ($plan['name'] ?? 'No plan'), 'actions' => '<a class="btn btn-primary btn-sm" href="' . site_url('supplier/products/new') . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add inventory</a>']) ?>
<?php if ($company['status'] !== 'active'): ?><div class="alert alert-danger">Your account is suspended<?= $company['suspension_reason'] ? ': ' . esc($company['suspension_reason']) : '' ?>. Your listings are hidden. Contact BearingCave support.</div><?php endif ?>
<?php if ($pending && ! $verified): ?><div class="alert alert-warning d-flex justify-content-between flex-wrap gap-2 align-items-center"><span><i class="bi bi-receipt me-1" aria-hidden="true"></i>Your company is verified. Pay the membership invoice to activate the Verified Supplier badge and premium features.</span><a class="btn btn-sm btn-warning" href="<?= site_url('supplier/billing') ?>">View invoice</a></div><?php endif ?>
<?php if ($subscription && $subscription['ends_on'] <= date('Y-m-d', strtotime('+30 days'))): ?><div class="alert alert-info">Your membership ends on <?= fdate($subscription['ends_on']) ?>. <a href="<?= site_url('supplier/membership') ?>">Renew now</a> to avoid interruption.</div><?php endif ?>
<?php if ($done < count($onboarding)): ?>
<div class="bc-card mb-4"><div class="bc-card-body">
    <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0">Get set up (<?= $done ?>/<?= count($onboarding) ?>)</h2><div class="progress-steps" style="width:160px"><?php foreach ($onboarding as $o): ?><span class="<?= $o[1] ? 'done' : '' ?>"></span><?php endforeach ?></div></div>
    <div class="row g-2"><?php foreach ($onboarding as [$label, $ok, $url]): ?><div class="col-md-6 col-xl-3"><a class="d-flex align-items-center gap-2 p-2 border rounded text-decoration-none <?= $ok ? 'text-muted' : '' ?>" href="<?= site_url($url) ?>"><i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : 'bi-circle' ?>" aria-hidden="true"></i><span class="small"><?= esc($label) ?></span></a></div><?php endforeach ?></div>
</div></div>
<?php endif ?>
<div class="row g-3 mb-4">
    <?php foreach ([['Published listings', $kpi['published'], 'bi-boxes', 'supplier/products?status=published'], ['Awaiting review', $kpi['review'], 'bi-hourglass-split', 'supplier/products?status=pending_review'], ['Units in stock', $kpi['units'], 'bi-stack', 'supplier/products'], ['Open enquiries', $kpi['enquiries'], 'bi-chat-left-text', 'supplier/enquiries'], ['RFQs to answer', $kpi['rfqs'], 'bi-megaphone', 'supplier/rfqs'], ['Orders to confirm', $kpi['to_confirm'], 'bi-box-seam', 'supplier/orders?status=pending_supplier_confirmation']] as [$l, $v, $i, $u]): ?>
    <div class="col-6 col-md-4 col-xl-2"><a class="bc-card bc-kpi d-block text-decoration-none h-100" href="<?= site_url($u) ?>"><span class="kpi-icon mb-2"><i class="bi <?= $i ?>" aria-hidden="true"></i></span><div class="kpi-value"><?= number_format($v) ?></div><div class="kpi-label"><?= esc($l) ?></div></a></div>
    <?php endforeach ?>
</div>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Stock ageing</h2><a class="small" href="<?= site_url('supplier/products?age=gt_12m') ?>">View aged stock</a></div><div class="bc-card-body">
            <?php foreach ([['lt6', 'Less than 6 months', 'bg-success'], ['m612', '6–12 months', 'bg-warning'], ['gt12', 'More than 12 months', 'bg-danger']] as [$k, $l, $cls]): $v = (int) $ageing[$k]; ?>
                <div class="mb-2"><div class="d-flex justify-content-between small"><span><?= $l ?></span><span><?= number_format($v) ?> units</span></div><div class="progress" role="progressbar" aria-label="<?= $l ?>" aria-valuenow="<?= round(100 * $v / $totalAge) ?>" aria-valuemin="0" aria-valuemax="100" style="height:8px"><div class="progress-bar <?= $cls ?>" style="width:<?= round(100 * $v / $totalAge) ?>%"></div></div></div>
            <?php endforeach ?>
        </div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Recent orders</h2><a class="small" href="<?= site_url('supplier/orders') ?>">All orders</a></div>
            <?php if (! $orders): ?><?= empty_state('bi-box-seam', 'No orders yet', 'Orders appear when buyers order your listings or accept your quotations.') ?><?php else: ?>
            <div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Order</th><th class="text-end">Total</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($orders as $o): ?><tr><td data-label="Order"><a class="part-no" href="<?= site_url('supplier/orders/' . $o['id']) ?>"><?= esc($o['order_number']) ?></a></td><td data-label="Total" class="text-end"><?= money($o['total'], $o['currency']) ?></td><td data-label="Status"><?= status_badge($o['status'], \App\Services\OrderService::STATUSES[$o['status']] ?? null) ?></td></tr><?php endforeach ?>
            </tbody></table></div><?php endif ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="bc-card"><div class="bc-card-body">
            <h2 class="h6">Membership</h2>
            <p class="mb-1"><?= $verified ? verified_badge($company) : status_badge($company['verification_status']) ?></p>
            <dl class="dl-grid small"><dt>Plan</dt><dd><?= esc($plan['name'] ?? '—') ?></dd><?php if ($subscription): ?><dt>Valid until</dt><dd><?= fdate($subscription['ends_on']) ?></dd><?php endif ?></dl>
            <?php if (! $verified): ?><p class="small text-muted">Verified Suppliers get priority ranking, premium RFQ leads, bidding, per-piece pricing, country controls, bulk upload and analytics.</p><?php endif ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= site_url('supplier/membership') ?>">Manage membership</a>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>

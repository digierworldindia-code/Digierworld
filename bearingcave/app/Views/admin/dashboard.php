<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Platform overview', 'subtitle' => 'Live figures from the database' . ($kpi['sample_companies'] ? ' — includes ' . (int) $kpi['sample_companies'] . ' companies flagged as SAMPLE demo data' : '') . '.']) ?>
<?php if ($pendingDecisions): ?><div class="alert alert-warning d-flex justify-content-between flex-wrap gap-2 align-items-center"><span><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i><?= (int) $pendingDecisions ?> business-policy settings are still <strong>pending client approval</strong> (fees, tax, logistics base, RFQ distribution…).</span><?php if (auth()->user()->can('settings.manage')): ?><a class="btn btn-sm btn-warning" href="<?= site_url('admin/settings') ?>">Review settings</a><?php endif ?></div><?php endif ?>
<?php
$revenue = implode(' · ', array_map(static fn ($r) => money($r['total'], $r['currency'], 0), $kpi['membership_revenue'])) ?: '—';
$tiles = [
    ['Registered suppliers', $kpi['suppliers'], 'bi-building', 'admin/companies?type=supplier'], ['Verified suppliers', $kpi['verified_suppliers'], 'bi-patch-check', 'admin/companies?type=supplier&verification_status=verified'],
    ['Registered buyers', $kpi['buyers'], 'bi-bag', 'admin/companies?type=buyer'], ['Verified buyers', $kpi['verified_buyers'], 'bi-bag-check', 'admin/companies?type=buyer&verification_status=verified'],
    ['Active products', $kpi['active_products'], 'bi-boxes', 'admin/products?status=published'], ['Total inventory (units)', $kpi['total_inventory'], 'bi-stack', 'admin/reports/inventory_by_category'],
    ['Pending RFQs', $kpi['pending_rfqs'], 'bi-megaphone', 'admin/rfqs?status=open'], ['Active quotations', $kpi['active_quotations'], 'bi-file-earmark-text', 'admin/quotations'],
    ['Orders', $kpi['orders'], 'bi-box-seam', 'admin/orders'], ['Open inspection requests', $kpi['inspection_requests'], 'bi-clipboard-check', 'admin/inspections'],
    ['Open logistics requests', $kpi['logistics_requests'], 'bi-truck', 'admin/logistics'], ['Pending disputes', $kpi['pending_disputes'], 'bi-shield-exclamation', 'admin/disputes'],
    ['Supplier approval queue', $kpi['approval_queue'], 'bi-hourglass-split', 'admin/verifications'], ['Listings awaiting review', $kpi['products_pending'], 'bi-eye', 'admin/products'],
];
?>
<div class="row g-3 mb-4">
    <?php foreach ($tiles as [$l, $v, $i, $u]): ?>
    <div class="col-6 col-md-4 col-xl-3"><a class="bc-card bc-kpi d-flex gap-3 align-items-center text-decoration-none h-100" href="<?= site_url($u) ?>"><span class="kpi-icon"><i class="bi <?= $i ?>" aria-hidden="true"></i></span><span><span class="kpi-value d-block"><?= number_format((float) $v) ?></span><span class="kpi-label"><?= esc($l) ?></span></span></a></div>
    <?php endforeach ?>
    <div class="col-12 col-md-8 col-xl-6"><a class="bc-card bc-kpi d-flex gap-3 align-items-center text-decoration-none h-100" href="<?= site_url('admin/reports/membership_revenue') ?>"><span class="kpi-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span><span><span class="kpi-value d-block fs-5"><?= $revenue ?></span><span class="kpi-label">Membership revenue (paid invoices, all time)</span></span></a></div>
</div>
<div class="row g-4">
    <div class="col-xl-6">
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Verification queue</h2><a class="small" href="<?= site_url('admin/verifications') ?>">Open queue</a></div>
        <?php if (! $queue): ?><?= empty_state('bi-check2-all', 'Queue is empty') ?><?php else: ?><ul class="list-group list-group-flush small"><?php foreach ($queue as $a): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= site_url('admin/verifications/' . $a['id']) ?>"><?= esc($a['legal_name']) ?></a><span><?= sample_badge($a) ?> <?= esc(ucfirst($a['company_type'])) ?> · <?= status_badge($a['status']) ?></span></li><?php endforeach ?></ul><?php endif ?></div>
        <div class="bc-card"><div class="bc-card-header"><h2>RFQs awaiting review</h2><a class="small" href="<?= site_url('admin/rfqs?status=open') ?>">All</a></div>
        <?php if (! $rfqs): ?><?= empty_state('bi-inbox', 'No RFQs waiting') ?><?php else: ?><ul class="list-group list-group-flush small"><?php foreach ($rfqs as $r): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= site_url('admin/rfqs/' . $r['id']) ?>"><span class="part-no"><?= esc($r['rfq_number']) ?></span> <?= esc($r['title']) ?></a><?= status_badge($r['status']) ?></li><?php endforeach ?></ul><?php endif ?></div>
    </div>
    <div class="col-xl-6">
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Listings awaiting review</h2><a class="small" href="<?= site_url('admin/products') ?>">All</a></div>
        <?php if (! $products): ?><?= empty_state('bi-check2-all', 'Nothing to review') ?><?php else: ?><ul class="list-group list-group-flush small"><?php foreach ($products as $p): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= site_url('admin/products/' . $p['id']) ?>"><span class="part-no"><?= esc($p['part_number']) ?></span> <?= esc($p['name']) ?></a><span class="text-muted"><?= fdate($p['updated_at']) ?></span></li><?php endforeach ?></ul><?php endif ?></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Recent security events</h2><a class="small" href="<?= site_url('admin/audit?severity=security') ?>">Audit log</a></div>
        <?php if (! $security): ?><?= empty_state('bi-shield-check', 'No security events') ?><?php else: ?><ul class="list-group list-group-flush small"><?php foreach ($security as $s): ?><li class="list-group-item"><span class="fw-semibold"><?= esc($s['event']) ?></span> — <?= esc($s['description'] ?? '') ?><div class="text-muted"><?= fdt($s['created_at']) ?> · <?= esc($s['ip_address'] ?? '') ?></div></li><?php endforeach ?></ul><?php endif ?></div>
    </div>
</div>
<?= $this->endSection() ?>

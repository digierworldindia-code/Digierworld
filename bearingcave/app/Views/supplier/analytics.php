<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Analytics & performance', 'subtitle' => 'All figures are calculated from your actual listings, enquiries, RFQs and orders.']) ?>
<div class="row g-3 mb-4">
    <?php foreach ([['Listings', $basic['listings'], 'bi-boxes'], ['Listing views', $basic['views'], 'bi-eye'], ['Enquiries', $basic['enquiries'], 'bi-chat-left-text'], ['Orders', $basic['orders'], 'bi-box-seam']] as [$l, $v, $i]): ?>
    <div class="col-6 col-lg-3"><div class="bc-card bc-kpi h-100"><span class="kpi-icon mb-2"><i class="bi <?= $i ?>" aria-hidden="true"></i></span><div class="kpi-value"><?= number_format((int) $v) ?></div><div class="kpi-label"><?= $l ?></div></div></div>
    <?php endforeach ?>
</div>
<div class="bc-card mb-4"><div class="bc-card-header"><h2>Supplier performance score</h2><?= $perf ? '<span class="small text-muted">Computed ' . fdt($perf['computed_at']) . ' · ' . fdate($perf['period_start']) . ' – ' . fdate($perf['period_end']) . '</span>' : '' ?></div><div class="bc-card-body">
<?php if (! $perf): ?><p class="text-muted small mb-0">Not calculated yet. BearingCave recalculates performance scores regularly from completed orders, shipments, RFQ responses and disputes.</p><?php else: ?>
    <div class="row g-3 text-center">
        <?php foreach (['overall_score' => ['Overall', '/100'], 'on_time_delivery_pct' => ['On-time delivery', '%'], 'quality_rejection_pct' => ['Quality rejections', '%'], 'cost_competitiveness' => ['Cost competitiveness', '/100'], 'avg_response_hours' => ['Avg. RFQ response', ' h'], 'rfq_response_rate_pct' => ['RFQ response rate', '%'], 'service_level' => ['Service level', '/100']] as $k => [$l, $u]): ?>
        <div class="col-6 col-md-3 col-xl"><div class="small text-muted"><?= $l ?></div><div class="fs-5 fw-bold text-navy"><?= $perf[$k] !== null ? esc($perf[$k]) . $u : '<span class="fs-6 text-muted">No data</span>' ?></div></div>
        <?php endforeach ?>
    </div>
<?php endif ?>
</div></div>
<?php if (! $advanced): ?>
    <div class="bc-card"><?= empty_state('bi-graph-up', 'Advanced analytics', 'Verified Suppliers see top-performing listings, monthly order trends, RFQ win rates and buyer demand insights.', '<a class="btn btn-primary btn-sm" href="' . site_url('supplier/membership') . '">View membership</a>') ?></div>
<?php else: ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Top listings</h2></div><div class="table-responsive"><table class="table table-bc mb-0"><thead><tr><th>Part</th><th class="text-end">Views</th><th class="text-end">Enquiries</th><th class="text-end">Units ordered</th></tr></thead><tbody>
            <?php foreach ($topProducts as $t): ?><tr><td><span class="part-no"><?= esc($t['part_number']) ?></span> <span class="small text-muted"><?= esc($t['name']) ?></span></td><td class="text-end"><?= number_format((int) $t['view_count']) ?></td><td class="text-end"><?= (int) $t['enquiries'] ?></td><td class="text-end"><?= number_format((int) $t['ordered']) ?></td></tr><?php endforeach ?>
        </tbody></table></div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Orders by month</h2></div><div class="table-responsive"><table class="table table-bc mb-0"><thead><tr><th>Month</th><th class="text-end">Orders</th><th class="text-end">Value</th></tr></thead><tbody>
            <?php if (! $monthly): ?><tr><td colspan="3" class="text-muted small">No orders yet.</td></tr><?php endif ?>
            <?php foreach ($monthly as $m): ?><tr><td><?= esc($m['m']) ?></td><td class="text-end"><?= (int) $m['orders'] ?></td><td class="text-end"><?= money($m['value'], $m['currency']) ?></td></tr><?php endforeach ?>
        </tbody></table></div></div>
    </div>
    <div class="col-xl-5">
        <div class="bc-card mb-4"><div class="bc-card-body"><h2 class="h6">RFQ funnel</h2><dl class="dl-grid small mb-0"><dt>Invited</dt><dd><?= (int) $rfq['invited'] ?></dd><dt>Quoted</dt><dd><?= (int) $rfq['quoted'] ?></dd><dt>Declined</dt><dd><?= (int) $rfq['declined'] ?></dd><dt>Won</dt><dd><?= (int) $won ?></dd></dl></div></div>
        <?php if ($demand): ?>
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Buyer demand for your parts (90 days)</h2></div><ul class="list-group list-group-flush small">
            <?php if (! $searches): ?><li class="list-group-item text-muted">No matching buyer searches yet.</li><?php endif ?>
            <?php foreach ($searches as $s): ?><li class="list-group-item d-flex justify-content-between"><span class="part-no"><?= esc($s['query']) ?></span><span><?= (int) $s['searches'] ?> searches</span></li><?php endforeach ?>
        </ul></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Unmet demand (no results)</h2></div><ul class="list-group list-group-flush small">
            <?php if (! $unmet): ?><li class="list-group-item text-muted">None recorded.</li><?php endif ?>
            <?php foreach ($unmet as $s): ?><li class="list-group-item d-flex justify-content-between"><span class="part-no"><?= esc($s['query']) ?></span><span><?= (int) $s['searches'] ?></span></li><?php endforeach ?>
        </ul><div class="px-3 py-2 small text-muted">Search terms are aggregated and never linked to buyer identities.</div></div>
        <?php endif ?>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>

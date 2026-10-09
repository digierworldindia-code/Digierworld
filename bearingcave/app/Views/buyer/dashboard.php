<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $verified = service('entitlements')->isVerifiedBuyer($company); $done = count(array_filter($onboarding, static fn ($o) => $o[1])); ?>
<?= view('components/page_header', ['title' => 'Welcome back', 'subtitle' => $company['legal_name'], 'actions' => '<a class="btn btn-light btn-sm" href="' . site_url('marketplace') . '"><i class="bi bi-search me-1" aria-hidden="true"></i>Search parts</a><a class="btn btn-primary btn-sm" href="' . site_url('buyer/rfqs/new') . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New RFQ</a>']) ?>
<?php if ($company['status'] !== 'active'): ?><div class="alert alert-danger">Your account is suspended<?= $company['suspension_reason'] ? ': ' . esc($company['suspension_reason']) : '' ?>. Contact support.</div><?php endif ?>
<?php if ($done < count($onboarding)): ?>
<div class="bc-card mb-4"><div class="bc-card-body">
    <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0">Get set up (<?= $done ?>/<?= count($onboarding) ?>)</h2><div class="progress-steps" style="width:160px"><?php foreach ($onboarding as $o): ?><span class="<?= $o[1] ? 'done' : '' ?>"></span><?php endforeach ?></div></div>
    <div class="row g-2"><?php foreach ($onboarding as [$label, $ok, $url]): ?><div class="col-md-6 col-xl-3"><a class="d-flex align-items-center gap-2 p-2 border rounded text-decoration-none <?= $ok ? 'text-muted' : '' ?>" href="<?= site_url($url) ?>"><i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : 'bi-circle' ?>" aria-hidden="true"></i><span class="small"><?= esc($label) ?></span></a></div><?php endforeach ?></div>
</div></div>
<?php endif ?>
<div class="row g-3 mb-4">
    <?php foreach ([['Open RFQs', $kpi['open_rfqs'], 'bi-file-earmark-text', 'buyer/rfqs'], ['Quotations to review', $kpi['quotations'], 'bi-inbox', 'buyer/rfqs'], ['Active orders', $kpi['orders'], 'bi-box-seam', 'buyer/orders'], ['Unpaid invoices', $kpi['unpaid'], 'bi-receipt', 'buyer/billing'], ['Saved listings', $kpi['saved'], 'bi-bookmark', 'buyer/saved'], ['Open disputes', $kpi['disputes'], 'bi-shield-exclamation', 'buyer/disputes']] as [$l, $v, $i, $u]): ?>
    <div class="col-6 col-md-4 col-xl-2"><a class="bc-card bc-kpi d-block text-decoration-none h-100" href="<?= site_url($u) ?>"><span class="kpi-icon mb-2"><i class="bi <?= $i ?>" aria-hidden="true"></i></span><div class="kpi-value"><?= number_format($v) ?></div><div class="kpi-label"><?= esc($l) ?></div></a></div>
    <?php endforeach ?>
</div>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Recent RFQs</h2><a class="small" href="<?= site_url('buyer/rfqs') ?>">All RFQs</a></div>
            <?php if (! $rfqs): ?><?= empty_state('bi-file-earmark-text', 'No RFQs yet', 'Tell us what you need — we match it with verified suppliers.', '<a class="btn btn-primary btn-sm" href="' . site_url('buyer/rfqs/new') . '">Create RFQ</a>') ?><?php else: ?>
            <div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>RFQ</th><th>Title</th><th>Deadline</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($rfqs as $r): ?><tr><td data-label="RFQ"><a class="part-no" href="<?= site_url('buyer/rfqs/' . $r['id']) ?>"><?= esc($r['rfq_number']) ?></a></td><td data-label="Title"><?= esc($r['title']) ?></td><td data-label="Deadline" class="small"><?= fdt($r['deadline_at']) ?></td><td data-label="Status"><?= status_badge($r['status'], \App\Services\RfqService::STATUSES[$r['status']] ?? null) ?></td></tr><?php endforeach ?>
            </tbody></table></div><?php endif ?>
        </div>
        <div class="bc-card"><div class="bc-card-header"><h2>Recent orders</h2><a class="small" href="<?= site_url('buyer/orders') ?>">All orders</a></div>
            <?php if (! $orders): ?><?= empty_state('bi-box-seam', 'No orders yet') ?><?php else: ?>
            <div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Order</th><th class="text-end">Total</th><th>Payment</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($orders as $o): ?><tr><td data-label="Order"><a class="part-no" href="<?= site_url('buyer/orders/' . $o['id']) ?>"><?= esc($o['order_number']) ?></a></td><td data-label="Total" class="text-end"><?= money($o['total'], $o['currency']) ?></td><td data-label="Payment"><?= status_badge($o['payment_status']) ?></td><td data-label="Status"><?= status_badge($o['status'], \App\Services\OrderService::STATUSES[$o['status']] ?? null) ?></td></tr><?php endforeach ?>
            </tbody></table></div><?php endif ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="bc-card mb-4"><div class="bc-card-body">
            <h2 class="h6">Buyer status</h2>
            <p class="mb-2"><?= $verified ? verified_badge($company) : status_badge($company['verification_status']) ?></p>
            <?php if (! $verified): ?><p class="small text-muted">Verify your company to get the Verified Buyer badge, become eligible for restricted inventory and unlock advanced comparison.</p><a class="btn btn-sm btn-outline-primary" href="<?= site_url('buyer/verification') ?>">Verification</a>
            <?php else: ?>
                <dl class="dl-grid small mb-0"><dt>Engagement</dt><dd><?= esc($profile['engagement_score'] ?? '0') ?>/100</dd><dt>RFQ genuineness</dt><dd><?= esc($profile['rfq_genuineness_score'] ?? '0') ?>/100</dd><dt>Transactions</dt><dd><?= esc($profile['transaction_score'] ?? '0') ?>/100</dd><dt>Restricted access</dt><dd><?= ! empty($profile['restricted_inventory_access']) ? status_badge('approved') : '<span class="text-muted">Not granted</span>' ?></dd></dl>
                <p class="small text-muted mt-2 mb-0">Scores are computed from your platform activity<?= $profile['scores_computed_at'] ? ' on ' . fdate($profile['scores_computed_at']) : ' (not yet computed)' ?>.</p>
            <?php endif ?>
        </div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Notifications</h2><a class="small" href="<?= site_url('notifications') ?>">All</a></div><div class="bc-card-body">
            <?php if (! $notifications): ?><p class="small text-muted mb-0">Nothing new.</p><?php endif ?>
            <ul class="list-unstyled mb-0 d-grid gap-2"><?php foreach ($notifications as $n): ?><li class="small"><span class="<?= $n['read_at'] ? 'text-muted' : 'fw-semibold' ?>"><?= esc($n['title']) ?></span><br><span class="text-muted"><?= fdt($n['created_at']) ?></span></li><?php endforeach ?></ul>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>

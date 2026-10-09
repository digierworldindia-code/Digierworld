<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $sup = $c['company_type'] === 'supplier'; $u = auth()->user(); ?>
<?= view('components/page_header', ['title' => $c['legal_name'], 'subtitle' => ucfirst($c['company_type']) . ' · ' . $c['public_alias'] . ' · ' . country_name($c['country_code']), 'breadcrumbs' => [($sup ? 'Suppliers' : 'Buyers') => 'admin/companies?type=' . $c['company_type'], $c['legal_name'] => null]]) ?>
<?php if ($c['is_sample']): ?><div class="alert alert-warning small"><span class="badge-sample">SAMPLE DATA</span> This is a demo record, not a real business.</div><?php endif ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="bc-card mb-4"><div class="bc-card-body"><div class="row g-3">
            <div class="col-md-6"><dl class="dl-grid small mb-0"><dt>Verification</dt><dd><?= status_badge($c['verification_status']) ?> <?= verified_badge($c) ?></dd><dt>Account</dt><dd><?= status_badge($c['status']) ?><?= $c['suspension_reason'] ? '<div class="text-muted">' . esc($c['suspension_reason']) . '</div>' : '' ?></dd><dt>Plan</dt><dd><?= esc($plan['name'] ?? '—') ?></dd><dt>Registration no.</dt><dd><?= esc($c['registration_number'] ?? '—') ?></dd><dt>Founded</dt><dd><?= esc($c['year_established'] ?? '—') ?></dd></dl></div>
            <div class="col-md-6"><dl class="dl-grid small mb-0"><dt>Address</dt><dd><?= esc(trim(implode(', ', array_filter([$c['address_line1'], $c['address_line2'], $c['city'], $c['state'], $c['postal_code']])))) ?: '—' ?></dd><dt>Phone</dt><dd><?= esc($c['phone'] ?? '—') ?></dd><dt>Email</dt><dd><?= esc($c['email'] ?? '—') ?></dd><dt>Website</dt><dd><?= esc($c['website'] ?? '—') ?></dd><dt>Public profile</dt><dd><?= $c['public_profile_enabled'] ? 'Yes' : 'No' ?> · contact <?= $c['show_contact_public'] ? 'shown' : 'hidden' ?></dd></dl></div>
        </div></div></div>
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Activity</h2></div><div class="bc-card-body d-flex gap-4 small"><span><?= (int) $counts['products'] ?> products</span><span><?= (int) $counts['orders'] ?> orders</span><span><?= (int) $counts['rfqs'] ?> RFQs</span>
            <?php if ($sup && $perf): ?><span>Performance <?= $perf['overall_score'] !== null ? esc($perf['overall_score']) . '/100' : 'n/a' ?> (<?= fdate($perf['computed_at']) ?>)</span><?php endif ?>
            <?php if (! $sup && $profile): ?><span>Engagement <?= esc($profile['engagement_score']) ?> · Genuineness <?= esc($profile['rfq_genuineness_score']) ?> · Transactions <?= esc($profile['transaction_score']) ?></span><?php endif ?>
        </div></div>
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Team</h2></div><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Last active</th></tr></thead><tbody><?php foreach ($members as $m): ?><tr><td><a href="<?= site_url('admin/users/' . $m['user_id']) ?>"><?= esc($m['email']) ?></a></td><td><?= esc($m['member_role']) ?></td><td><?= status_badge($m['status']) ?></td><td><?= fdt($m['last_active']) ?></td></tr><?php endforeach ?></tbody></table></div></div>
        <?php if ($sup): ?>
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Subscriptions</h2></div><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Plan</th><th>Period</th><th>Status</th></tr></thead><tbody><?php foreach ($subscriptions as $s): ?><tr><td><?= esc($s['plan_name']) ?></td><td><?= fdate($s['starts_on']) ?> – <?= fdate($s['ends_on']) ?></td><td><?= status_badge($s['status']) ?></td></tr><?php endforeach ?><?php if (! $subscriptions): ?><tr><td colspan="3" class="text-muted">None</td></tr><?php endif ?></tbody></table></div></div>
        <?php if ($attributes): ?><div class="bc-card mb-4"><div class="bc-card-header"><h2>Supplier master data (imported)</h2></div><div class="table-responsive" style="max-height:360px"><table class="table table-sm small mb-0"><tbody><?php foreach ($attributes as $a): ?><tr><th class="text-muted fw-normal" style="width:40%"><?= esc($a['source_heading'] ?? $a['field_key']) ?></th><td><?= esc($a['value']) ?></td></tr><?php endforeach ?></tbody></table></div></div><?php endif ?>
        <?php endif ?>
        <div class="bc-card"><div class="bc-card-header"><h2>Audit trail</h2><a class="small" href="<?= site_url('admin/audit?company_id=' . $c['id']) ?>">All</a></div><ul class="list-group list-group-flush small"><?php foreach ($audit as $a): ?><li class="list-group-item"><span class="fw-semibold"><?= esc($a['event']) ?></span> <?= esc($a['description'] ?? '') ?> <span class="text-muted">· <?= fdt($a['created_at']) ?></span></li><?php endforeach ?></ul></div>
    </div>
    <div class="col-xl-4">
        <div class="bc-card mb-3"><div class="bc-card-body">
            <h2 class="h6">Verification</h2>
            <?php if ($application): ?><p class="small mb-2">Application #<?= (int) $application['id'] ?> (cycle <?= (int) $application['cycle'] ?>) — <?= status_badge($application['status']) ?></p><a class="btn btn-sm btn-primary" href="<?= site_url('admin/verifications/' . $application['id']) ?>">Open workbench</a><?php else: ?><p class="small text-muted mb-0">No application started.</p><?php endif ?>
        </div></div>
        <?php if ($u->can('companies.manage')): ?>
        <div class="bc-card mb-3"><div class="bc-card-body">
            <h2 class="h6">Account status</h2>
            <?php if ($c['status'] === 'active'): ?>
                <form method="post" action="<?= site_url('admin/companies/' . $c['id'] . '/suspend') ?>" data-confirm="Suspend this company? Listings will be hidden and premium features paused."><?= csrf_field() ?><label class="form-label" for="sr">Suspension reason</label><textarea id="sr" class="form-control form-control-sm mb-2" name="reason" rows="2" required></textarea><button class="btn btn-sm btn-outline-danger" type="submit">Suspend company</button></form>
            <?php else: ?><?= post_button('admin/companies/' . $c['id'] . '/reactivate', 'Reactivate company', 'btn btn-sm btn-primary') ?><?php endif ?>
        </div></div>
        <?php if ($sup): ?>
        <div class="bc-card mb-3"><div class="bc-card-body">
            <h2 class="h6">Account manager</h2>
            <form method="post" action="<?= site_url('admin/companies/' . $c['id'] . '/manager') ?>"><?= csrf_field() ?><?= select_field('account_manager_user_id', '', $managers, $c['account_manager_user_id'], ['placeholder' => '— None —', 'class' => 'mb-2']) ?><button class="btn btn-sm btn-light" type="submit">Save</button></form>
            <?php if (! service('entitlements')->has($c, 'dedicated_account_manager')): ?><p class="small text-muted mt-2 mb-0">Current plan does not include a dedicated account manager.</p><?php endif ?>
        </div></div>
        <?php endif ?>
        <?php endif ?>
        <?php if (! $sup && $u->can('verification.approve')): ?>
        <div class="bc-card mb-3"><div class="bc-card-body">
            <h2 class="h6">Restricted inventory access</h2>
            <p class="small"><?= ! empty($profile['restricted_inventory_access']) ? status_badge('approved', 'Granted') : '<span class="text-muted">Not granted</span>' ?></p>
            <?= post_button('admin/companies/' . $c['id'] . '/buyer-access', ! empty($profile['restricted_inventory_access']) ? 'Revoke access' : 'Grant access', 'btn btn-sm ' . (! empty($profile['restricted_inventory_access']) ? 'btn-outline-danger' : 'btn-primary'), null, ['grant' => ! empty($profile['restricted_inventory_access']) ? '0' : '1']) ?>
            <p class="small text-muted mt-2 mb-0">Only verified buyers can be granted access. Access is never automatic.</p>
        </div></div>
        <?php endif ?>
        <?php if ($u->can('performance.manage')): ?><div class="bc-card"><div class="bc-card-body"><?= post_button('admin/companies/' . $c['id'] . '/scores', 'Recalculate scores from data', 'btn btn-sm btn-light w-100') ?></div></div><?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

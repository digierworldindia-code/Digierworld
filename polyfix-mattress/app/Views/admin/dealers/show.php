<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $d @var list<array> $users @var array $stats @var list<array> $recentSales @var list<array> $recentClaims @var list<array> $versions */ ?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/dealers') ?>">Dealers</a></div>
    <h1><?= esc($d['business_name']) ?> <?= pill($d['status']) ?></h1>
    <p><span class="mono"><?= esc($d['code']) ?></span> · <?= esc($d['city']) ?>, <?= esc($d['state']) ?> · onboarded <?= esc(local_date($d['onboarded_at'])) ?></p></div>
</div>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><a class="stat" href="<?= site_url('admin/mattresses?status=DEALER_RECEIVED&dealer=' . $d['id']) ?>"><div class="stat__label">In stock</div><div class="stat__value"><?= (int) $stats['stock'] ?></div></a></div>
    <div class="col-6 col-lg-3"><a class="stat" href="<?= site_url('admin/sales?dealer=' . $d['id']) ?>"><div class="stat__label">Sales</div><div class="stat__value"><?= (int) $stats['sales'] ?></div></a></div>
    <div class="col-6 col-lg-3"><a class="stat" href="<?= site_url('admin/claims?dealer=' . $d['id']) ?>"><div class="stat__label">Claims</div><div class="stat__value"><?= (int) $stats['claims'] ?></div></a></div>
    <div class="col-6 col-lg-3"><a class="stat" href="<?= site_url('admin/dispatches?status=DISPATCHED') ?>"><div class="stat__label">Incoming</div><div class="stat__value"><?= (int) $stats['incoming'] ?></div></a></div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <?php if ($ctx->can('dealer:write')): ?>
        <form class="panel" method="post" action="<?= site_url('admin/dealers/' . $d['id']) ?>">
            <div class="panel-head"><h2>Details</h2></div>
            <div class="panel-body"><?= csrf_field() ?><?= view('admin/dealers/_fields', ['d' => $d]) ?>
                <button class="btn btn-primary mt-3" type="submit">Save changes</button></div>
        </form>
        <?php else: ?>
        <div class="panel"><div class="panel-head"><h2>Details</h2></div><div class="panel-body"><dl class="dl-grid">
            <dt>Owner</dt><dd><?= esc($d['owner_name']) ?></dd><dt>Phone</dt><dd><?= esc($d['phone']) ?></dd>
            <dt>Email</dt><dd><?= esc($d['email'] ?? '—') ?></dd><dt>GSTIN</dt><dd><?= esc($d['gst_number'] ?? '—') ?></dd>
            <dt>Address</dt><dd><?= esc(implode(', ', array_filter([$d['address_line1'], $d['address_line2'], $d['city'], $d['state'], $d['pincode']]))) ?></dd>
        </dl></div></div>
        <?php endif ?>

        <div class="panel"><div class="panel-head"><h2>Recent sales</h2><a class="small" href="<?= site_url('admin/sales?dealer=' . $d['id']) ?>">All</a></div>
            <?php if ($recentSales === []): ?><div class="table-empty">No sales recorded.</div><?php else: ?>
            <div class="table-responsive"><table class="table"><thead><tr><th>Invoice</th><th>Serial</th><th>Sold</th><th class="num">Price</th></tr></thead><tbody>
            <?php foreach ($recentSales as $s): ?><tr><td class="small"><?= esc($s['invoice_number']) ?></td>
                <td><a class="mono small" href="<?= site_url('admin/mattresses/' . $s['mattress_id']) ?>"><?= esc($s['serial_number']) ?></a></td>
                <td class="small"><?= esc(local_date($s['sold_at'])) ?></td><td class="num"><?= inr((float) $s['sale_price']) ?></td></tr><?php endforeach ?>
            </tbody></table></div><?php endif ?>
        </div>
    </div>

    <div class="col-xl-5">
        <?php if ($ctx->can('dealer:suspend')): ?>
        <form class="panel" method="post" action="<?= site_url('admin/dealers/' . $d['id'] . '/status') ?>" data-confirm="Change this dealer's status? Their signed-in users are signed out.">
            <div class="panel-head"><h2>Status</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <p class="small text-muted">A suspended or terminated dealer cannot sign in, receive stock or record sales. Their history stays intact.</p>
                <div class="row g-2">
                    <div class="col-5"><label class="form-label" for="status">New status</label><select class="form-select" id="status" name="status">
                        <?php foreach (['ACTIVE', 'SUSPENDED', 'TERMINATED'] as $s): ?><option value="<?= $s ?>"<?= $d['status'] === $s ? ' disabled' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
                    <div class="col-7"><label class="form-label" for="reason">Reason</label><input class="form-control" id="reason" name="reason" maxlength="500"></div>
                </div>
                <button class="btn btn-light mt-3" type="submit">Change status</button>
            </div>
        </form>
        <?php endif ?>

        <div class="panel"><div class="panel-head"><h2>Logins</h2><?php if ($ctx->can('user:write')): ?><a class="small" href="<?= site_url('admin/users/new') ?>">Add</a><?php endif ?></div>
            <?php if ($users === []): ?><div class="table-empty">No login yet for this dealer.</div><?php else: ?>
            <table class="table"><tbody><?php foreach ($users as $u): ?>
                <tr><td><?= $ctx->can('user:read') ? '<a href="' . site_url('admin/users/' . $u['id']) . '">' . esc($u['full_name']) . '</a>' : esc($u['full_name']) ?>
                    <div class="small text-muted"><?= esc($u['email']) ?></div></td>
                    <td><?= pill($u['status']) ?></td><td class="small text-nowrap"><?= esc(local_time($u['last_login_at'], 'd M Y')) ?></td></tr>
            <?php endforeach ?></tbody></table><?php endif ?>
        </div>

        <div class="panel"><div class="panel-head"><h2>Recent claims</h2></div>
            <?php if ($recentClaims === []): ?><div class="table-empty">No claims from this dealer.</div><?php else: ?>
            <table class="table"><tbody><?php foreach ($recentClaims as $c): ?>
                <tr><td><a href="<?= site_url('admin/claims/' . $c['id']) ?>"><?= esc($c['claim_number']) ?></a></td><td><?= pill($c['status']) ?></td>
                <td><?= $c['risk_level'] && $ctx->can('risk:view') ? pill($c['risk_level']) : '' ?></td><td class="small text-nowrap"><?= esc(local_date($c['submitted_at'])) ?></td></tr>
            <?php endforeach ?></tbody></table><?php endif ?>
        </div>

        <?php if ($versions !== []): ?>
        <div class="panel"><div class="panel-head"><h2>Change history</h2></div><div class="panel-body">
            <?php foreach ($versions as $v): ?><p class="small mb-1">v<?= (int) $v['version'] ?> · <?= esc(local_time($v['changed_at'])) ?> — <?= esc($v['reason'] ?? 'updated') ?></p><?php endforeach ?>
            <p class="small text-muted mb-0">Full detail is in the <a href="<?= site_url('admin/audit?entity=dealer&id=' . $d['id']) ?>">audit trail</a>.</p>
        </div></div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

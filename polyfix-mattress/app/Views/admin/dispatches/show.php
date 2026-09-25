<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $x @var list<array> $items @var list<array> $receipts */ ?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/dispatches') ?>">Dispatches</a></div>
    <h1 class="mono"><?= esc($x['dispatch_code']) ?> <?= pill($x['status'], $x['status'] === 'DRAFT' ? 'Ready' : null) ?></h1>
    <p><?= count($items) ?> unit(s) from <?= esc($x['warehouse']) ?> to <?= esc($x['dealer']) ?>, <?= esc($x['city']) ?></p></div>
</div>
<div class="row g-3">
    <div class="col-xl-7">
        <div class="panel"><div class="panel-head"><h2>Units</h2></div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Serial</th><th>Product</th><th>Size</th><th>Now</th><th>Received as</th></tr></thead>
                <tbody><?php foreach ($items as $i): ?>
                    <tr><td><a class="mono" href="<?= site_url('admin/mattresses/' . $i['id']) ?>"><?= esc($i['serial_number']) ?></a></td><td><?= esc($i['product']) ?></td>
                    <td class="small"><?= esc($i['size_label']) ?></td><td><?= pill($i['current_status']) ?></td><td><?= $i['received_condition'] ? pill($i['received_condition']) : '—' ?></td></tr>
                <?php endforeach ?></tbody>
            </table></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="panel"><div class="panel-head"><h2>Consignment</h2></div><div class="panel-body"><dl class="dl-grid">
            <dt>Dealer</dt><dd><a href="<?= site_url('admin/dealers/' . $x['dealer_id']) ?>"><?= esc($x['dealer']) ?></a> (<?= esc($x['dealer_code']) ?>)</dd>
            <dt>Created</dt><dd><?= esc(local_time($x['created_at'])) ?> by <?= esc($x['created_by_name'] ?? '—') ?></dd>
            <dt>Sent</dt><dd><?= esc(local_time($x['dispatched_at'])) ?></dd>
            <dt>Expected</dt><dd><?= esc(local_date($x['expected_at'])) ?></dd>
            <dt>Invoice</dt><dd><?= esc($x['invoice_number'] ?? '—') ?></dd>
            <dt>Transporter</dt><dd><?= esc($x['transporter'] ?? '—') ?></dd>
            <dt>LR / vehicle</dt><dd><?= esc($x['lr_number'] ?? '—') ?> / <?= esc($x['vehicle_number'] ?? '—') ?></dd>
            <dt>Remarks</dt><dd><?= esc($x['remarks'] ?? '—') ?></dd>
        </dl></div></div>

        <?php if ($x['status'] === 'DRAFT' && $ctx->can('dispatch:update')): ?>
        <form class="panel" method="post" action="<?= site_url('admin/dispatches/' . $x['id'] . '/send') ?>" data-confirm="Mark this consignment as sent? Custody passes to the dealer.">
            <div class="panel-head"><h2>Send</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label" for="s-invoice">Invoice number</label><input class="form-control" id="s-invoice" name="invoice_number" maxlength="60" value="<?= esc($x['invoice_number'] ?? '', 'attr') ?>"></div>
                    <div class="col-6"><label class="form-label" for="s-transporter">Transporter</label><input class="form-control" id="s-transporter" name="transporter" maxlength="120" value="<?= esc($x['transporter'] ?? '', 'attr') ?>"></div>
                    <div class="col-6"><label class="form-label" for="s-lr">LR number</label><input class="form-control" id="s-lr" name="lr_number" maxlength="60" value="<?= esc($x['lr_number'] ?? '', 'attr') ?>"></div>
                    <div class="col-6"><label class="form-label" for="s-vehicle">Vehicle</label><input class="form-control" id="s-vehicle" name="vehicle_number" maxlength="30" value="<?= esc($x['vehicle_number'] ?? '', 'attr') ?>"></div>
                </div>
                <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-truck"></i> Mark as sent</button>
            </div>
        </form>
        <?php endif ?>
        <?php if ($x['status'] === 'DRAFT' && $ctx->can('dispatch:cancel')): ?>
        <form class="panel" method="post" action="<?= site_url('admin/dispatches/' . $x['id'] . '/cancel') ?>" data-confirm="Cancel this dispatch and return the units to stock?">
            <div class="panel-head"><h2>Cancel</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <div class="d-flex gap-2"><input class="form-control" name="reason" required minlength="5" maxlength="500" placeholder="Reason"><button class="btn btn-danger" type="submit">Cancel dispatch</button></div>
            </div>
        </form>
        <?php endif ?>

        <?php if ($receipts !== []): ?>
        <div class="panel"><div class="panel-head"><h2>Receipts</h2></div><div class="panel-body">
            <?php foreach ($receipts as $r): ?><p class="small mb-2"><?= esc(local_time($r['received_at'])) ?> by <?= esc($r['full_name'] ?? 'dealer') ?><?= $r['remarks'] ? ' — ' . esc($r['remarks']) : '' ?></p><?php endforeach ?>
        </div></div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

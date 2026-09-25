<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var list<array> $dealers @var list<array> $warehouses @var list<array> $stock */ ?>
<div class="page-head"><div><div class="small"><a href="<?= site_url('admin/dispatches') ?>">Dispatches</a></div><h1>New dispatch</h1>
<p>Scan or paste the serial numbers going on the vehicle. They are reserved as soon as the dispatch is created.</p></div></div>
<div class="row g-3">
    <div class="col-xl-7">
        <form class="panel" method="post" action="<?= site_url('admin/dispatches') ?>">
            <div class="panel-body">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-7"><label class="form-label" for="dealer_id">Dealer</label>
                        <select class="form-select<?= invalid('dealer_id') ?>" id="dealer_id" name="dealer_id" required><option value="">Choose…</option>
                        <?php foreach ($dealers as $d): ?><option value="<?= $d['id'] ?>"<?= old('dealer_id') === $d['id'] ? ' selected' : '' ?>><?= esc($d['business_name']) ?> — <?= esc($d['city']) ?> (<?= esc($d['code']) ?>)</option><?php endforeach ?></select><?= field_error('dealer_id') ?></div>
                    <div class="col-md-5"><label class="form-label" for="warehouse_id">From</label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                        <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"<?= old('warehouse_id') === $w['id'] ? ' selected' : '' ?>><?= esc($w['name']) ?></option><?php endforeach ?></select></div>
                    <div class="col-12"><label class="form-label" for="serials">Serial numbers</label>
                        <textarea class="form-control mono<?= invalid('serials') ?>" id="serials" name="serials" rows="8" required placeholder="<?= esc(brand('serialPrefix'), 'attr') ?>26000001&#10;<?= esc(brand('serialPrefix'), 'attr') ?>26000002"><?= esc(old('serials', '')) ?></textarea>
                        <div class="form-text">One per line (a barcode scanner adds the line break), or separated by commas.</div><?= field_error('serials') ?></div>
                    <div class="col-md-4"><label class="form-label" for="expected_at">Expected on</label><input class="form-control" type="date" id="expected_at" name="expected_at" value="<?= esc(old('expected_at', ''), 'attr') ?>"></div>
                    <div class="col-md-4"><label class="form-label" for="invoice_number">Invoice number</label><input class="form-control" id="invoice_number" name="invoice_number" maxlength="60" value="<?= esc(old('invoice_number', ''), 'attr') ?>"></div>
                    <div class="col-md-4"><label class="form-label" for="transporter">Transporter</label><input class="form-control" id="transporter" name="transporter" maxlength="120" value="<?= esc(old('transporter', ''), 'attr') ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="lr_number">LR number</label><input class="form-control" id="lr_number" name="lr_number" maxlength="60" value="<?= esc(old('lr_number', ''), 'attr') ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="vehicle_number">Vehicle number</label><input class="form-control" id="vehicle_number" name="vehicle_number" maxlength="30" value="<?= esc(old('vehicle_number', ''), 'attr') ?>"></div>
                    <div class="col-12"><label class="form-label" for="remarks">Remarks</label><textarea class="form-control" id="remarks" name="remarks" rows="2" maxlength="1000"><?= esc(old('remarks', '')) ?></textarea></div>
                </div>
                <button class="btn btn-primary mt-3" type="submit">Create dispatch</button>
            </div>
        </form>
    </div>
    <div class="col-xl-5">
        <div class="panel"><div class="panel-head"><h2>Available in the warehouse</h2></div>
            <?php if ($stock === []): ?><div class="table-empty">No units waiting for dispatch.</div><?php else: ?>
            <div class="table-responsive"><table class="table"><thead><tr><th>Plant</th><th>Product</th><th>Size</th><th class="num">Units</th></tr></thead><tbody>
            <?php foreach ($stock as $s): ?><tr><td class="small"><?= esc($s['warehouse']) ?></td><td><?= esc($s['product']) ?></td><td class="small"><?= esc($s['size_label']) ?></td><td class="num"><?= (int) $s['n'] ?></td></tr><?php endforeach ?>
            </tbody></table></div>
            <div class="panel-body small"><a href="<?= site_url('admin/mattresses?status=MANUFACTURED') ?>">List the serials</a></div>
            <?php endif ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

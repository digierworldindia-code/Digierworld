<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php
use App\Controllers\Dealer\Sales;

/** @var string $serial @var list<array> $stock */
?>
<h1 class="h4 mb-1">Record a sale</h1>
<p class="small text-muted">The warranty starts from the date you enter here, so get it right — a correction later needs a reason and is recorded.</p>

<?php if ($stock === []): ?>
<div class="alert alert-secondary">You have no stock to sell yet. Confirm an incoming consignment first.</div>
<?php else: ?>
<form method="post" action="<?= site_url('dealer/sell') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="panel mb-3">
        <div class="panel-head"><h2>The mattress</h2></div>
        <div class="panel-body">
            <label class="form-label" for="serial">Serial number</label>
            <input class="form-control form-control-lg mono text-uppercase<?= invalid('serial') ?>" id="serial" name="serial" list="stock-serials" required
                   maxlength="20" autocapitalize="characters" autocomplete="off" value="<?= esc(old('serial', $serial), 'attr') ?>">
            <datalist id="stock-serials">
                <?php foreach ($stock as $s): ?><option value="<?= esc($s['serial_number'], 'attr') ?>"><?= esc($s['product'] . ' ' . $s['size_label']) ?></option><?php endforeach ?>
            </datalist>
            <?= field_error('serial') ?>
            <div class="form-text">Scan the label with your camera, or start typing — your stock appears as you type.</div>
        </div>
    </div>

    <div class="panel mb-3">
        <div class="panel-head"><h2>The sale</h2></div>
        <div class="panel-body row g-3">
            <div class="col-12"><label class="form-label" for="invoice_number">Invoice number</label>
                <input class="form-control<?= invalid('invoice_number') ?>" id="invoice_number" name="invoice_number" required maxlength="60" value="<?= esc(old('invoice_number', ''), 'attr') ?>"><?= field_error('invoice_number') ?></div>
            <div class="col-6"><label class="form-label" for="sold_on">Sold on</label>
                <input class="form-control<?= invalid('sold_on') ?>" type="date" id="sold_on" name="sold_on" required max="<?= esc(local_time(utc_now(), 'Y-m-d'), 'attr') ?>" value="<?= esc(old('sold_on', local_time(utc_now(), 'Y-m-d')), 'attr') ?>"><?= field_error('sold_on') ?></div>
            <div class="col-6"><label class="form-label" for="sale_price">Sale price</label>
                <input class="form-control<?= invalid('sale_price') ?>" id="sale_price" name="sale_price" inputmode="decimal" required value="<?= esc(old('sale_price', ''), 'attr') ?>"><?= field_error('sale_price') ?></div>
            <div class="col-12"><label class="form-label" for="payment_mode">Payment</label>
                <select class="form-select" id="payment_mode" name="payment_mode" required>
                    <?php foreach (Sales::PAYMENT_MODES as $k => $label): ?><option value="<?= $k ?>"<?= old('payment_mode') === $k ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach ?>
                </select></div>
        </div>
    </div>

    <div class="panel mb-3">
        <div class="panel-head"><h2>The customer</h2></div>
        <div class="panel-body row g-3">
            <div class="col-12"><label class="form-label" for="customer_full_name">Name</label>
                <input class="form-control<?= invalid('customer_full_name') ?>" id="customer_full_name" name="customer_full_name" required maxlength="160" autocomplete="off" value="<?= esc(old('customer_full_name', ''), 'attr') ?>"><?= field_error('customer_full_name') ?></div>
            <div class="col-12"><label class="form-label" for="customer_phone">Mobile number</label>
                <input class="form-control<?= invalid('customer_phone') ?>" id="customer_phone" name="customer_phone" type="tel" inputmode="tel" required maxlength="16" value="<?= esc(old('customer_phone', ''), 'attr') ?>"><?= field_error('customer_phone') ?>
                <div class="form-text">Stored encrypted. It is how a warranty claim is matched to this sale.</div></div>
            <div class="col-12"><label class="form-label" for="customer_email">Email <span class="text-muted">(optional)</span></label>
                <input class="form-control<?= invalid('customer_email') ?>" id="customer_email" name="customer_email" type="email" maxlength="255" value="<?= esc(old('customer_email', ''), 'attr') ?>"><?= field_error('customer_email') ?></div>
            <div class="col-12"><label class="form-label" for="customer_address_line">Address <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="customer_address_line" name="customer_address_line" maxlength="300" value="<?= esc(old('customer_address_line', ''), 'attr') ?>"></div>
            <div class="col-6"><label class="form-label" for="customer_city">City</label>
                <input class="form-control" id="customer_city" name="customer_city" maxlength="80" value="<?= esc(old('customer_city', ''), 'attr') ?>"></div>
            <div class="col-6"><label class="form-label" for="customer_pincode">PIN code</label>
                <input class="form-control<?= invalid('customer_pincode') ?>" id="customer_pincode" name="customer_pincode" inputmode="numeric" maxlength="6" value="<?= esc(old('customer_pincode', ''), 'attr') ?>"><?= field_error('customer_pincode') ?></div>
            <div class="col-12"><label class="form-label" for="customer_state">State</label>
                <input class="form-control" id="customer_state" name="customer_state" maxlength="80" value="<?= esc(old('customer_state', ''), 'attr') ?>"></div>
            <div class="col-12"><label class="form-label" for="remarks">Remarks <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="remarks" name="remarks" maxlength="500" value="<?= esc(old('remarks', ''), 'attr') ?>"></div>
        </div>
    </div>

    <button class="btn btn-primary btn-lg w-100" type="submit">Record the sale</button>
</form>
<?php endif ?>
<?= $this->endSection() ?>

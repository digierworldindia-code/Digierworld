<?php /** Shared dealer fields. @var array|null $d */
$v = static fn (string $k, string $default = ''): string => esc(old($k, $d[$k] ?? $default), 'attr');
$checked = static fn (string $k): string => (old($k) !== null ? old($k) === '1' : ! empty($d[$k])) ? ' checked' : '';
?>
<div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="business_name">Business name</label><input class="form-control<?= invalid('business_name') ?>" id="business_name" name="business_name" required maxlength="180" value="<?= $v('business_name') ?>"><?= field_error('business_name') ?></div>
    <div class="col-md-6"><label class="form-label" for="owner_name">Owner name</label><input class="form-control<?= invalid('owner_name') ?>" id="owner_name" name="owner_name" required maxlength="160" value="<?= $v('owner_name') ?>"><?= field_error('owner_name') ?></div>
    <div class="col-md-4"><label class="form-label" for="phone">Phone</label><input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" type="tel" required maxlength="16" value="<?= $v('phone') ?>"><?= field_error('phone') ?></div>
    <div class="col-md-4"><label class="form-label" for="email">Email</label><input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" maxlength="255" value="<?= $v('email') ?>"><?= field_error('email') ?></div>
    <div class="col-md-4"><label class="form-label" for="gst_number">GSTIN</label><input class="form-control<?= invalid('gst_number') ?>" id="gst_number" name="gst_number" maxlength="15" value="<?= $v('gst_number') ?>"><?= field_error('gst_number') ?></div>
    <div class="col-md-6"><label class="form-label" for="address_line1">Address line 1</label><input class="form-control<?= invalid('address_line1') ?>" id="address_line1" name="address_line1" required maxlength="200" value="<?= $v('address_line1') ?>"><?= field_error('address_line1') ?></div>
    <div class="col-md-6"><label class="form-label" for="address_line2">Address line 2</label><input class="form-control" id="address_line2" name="address_line2" maxlength="200" value="<?= $v('address_line2') ?>"></div>
    <div class="col-md-4"><label class="form-label" for="city">City</label><input class="form-control<?= invalid('city') ?>" id="city" name="city" required maxlength="80" value="<?= $v('city') ?>"><?= field_error('city') ?></div>
    <div class="col-md-4"><label class="form-label" for="state">State</label><input class="form-control<?= invalid('state') ?>" id="state" name="state" required maxlength="80" value="<?= $v('state') ?>"><?= field_error('state') ?></div>
    <div class="col-md-4"><label class="form-label" for="pincode">PIN code</label><input class="form-control<?= invalid('pincode') ?>" id="pincode" name="pincode" required inputmode="numeric" maxlength="6" value="<?= $v('pincode') ?>"><?= field_error('pincode') ?></div>
    <div class="col-md-6"><div class="form-check"><input type="hidden" name="public_listed" value="0"><input class="form-check-input" type="checkbox" id="public_listed" name="public_listed" value="1"<?= $checked('public_listed') ?>>
        <label class="form-check-label" for="public_listed">List on the public dealer locator</label></div>
        <div class="form-check"><input type="hidden" name="is_showroom" value="0"><input class="form-check-input" type="checkbox" id="is_showroom" name="is_showroom" value="1"<?= $checked('is_showroom') ?>>
        <label class="form-check-label" for="is_showroom">Has a showroom customers can visit</label></div></div>
    <div class="col-12"><label class="form-label" for="notes">Internal notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"><?= esc(old('notes', $d['notes'] ?? '')) ?></textarea></div>
</div>

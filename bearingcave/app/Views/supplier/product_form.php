<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php
$p      = $product ?? [];
$isEdit = ! empty($p['id']);
$action = $isEdit ? site_url('supplier/products/' . $p['id']) : site_url('supplier/products');
$v      = static fn (string $k, $d = '') => $p[$k] ?? $d;
$selShip = $isEdit ? explode(',', $p['shipping_options']) : ['platform_managed'];
$specRows = old('specs') ?: ($specs ?: [['spec_name' => '', 'spec_value' => '', 'unit' => '']]);
$fitRows  = old('fitments') ?: $fitments;
$tierRows = old('tiers') ?: $tiers;
$statusActions = [];
if ($isEdit) {
    $statusActions = match ($p['status']) {
        'draft', 'rejected' => ['submit' => 'Submit for publishing'],
        'published'   => ['unpublished' => 'Unpublish', 'archived' => 'Archive'],
        'unpublished' => ['published' => 'Re-publish', 'archived' => 'Archive'],
        'pending_review' => ['draft' => 'Withdraw from review'],
        'archived'    => ['draft' => 'Restore as draft'],
        default => [],
    };
}
?>
<?= view('components/page_header', ['title' => $isEdit ? $p['part_number'] . ' — ' . $p['name'] : 'Add inventory', 'subtitle' => $isEdit ? 'SKU ' . $p['sku'] : 'Listings are saved as drafts. Nothing is visible to buyers until you submit and it is approved.',
    'breadcrumbs' => ['Inventory' => 'supplier/products', ($isEdit ? 'Edit' : 'New') => null],
    'actions' => $isEdit ? '<a class="btn btn-light btn-sm" href="' . site_url('product/' . $p['slug']) . '" target="_blank"><i class="bi bi-eye me-1" aria-hidden="true"></i>Preview</a>' : '']) ?>
<?php if ($isEdit): ?>
<div class="bc-card mb-4"><div class="bc-card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div class="d-flex flex-wrap gap-3 align-items-center small">
        <span>Status <?= status_badge($p['status']) ?></span>
        <span>On hand <strong><?= number_format((int) $p['stock_on_hand']) ?></strong></span>
        <span>Reserved <strong><?= number_format((int) $p['stock_reserved']) ?></strong></span>
        <span>Available <strong><?= number_format(max(0, (int) $p['stock_on_hand'] - (int) $p['stock_reserved'])) ?></strong></span>
        <span>Age <strong><?= age_label($p['inventory_age_date']) ?></strong></span>
        <span>Views <strong><?= number_format((int) $p['view_count']) ?></strong> · Enquiries <strong><?= (int) $enquiries ?></strong></span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php foreach ($statusActions as $to => $label): ?>
            <?= $to === 'submit' ? post_button('supplier/products/' . $p['id'] . '/submit', $label, 'btn btn-sm btn-primary') : post_button('supplier/products/' . $p['id'] . '/status', $label, 'btn btn-sm ' . ($to === 'archived' ? 'btn-outline-danger' : 'btn-light'), $to === 'archived' ? 'Archive this listing?' : null, ['status' => $to]) ?>
        <?php endforeach ?>
    </div>
</div>
<?php if ($p['status'] === 'rejected' && $p['review_notes']): ?><div class="alert alert-warning"><strong>Reviewer feedback:</strong> <?= esc($p['review_notes']) ?></div><?php endif ?>
</div>
<?php endif ?>

<form method="post" action="<?= $action ?>" novalidate>
<?= csrf_field() ?>
<div class="row g-4">
    <div class="col-xl-8">
        <fieldset class="bc-fieldset"><legend>Product</legend>
            <div class="row g-3">
                <div class="col-md-8"><?= field('name', 'Product name', $v('name'), ['required' => true, 'class' => '', 'placeholder' => 'e.g. Deep groove ball bearing, sealed']) ?></div>
                <div class="col-md-4"><?= field('sku', 'Your SKU', $v('sku'), ['required' => true, 'class' => '']) ?></div>
                <div class="col-md-4"><?= field('part_number', 'Manufacturer part number', $v('part_number'), ['required' => true, 'class' => '', 'attrs' => 'autocapitalize="characters"']) ?></div>
                <div class="col-md-4"><?= field('oem_part_number', 'OEM part number', $v('oem_part_number'), ['class' => '']) ?></div>
                <div class="col-md-4"><?= select_field('item_condition', 'Condition', $conditions, $v('item_condition', 'new'), ['required' => true, 'class' => '']) ?></div>
                <div class="col-md-5"><?= select_field('category_id', 'Category', $categories, $v('category_id'), ['required' => true, 'class' => '', 'placeholder' => 'Select category']) ?></div>
                <div class="col-md-4"><?= select_field('brand_id', 'Brand', $brands, $v('brand_id'), ['class' => '', 'placeholder' => 'Unbranded / other', 'help' => 'Missing brand? Ask BearingCave to add it.']) ?></div>
                <div class="col-md-3"><?= field('unit_of_measure', 'Unit', $v('unit_of_measure', 'pcs'), ['class' => '']) ?></div>
                <div class="col-12"><?= textarea_field('description', 'Description', $v('description'), ['rows' => 3, 'class' => '', 'placeholder' => 'Packaging, batch/date codes, storage conditions, reason for surplus…']) ?></div>
            </div>
        </fieldset>

        <fieldset class="bc-fieldset"><legend>Part numbers &amp; cross references</legend>
            <div class="row g-3">
                <div class="col-md-6"><?= textarea_field('alt_part_numbers', 'Alternate / superseded numbers', $altNumbers, ['rows' => 3, 'class' => '', 'placeholder' => "BRAND:NUMBER, one per line", 'help' => 'Numbers this exact item is also sold under.']) ?></div>
                <div class="col-md-6"><?= textarea_field('cross_references', 'Cross references (other brands)', $xrefs, ['rows' => 3, 'class' => '', 'placeholder' => "FAG:6204-2RSR\nNSK:6204DDU", 'help' => 'Shown to buyers as “supplier-declared — not verified” unless BearingCave verifies them.']) ?></div>
            </div>
        </fieldset>

        <fieldset class="bc-fieldset"><legend>Dimensions &amp; specifications</legend>
            <div class="row g-3">
                <?php foreach (['inner_diameter_mm' => 'Inner Ø (mm)', 'outer_diameter_mm' => 'Outer Ø (mm)', 'width_mm' => 'Width (mm)', 'length_mm' => 'Length (mm)', 'height_mm' => 'Height (mm)', 'weight_kg' => 'Weight (kg)'] as $k => $l): ?>
                <div class="col-6 col-md-2"><?= field($k, $l, $v($k) !== null ? rtrim(rtrim((string) $v($k), '0'), '.') : '', ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                <?php endforeach ?>
            </div>
            <div data-repeater="specs" class="mt-2">
                <span class="form-label d-block">Other specifications</span>
                <div data-repeater-list>
                    <?php foreach (array_values($specRows) as $i => $s): ?>
                    <div class="row g-2 mb-2" data-repeater-item>
                        <div class="col-5"><input class="form-control form-control-sm" name="specs[<?= $i ?>][name]" value="<?= esc($s['spec_name'] ?? $s['name'] ?? '', 'attr') ?>" placeholder="Name (e.g. Seal)" aria-label="Specification name"></div>
                        <div class="col-4"><input class="form-control form-control-sm" name="specs[<?= $i ?>][value]" value="<?= esc($s['spec_value'] ?? $s['value'] ?? '', 'attr') ?>" placeholder="Value (e.g. 2RS)" aria-label="Specification value"></div>
                        <div class="col-2"><input class="form-control form-control-sm" name="specs[<?= $i ?>][unit]" value="<?= esc($s['unit'] ?? '', 'attr') ?>" placeholder="Unit" aria-label="Unit"></div>
                        <div class="col-1"><button class="btn btn-sm btn-link text-danger" type="button" data-repeater-remove aria-label="Remove"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                    </div>
                    <?php endforeach ?>
                </div>
                <template><div class="row g-2 mb-2" data-repeater-item><div class="col-5"><input class="form-control form-control-sm" name="specs[__INDEX__][name]" placeholder="Name" aria-label="Specification name"></div><div class="col-4"><input class="form-control form-control-sm" name="specs[__INDEX__][value]" placeholder="Value" aria-label="Specification value"></div><div class="col-2"><input class="form-control form-control-sm" name="specs[__INDEX__][unit]" placeholder="Unit" aria-label="Unit"></div><div class="col-1"><button class="btn btn-sm btn-link text-danger" type="button" data-repeater-remove aria-label="Remove"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div></div></template>
                <button class="btn btn-light btn-sm" type="button" data-repeater-add><i class="bi bi-plus" aria-hidden="true"></i> Add specification</button>
            </div>
        </fieldset>

        <fieldset class="bc-fieldset" data-repeater="fitments"><legend>Vehicle / application compatibility</legend>
            <div data-repeater-list>
                <?php foreach (array_values($fitRows) as $i => $f): ?>
                <div class="row g-2 mb-2" data-repeater-item>
                    <div class="col-md-3"><input class="form-control form-control-sm" name="fitments[<?= $i ?>][make]" value="<?= esc($f['make'] ?? '', 'attr') ?>" placeholder="Make" aria-label="Make"></div>
                    <div class="col-md-3"><input class="form-control form-control-sm" name="fitments[<?= $i ?>][model]" value="<?= esc($f['model'] ?? '', 'attr') ?>" placeholder="Model" aria-label="Model"></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="fitments[<?= $i ?>][engine]" value="<?= esc($f['engine'] ?? '', 'attr') ?>" placeholder="Engine" aria-label="Engine"></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="fitments[<?= $i ?>][year_from]" value="<?= esc((string) ($f['year_from'] ?? ''), 'attr') ?>" placeholder="From" inputmode="numeric" aria-label="Year from"></div>
                    <div class="col-md-1"><input class="form-control form-control-sm" name="fitments[<?= $i ?>][year_to]" value="<?= esc((string) ($f['year_to'] ?? ''), 'attr') ?>" placeholder="To" inputmode="numeric" aria-label="Year to"></div>
                    <div class="col-md-1"><button class="btn btn-sm btn-link text-danger" type="button" data-repeater-remove aria-label="Remove"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                </div>
                <?php endforeach ?>
            </div>
            <template><div class="row g-2 mb-2" data-repeater-item><div class="col-md-3"><input class="form-control form-control-sm" name="fitments[__INDEX__][make]" placeholder="Make" aria-label="Make"></div><div class="col-md-3"><input class="form-control form-control-sm" name="fitments[__INDEX__][model]" placeholder="Model" aria-label="Model"></div><div class="col-md-2"><input class="form-control form-control-sm" name="fitments[__INDEX__][engine]" placeholder="Engine" aria-label="Engine"></div><div class="col-md-2"><input class="form-control form-control-sm" name="fitments[__INDEX__][year_from]" placeholder="From" aria-label="Year from"></div><div class="col-md-1"><input class="form-control form-control-sm" name="fitments[__INDEX__][year_to]" placeholder="To" aria-label="Year to"></div><div class="col-md-1"><button class="btn btn-sm btn-link text-danger" type="button" data-repeater-remove aria-label="Remove"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div></div></template>
            <button class="btn btn-light btn-sm" type="button" data-repeater-add><i class="bi bi-plus" aria-hidden="true"></i> Add vehicle / application</button>
        </fieldset>

        <fieldset class="bc-fieldset"><legend>Pricing &amp; quantities</legend>
            <div class="row g-3">
                <div class="col-md-4"><?= select_field('sale_mode', 'Sell as', $can['piece'] ? ['lot' => 'Whole lot / bulk', 'piece' => 'Per piece', 'both' => 'Per piece or lot'] : ['lot' => 'Whole lot / bulk'], $v('sale_mode', 'lot'), ['required' => true, 'class' => '', 'help' => $can['piece'] ? null : 'Per-piece selling is a Verified Supplier feature.']) ?></div>
                <div class="col-md-4"><?= select_field('currency', 'Currency', currency_options(), $v('currency', 'INR'), ['required' => true, 'class' => '']) ?></div>
                <div class="col-md-4"><?= select_field('price_visibility', 'Price display', ['show' => 'Show price to signed-in buyers', 'on_request' => 'Price on request (RFQ)'], $v('price_visibility', 'show'), ['required' => true, 'class' => '']) ?></div>
                <div class="col-md-3"><?= field('lot_quantity', 'Lot size (units)', $v('lot_quantity'), ['class' => '', 'type' => 'number', 'attrs' => 'min="1"']) ?></div>
                <div class="col-md-3"><?= field('lot_price', 'Lot price', $v('lot_price'), ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                <div class="col-md-3"><?= field('unit_price', 'Per-piece price', $v('unit_price') !== null && $v('unit_price') !== '' ? rtrim(rtrim((string) $v('unit_price'), '0'), '.') : '', ['class' => '', 'attrs' => 'inputmode="decimal"' . ($can['piece'] ? '' : ' disabled')]) ?></div>
                <div class="col-md-3"><?= field('moq', 'Minimum order qty', $v('moq', 1), ['required' => true, 'class' => '', 'type' => 'number', 'attrs' => 'min="1"']) ?></div>
            </div>
            <?php if ($can['piece']): ?>
            <div data-repeater="tiers" class="mt-2">
                <span class="form-label d-block">Quantity-based pricing (optional)</span>
                <div data-repeater-list>
                    <?php foreach (array_values($tierRows) as $i => $t): ?>
                    <div class="row g-2 mb-2" data-repeater-item><div class="col-4"><input class="form-control form-control-sm" name="tiers[<?= $i ?>][min_qty]" value="<?= esc((string) $t['min_qty'], 'attr') ?>" placeholder="From qty" aria-label="From quantity"></div><div class="col-3"><input class="form-control form-control-sm" name="tiers[<?= $i ?>][max_qty]" value="<?= esc((string) ($t['max_qty'] ?? ''), 'attr') ?>" placeholder="To qty" aria-label="To quantity"></div><div class="col-4"><input class="form-control form-control-sm" name="tiers[<?= $i ?>][unit_price]" value="<?= esc((string) $t['unit_price'], 'attr') ?>" placeholder="Unit price" aria-label="Unit price"></div><div class="col-1"><button class="btn btn-sm btn-link text-danger" type="button" data-repeater-remove aria-label="Remove"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div></div>
                    <?php endforeach ?>
                </div>
                <template><div class="row g-2 mb-2" data-repeater-item><div class="col-4"><input class="form-control form-control-sm" name="tiers[__INDEX__][min_qty]" placeholder="From qty" aria-label="From quantity"></div><div class="col-3"><input class="form-control form-control-sm" name="tiers[__INDEX__][max_qty]" placeholder="To qty" aria-label="To quantity"></div><div class="col-4"><input class="form-control form-control-sm" name="tiers[__INDEX__][unit_price]" placeholder="Unit price" aria-label="Unit price"></div><div class="col-1"><button class="btn btn-sm btn-link text-danger" type="button" data-repeater-remove aria-label="Remove"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div></div></template>
                <button class="btn btn-light btn-sm" type="button" data-repeater-add><i class="bi bi-plus" aria-hidden="true"></i> Add price tier</button>
            </div>
            <?php endif ?>
        </fieldset>
    </div>

    <div class="col-xl-4">
        <?php if (! $isEdit): ?>
        <fieldset class="bc-fieldset"><legend>Initial stock</legend>
            <?= field('initial_quantity', 'Quantity on hand', null, ['type' => 'number', 'required' => true, 'attrs' => 'min="1"']) ?>
            <?= field('received_on', 'Stock received / manufacture date', null, ['type' => 'date', 'required' => true, 'attrs' => 'max="' . date('Y-m-d') . '"', 'help' => 'Used to calculate inventory age.']) ?>
            <?= field('lot_code', 'Lot / batch code', null, ['help' => 'Leave blank to auto-generate.']) ?>
        </fieldset>
        <?php endif ?>
        <fieldset class="bc-fieldset"><legend>Location</legend>
            <?= field('warehouse_city', 'Warehouse city', $v('warehouse_city')) ?>
            <?= select_field('warehouse_country', 'Warehouse country', country_options(), $v('warehouse_country', $company['country_code'])) ?>
        </fieldset>
        <fieldset class="bc-fieldset"><legend>Visibility &amp; confidentiality</legend>
            <?= select_field('visibility', 'Who can see this listing', ['public' => 'All buyers', 'verified_buyers' => 'Approved verified buyers only', 'hidden' => 'Hidden — RFQ matching only'], $v('visibility', 'public'), ['required' => true]) ?>
            <?php if ($can['countries']): ?>
                <?= select_field('country_mode', 'Countries', ['all' => 'All countries', 'allow_list' => 'Only selected countries', 'deny_list' => 'All except selected countries'], $v('country_mode', 'all'), ['required' => true, 'attrs' => 'data-toggle-target="#countryPick" data-toggle-hide-value="all"']) ?>
                <div id="countryPick"><?= select_field('countries[]', 'Selected countries', country_options(), $countries, ['multiple' => true, 'attrs' => 'size="8"', 'help' => 'Hold Ctrl/Cmd to select several.']) ?></div>
            <?php else: ?>
                <input type="hidden" name="country_mode" value="all">
                <p class="small text-muted">Country selection is a Verified Supplier feature. Listings are visible globally, subject to platform restrictions.</p>
            <?php endif ?>
            <?php if ($can['public']): ?>
                <?= check_field('hide_supplier_identity', 'Hide my company identity on this listing', $isEdit ? (bool) $p['hide_supplier_identity'] : $defaultConfidential) ?>
            <?php else: ?>
                <input type="hidden" name="hide_supplier_identity" value="1"><p class="small text-muted mb-0"><i class="bi bi-incognito me-1" aria-hidden="true"></i>Your identity is hidden from buyers (unverified suppliers are always anonymous).</p>
            <?php endif ?>
        </fieldset>
        <fieldset class="bc-fieldset"><legend>Shipping options</legend>
            <?php foreach ($shipping as $k => $l): $disabled = $k === 'supplier_direct' && ! $can['direct']; ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="shipping_options[]" value="<?= $k ?>" id="sh<?= $k ?>"<?= in_array($k, (array) old('shipping_options', $selShip), true) ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>><label class="form-check-label small" for="sh<?= $k ?>"><?= esc($l) ?><?= $disabled ? ' (Verified plan)' : '' ?></label></div>
            <?php endforeach ?>
        </fieldset>
        <button class="btn btn-primary w-100" type="submit"><?= $isEdit ? 'Save changes' : 'Save as draft' ?></button>
    </div>
</div>
</form>

<?php if ($isEdit): ?>
<div class="row g-4 mt-1">
    <div class="col-xl-8">
        <section class="bc-card mb-4"><div class="bc-card-header"><h2>Stock lots</h2><span class="small text-muted">Oldest stock is dispatched first</span></div>
            <div class="table-responsive"><table class="table table-bc table-stack mb-0">
                <thead><tr><th>Lot</th><th>Received</th><th>Age</th><th>Location</th><th class="text-end">Quantity</th><th>Adjust</th></tr></thead>
                <tbody><?php foreach ($lots as $l): ?><tr>
                    <td data-label="Lot" class="part-no"><?= esc($l['lot_code']) ?></td><td data-label="Received"><?= fdate($l['received_on']) ?></td><td data-label="Age" class="small"><?= age_label($l['received_on']) ?></td><td data-label="Location" class="small"><?= esc($l['warehouse_location'] ?? '—') ?></td>
                    <td data-label="Quantity" class="text-end fw-semibold"><?= number_format((int) $l['quantity']) ?></td>
                    <td data-label="Adjust"><form method="post" action="<?= site_url('supplier/products/' . $p['id'] . '/lots/' . $l['id'] . '/adjust') ?>" class="d-flex gap-1" style="min-width:260px"><?= csrf_field() ?><input class="form-control form-control-sm" name="delta" type="number" placeholder="+/-" aria-label="Quantity change" required style="max-width:80px"><input class="form-control form-control-sm" name="note" placeholder="Reason" aria-label="Reason" required><button class="btn btn-sm btn-light" type="submit">Apply</button></form></td>
                </tr><?php endforeach ?></tbody>
            </table></div>
            <div class="bc-card-body border-top">
                <form method="post" action="<?= site_url('supplier/products/' . $p['id'] . '/lots') ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-md-2"><label class="form-label" for="rq">Quantity</label><input id="rq" class="form-control form-control-sm" type="number" min="1" name="quantity" required></div>
                    <div class="col-md-3"><label class="form-label" for="rd">Received on</label><input id="rd" class="form-control form-control-sm" type="date" name="received_on" max="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-md-3"><label class="form-label" for="rl">Lot code</label><input id="rl" class="form-control form-control-sm" name="lot_code"></div>
                    <div class="col-md-2"><label class="form-label" for="rw">Location</label><input id="rw" class="form-control form-control-sm" name="warehouse_location"></div>
                    <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit">Receive stock</button></div>
                </form>
            </div>
        </section>
        <section class="bc-card"><div class="bc-card-header"><h2>Recent stock movements</h2></div>
            <div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>When</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th>Reference</th><th>Note</th></tr></thead><tbody>
            <?php foreach ($movements as $m): ?><tr><td><?= fdt($m['created_at']) ?></td><td><?= esc(ucfirst($m['movement_type'])) ?></td><td class="text-end <?= (int) $m['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $m['quantity'] > 0 ? '+' : '' ?><?= number_format((int) $m['quantity']) ?></td><td class="text-end"><?= number_format((int) $m['on_hand_after']) ?></td><td class="text-end"><?= number_format((int) $m['reserved_after']) ?></td><td><?= esc(($m['reference_type'] ?? '') . ($m['reference_id'] ? ' #' . $m['reference_id'] : '')) ?></td><td><?= esc($m['note'] ?? '') ?></td></tr><?php endforeach ?>
            </tbody></table></div>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="bc-card mb-4"><div class="bc-card-header"><h2>Images</h2><span class="small text-muted"><?= count($images) ?>/8</span></div><div class="bc-card-body">
            <div class="d-flex flex-wrap gap-2 mb-3"><?php foreach ($images as $img): ?><div class="text-center"><img src="<?= esc(product_image_url($img['path']), 'attr') ?>" alt="<?= esc($img['alt_text'] ?? '', 'attr') ?>" style="width:80px;height:80px;object-fit:contain" class="border rounded bg-white"><br><?= post_button('supplier/products/' . $p['id'] . '/images/' . $img['id'] . '/delete', 'Remove', 'btn btn-link btn-sm text-danger p-0', 'Remove this image?') ?></div><?php endforeach ?></div>
            <form method="post" action="<?= site_url('supplier/products/' . $p['id'] . '/images') ?>" enctype="multipart/form-data"><?= csrf_field() ?><label class="form-label" for="img">Add image (JPG/PNG/WebP, max 5 MB)</label><input id="img" class="form-control form-control-sm mb-2" type="file" name="image" accept="image/jpeg,image/png,image/webp" required><input class="form-control form-control-sm mb-2" name="alt_text" placeholder="Short description (accessibility)" aria-label="Image description"><button class="btn btn-sm btn-outline-primary" type="submit">Upload</button></form>
            <p class="small text-muted mt-2 mb-0">Images are resized and stripped of metadata. Avoid logos or labels that reveal your company if the listing is confidential.</p>
        </div></section>
        <section class="bc-card"><div class="bc-card-header"><h2>Documents</h2></div><div class="bc-card-body">
            <?php foreach ($documents as $d): ?><div class="small mb-1 d-flex justify-content-between"><a href="<?= site_url('documents/' . $d['uuid']) ?>"><?= esc($d['title']) ?></a><span class="text-muted"><?= esc(str_replace('_', ' ', $d['visibility'])) ?></span></div><?php endforeach ?>
            <form method="post" action="<?= site_url('supplier/products/' . $p['id'] . '/documents') ?>" enctype="multipart/form-data" class="mt-2"><?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" name="title" placeholder="Title" required aria-label="Document title">
                <select class="form-select form-select-sm mb-2" name="doc_type" aria-label="Document type"><option value="datasheet">Datasheet</option><option value="test_certificate">Test certificate</option><option value="certificate_of_conformity">Certificate of conformity</option><option value="packing_list">Packing list</option><option value="photos">Photos</option><option value="other">Other</option></select>
                <select class="form-select form-select-sm mb-2" name="visibility" aria-label="Who can see it"><option value="platform">All signed-in buyers who can see the listing</option><option value="verified_buyers">Verified buyers only</option><option value="private">Private (BearingCave only)</option></select>
                <input class="form-control form-control-sm mb-2" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.csv" required aria-label="File">
                <button class="btn btn-sm btn-outline-primary" type="submit">Upload document</button>
            </form>
        </div></section>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>

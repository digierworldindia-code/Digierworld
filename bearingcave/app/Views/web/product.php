<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?php
use App\Services\ProductService;

$p        = $product;
$isBuyer  = $viewer->isBuyer();
$owner    = $viewer->owns($p);
$price    = $canPrice ? service('products')->priceFor($p, max(1, (int) $p['moq'])) : null;
$lotMode  = $p['sale_mode'] === 'lot';
$step     = $lotMode && (int) $p['lot_quantity'] > 0 ? (int) $p['lot_quantity'] : 1;
?>
<div class="container py-4">
    <nav aria-label="Breadcrumb"><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Home</a></li>
        <li class="breadcrumb-item"><a href="<?= site_url('marketplace') ?>">Marketplace</a></li>
        <li class="breadcrumb-item"><a href="<?= site_url('category/' . $category['slug']) ?>"><?= esc($category['name']) ?></a></li>
        <li class="breadcrumb-item active part-no" aria-current="page"><?= esc($p['part_number']) ?></li>
    </ol></nav>

    <?php if ($p['status'] !== 'published'): ?>
        <div class="alert alert-warning"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Preview — this listing is <strong><?= esc(str_replace('_', ' ', $p['status'])) ?></strong> and not visible to buyers.</div>
    <?php endif ?>
    <?php if ($p['visibility'] === 'verified_buyers' || $p['country_mode'] !== 'all'): ?>
        <div class="alert alert-info small"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Restricted listing: visible only to <?= $p['visibility'] === 'verified_buyers' ? 'approved verified buyers' : 'buyers' ?><?= $p['country_mode'] !== 'all' ? ' in permitted countries' : '' ?>. Please do not share its details outside your organisation.</div>
    <?php endif ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div data-gallery>
                <div class="gallery-main mb-2">
                    <?php if ($images): ?>
                        <img data-gallery-main src="<?= esc(product_image_url($images[0]['path']), 'attr') ?>" alt="<?= esc($images[0]['alt_text'] ?: $p['name'], 'attr') ?>">
                    <?php else: ?>
                        <i class="bi bi-gear-wide-connected thumb-placeholder" style="font-size:4rem" aria-hidden="true"></i><span class="visually-hidden">No image provided</span>
                    <?php endif ?>
                </div>
                <?php if (count($images) > 1): ?>
                <div class="gallery-thumbs d-flex gap-2 flex-wrap">
                    <?php foreach ($images as $i => $img): ?>
                        <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-gallery-thumb data-src="<?= esc(product_image_url($img['path']), 'attr') ?>" data-alt="<?= esc($img['alt_text'] ?: $p['name'], 'attr') ?>" aria-label="Show image <?= $i + 1 ?>"><img src="<?= esc(product_image_url($img['path']), 'attr') ?>" alt="" loading="lazy"></button>
                    <?php endforeach ?>
                </div>
                <?php endif ?>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="row g-4">
                <div class="col-xl-7">
                    <p class="small-caps mb-1"><?= esc($brand['name'] ?? 'Unbranded') ?> · <?= esc($category['name']) ?></p>
                    <h1 class="h3 mb-2"><?= esc($p['name']) ?></h1>
                    <dl class="dl-grid mb-3">
                        <dt>Part number</dt><dd class="part-no fw-semibold"><?= esc($p['part_number']) ?></dd>
                        <?php if ($p['oem_part_number']): ?><dt>OEM number</dt><dd class="part-no"><?= esc($p['oem_part_number']) ?></dd><?php endif ?>
                        <dt>SKU</dt><dd class="part-no"><?= esc($p['sku']) ?></dd>
                        <dt>Condition</dt><dd><?= esc(ProductService::CONDITIONS[$p['item_condition']] ?? $p['item_condition']) ?></dd>
                        <dt>Inventory age</dt><dd><?= age_label($p['inventory_age_date']) ?></dd>
                        <dt>Stock location</dt><dd><?= esc(trim(($p['warehouse_city'] ? $p['warehouse_city'] . ', ' : '') . country_name($p['warehouse_country']))) ?></dd>
                        <dt>Inspection</dt><dd><?= $p['inspection_status'] === 'none' ? '<span class="text-muted">Not inspected — available on request</span>' : status_badge($p['inspection_status'], 'Inspection ' . $p['inspection_status']) ?></dd>
                    </dl>
                    <?php if ($p['description']): ?><div class="text-muted small mb-3" style="white-space:pre-line"><?= esc($p['description']) ?></div><?php endif ?>

                    <div class="bc-card p-3 mb-3">
                        <div class="small-caps mb-2">Supplier</div>
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold text-navy"><?= esc($supplierLabel) ?> <?= sample_badge($supplier) ?></div>
                                <div class="small text-muted"><?= esc(country_name($supplier['country_code'])) ?><?= $showIdentity ? '' : ' · identity confidential' ?></div>
                            </div>
                            <?= $supplierVerified ? '<span class="badge-verified"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Verified Supplier</span>' : '<span class="badge-soft neutral">Unverified supplier</span>' ?>
                        </div>
                        <?php if ($showContact): ?>
                            <div class="small mt-2"><i class="bi bi-telephone me-1" aria-hidden="true"></i><?= esc($supplier['phone'] ?? '—') ?> · <i class="bi bi-envelope me-1" aria-hidden="true"></i><?= esc($supplier['email'] ?? '—') ?></div>
                        <?php endif ?>
                        <?php if ($showIdentity && $supplier['public_profile_enabled'] && $supplierVerified): ?><a class="small" href="<?= site_url('suppliers/' . $supplier['slug']) ?>">View company profile</a><?php endif ?>
                        <?php if (! $supplierVerified): ?><p class="small text-muted mb-0 mt-2">Transactions with unverified suppliers can be protected with BearingCave inspection and managed logistics.</p><?php endif ?>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="bc-card p-3 buy-box">
                        <?php if ($canPrice && $price['total'] !== null): ?>
                            <div class="small text-muted"><?= $price['mode'] === 'lot' ? 'Lot price' : 'Price per piece' ?></div>
                            <div class="fs-3 fw-bold text-navy"><?= money($price['mode'] === 'lot' ? $p['lot_price'] : $price['unit'], $p['currency']) ?></div>
                            <div class="small text-muted mb-2"><?= $price['mode'] === 'lot' ? 'Lot of ' . number_format((int) $p['lot_quantity']) . ' ' . esc($p['unit_of_measure']) . ' (' . money($price['unit'], $p['currency'], 4) . ' / piece)' : 'Excl. taxes, inspection and logistics' ?></div>
                            <?php if ($tiers): ?>
                                <table class="table table-sm small mb-2"><caption class="visually-hidden">Quantity pricing</caption><thead><tr><th scope="col">Quantity</th><th scope="col" class="text-end">Unit price</th></tr></thead><tbody>
                                <?php foreach ($tiers as $t): ?><tr><td><?= number_format((int) $t['min_qty']) ?><?= $t['max_qty'] ? '–' . number_format((int) $t['max_qty']) : '+' ?></td><td class="text-end"><?= money($t['unit_price'], $p['currency'], 2) ?></td></tr><?php endforeach ?>
                                </tbody></table>
                            <?php endif ?>
                        <?php elseif ($p['price_visibility'] === 'on_request'): ?>
                            <div class="fs-5 fw-bold text-navy">Price on request</div><div class="small text-muted mb-2">Send an RFQ for a confidential quotation.</div>
                        <?php else: ?>
                            <div class="fs-6 fw-semibold text-navy mb-2"><a href="<?= site_url('login') ?>">Sign in</a> or <a href="<?= site_url('register/buyer') ?>">register free</a> to see prices.</div>
                        <?php endif ?>

                        <div class="d-flex justify-content-between small mb-3 border-top pt-2">
                            <span>Available</span><span class="fw-semibold <?= $available > 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($available) ?> <?= esc($p['unit_of_measure']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between small mb-3"><span>Minimum order</span><span class="fw-semibold"><?= number_format((int) $p['moq']) ?></span></div>

                        <?php if ($isBuyer): ?>
                            <?php if ($available > 0 && $canPrice && $price['total'] !== null): ?>
                            <form method="post" action="<?= site_url('buyer/cart/add/' . $p['id']) ?>" class="mb-2">
                                <?= csrf_field() ?>
                                <label class="form-label" for="qty">Quantity<?= $lotMode ? ' (multiples of ' . $step . ')' : '' ?></label>
                                <div class="input-group mb-2">
                                    <input id="qty" class="form-control" type="number" name="quantity" min="<?= max((int) $p['moq'], $step) ?>" max="<?= $available ?>" step="<?= $step ?>" value="<?= max((int) $p['moq'], $step) ?>" required>
                                    <span class="input-group-text"><?= esc($p['unit_of_measure']) ?></span>
                                </div>
                                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-cart-plus me-1" aria-hidden="true"></i>Add to cart</button>
                            </form>
                            <?php endif ?>
                            <a class="btn btn-outline-primary w-100 mb-2" href="<?= site_url('buyer/rfqs/new?product_id=' . $p['id']) ?>"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Request quotation</a>
                            <div class="d-flex gap-2">
                                <?= post_button('product/' . $p['id'] . '/save', $saved ? 'Saved' : 'Save', 'btn btn-light btn-sm w-100', null, [], $saved ? 'bi-bookmark-fill' : 'bi-bookmark') ?>
                                <?= post_button('compare/add/' . $p['id'], 'Compare', 'btn btn-light btn-sm w-100', null, [], 'bi-layout-three-columns') ?>
                            </div>
                        <?php elseif ($owner): ?>
                            <a class="btn btn-navy w-100" href="<?= site_url('supplier/products/' . $p['id'] . '/edit') ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Manage this listing</a>
                        <?php elseif ($viewer->isGuest()): ?>
                            <a class="btn btn-primary w-100 mb-2" href="<?= site_url('register/buyer') ?>">Register to buy or request a quote</a>
                            <?= post_button('compare/add/' . $p['id'], 'Add to comparison', 'btn btn-light btn-sm w-100', null, [], 'bi-layout-three-columns') ?>
                        <?php else: ?>
                            <?= post_button('compare/add/' . $p['id'], 'Add to comparison', 'btn btn-light btn-sm w-100', null, [], 'bi-layout-three-columns') ?>
                        <?php endif ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-lg-8">
            <section class="bc-card mb-4" aria-labelledby="specH">
                <div class="bc-card-header"><h2 id="specH">Specifications</h2></div>
                <div class="table-responsive"><table class="table spec-table mb-0 small">
                    <tbody>
                    <?php foreach (['inner_diameter_mm' => 'Inner diameter', 'outer_diameter_mm' => 'Outer diameter', 'width_mm' => 'Width', 'length_mm' => 'Length', 'height_mm' => 'Height'] as $k => $l): ?>
                        <?php if ($p[$k] !== null): ?><tr><th scope="row"><?= $l ?></th><td><?= esc(rtrim(rtrim($p[$k], '0'), '.')) ?> mm</td></tr><?php endif ?>
                    <?php endforeach ?>
                    <?php if ($p['weight_kg'] !== null): ?><tr><th scope="row">Weight</th><td><?= esc(rtrim(rtrim($p['weight_kg'], '0'), '.')) ?> kg</td></tr><?php endif ?>
                    <?php foreach ($specs as $s): ?><tr><th scope="row"><?= esc($s['spec_name']) ?></th><td><?= esc($s['spec_value']) ?> <?= esc($s['unit'] ?? '') ?></td></tr><?php endforeach ?>
                    <tr><th scope="row">Sale mode</th><td><?= esc(['lot' => 'Lot / bulk only', 'piece' => 'Per piece', 'both' => 'Per piece or lot'][$p['sale_mode']]) ?></td></tr>
                    <tr><th scope="row">Shipping options</th><td><?= esc(implode(', ', array_map(static fn ($o) => ProductService::SHIPPING_OPTIONS[$o] ?? $o, explode(',', $p['shipping_options'])))) ?></td></tr>
                    </tbody>
                </table></div>
            </section>

            <?php if ($partNumbers || $xrefs): ?>
            <section class="bc-card mb-4" aria-labelledby="xrefH">
                <div class="bc-card-header"><h2 id="xrefH">Part numbers &amp; cross references</h2></div>
                <div class="bc-card-body">
                    <p class="small text-muted"><i class="bi bi-exclamation-triangle text-warning" aria-hidden="true"></i> Cross references are declared by the supplier. Unless marked <strong>verified</strong>, they do not confirm interchangeability — check dimensions and specifications.</p>
                    <div class="table-responsive"><table class="table table-bc table-sm">
                        <thead><tr><th>Type</th><th>Brand</th><th>Number</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($partNumbers as $pn): ?><tr><td><?= esc(ucfirst($pn['number_type'])) ?></td><td><?= esc($pn['brand_name'] ?? '—') ?></td><td class="part-no"><?= esc($pn['part_number']) ?></td><td><span class="badge-soft neutral">Listed by supplier</span></td></tr><?php endforeach ?>
                        <?php foreach ($xrefs as $x): ?><tr><td>Cross reference</td><td><?= esc($x['ref_brand']) ?></td><td class="part-no"><?= esc($x['ref_part_number']) ?></td><td><?= $x['relation'] === 'verified_interchange' ? status_badge('verified', 'Verified by BearingCave') : '<span class="badge-soft warning">Not verified</span>' ?></td></tr><?php endforeach ?>
                        </tbody>
                    </table></div>
                </div>
            </section>
            <?php endif ?>

            <?php if ($fitments): ?>
            <section class="bc-card mb-4" aria-labelledby="fitH">
                <div class="bc-card-header"><h2 id="fitH">Vehicle / application compatibility</h2></div>
                <div class="table-responsive"><table class="table table-bc table-stack mb-0">
                    <thead><tr><th>Make</th><th>Model</th><th>Variant / engine</th><th>Years</th></tr></thead>
                    <tbody><?php foreach ($fitments as $ft): ?><tr><td data-label="Make"><?= esc($ft['make']) ?></td><td data-label="Model"><?= esc($ft['model'] ?? '—') ?></td><td data-label="Variant"><?= esc(trim(($ft['variant'] ?? '') . ' ' . ($ft['engine'] ?? '')) ?: '—') ?></td><td data-label="Years"><?= esc(($ft['year_from'] ?? '…') . '–' . ($ft['year_to'] ?? '…')) ?></td></tr><?php endforeach ?></tbody>
                </table></div>
                <div class="px-3 py-2 small text-muted">Compatibility information is provided by the supplier.</div>
            </section>
            <?php endif ?>

            <?php if (count($comparison) > 1): ?>
            <section class="bc-card mb-4" aria-labelledby="cmpH">
                <div class="bc-card-header"><h2 id="cmpH">Compare suppliers for <?= esc($p['part_number']) ?></h2><span class="small text-muted"><?= count($comparison) ?> offers</span></div>
                <div class="table-responsive"><table class="table table-bc table-stack mb-0">
                    <thead><tr><th>Supplier</th><th>Condition</th><th class="text-end">Available</th><th class="text-end">Price</th><th>Location</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($comparison as $c): $cp = $c['product']; ?>
                        <tr<?= (int) $cp['id'] === (int) $p['id'] ? ' class="table-active"' : '' ?>>
                            <td data-label="Supplier"><?= esc($c['label']) ?> <?= $c['verified'] ? '<i class="bi bi-patch-check-fill text-primary" title="Verified" aria-label="Verified"></i>' : '' ?></td>
                            <td data-label="Condition"><?= esc(ProductService::CONDITIONS[$cp['item_condition']] ?? '') ?></td>
                            <td data-label="Available" class="text-end"><?= number_format(max(0, (int) $cp['stock_on_hand'] - (int) $cp['stock_reserved'])) ?></td>
                            <td data-label="Price" class="text-end"><?= $c['price'] ? money($cp['unit_price'] ?? ($cp['lot_quantity'] ? (float) $cp['lot_price'] / (int) $cp['lot_quantity'] : null), $cp['currency']) . '<span class="text-muted small"> /pc</span>' : '<span class="text-muted">On request</span>' ?></td>
                            <td data-label="Location"><?= esc(country_name($cp['warehouse_country'])) ?></td>
                            <td><?= (int) $cp['id'] === (int) $p['id'] ? '<span class="small text-muted">Viewing</span>' : '<a href="' . site_url('product/' . $cp['slug']) . '">View</a>' ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table></div>
            </section>
            <?php endif ?>
        </div>
        <div class="col-lg-4">
            <?php if ($isBuyer): ?>
            <section class="bc-card mb-4" aria-labelledby="enqH">
                <div class="bc-card-header"><h2 id="enqH">Ask the supplier</h2></div>
                <div class="bc-card-body">
                    <?php if (! $acceptsEnquiries): ?>
                    <p class="small mb-0">This supplier receives requests through BearingCave RFQs only. Use <strong>Request quotation</strong> to ask about this item.</p>
                    <?php else: ?>
                    <form method="post" action="<?= site_url('product/' . $p['id'] . '/enquire') ?>">
                        <?= csrf_field() ?>
                        <?= field('quantity', 'Quantity needed', null, ['type' => 'number', 'attrs' => 'min="1"']) ?>
                        <?= textarea_field('message', 'Your question', null, ['required' => true, 'rows' => 3, 'placeholder' => 'Packaging, batch date, certificates, delivery…']) ?>
                        <button class="btn btn-outline-primary w-100" type="submit">Send enquiry</button>
                    </form>
                    <p class="small text-muted mt-2 mb-0">Messages are relayed by BearingCave. Your company details are shared only as needed for the transaction.</p>
                    <?php endif ?>
                </div>
            </section>
            <section class="bc-card mb-4" aria-labelledby="svcH">
                <div class="bc-card-header"><h2 id="svcH">Add services</h2></div>
                <div class="bc-card-body d-grid gap-2">
                    <a class="btn btn-light text-start" href="<?= site_url('buyer/inspections/new?product_id=' . $p['id']) ?>"><i class="bi bi-clipboard-check me-2" aria-hidden="true"></i>Request inspection</a>
                    <a class="btn btn-light text-start" href="<?= site_url('buyer/logistics/new') ?>"><i class="bi bi-truck me-2" aria-hidden="true"></i>Request logistics quote</a>
                </div>
            </section>
            <?php endif ?>
            <section class="bc-card mb-4" aria-labelledby="docH">
                <div class="bc-card-header"><h2 id="docH">Documents</h2></div>
                <div class="bc-card-body">
                    <?php if ($documents): ?>
                        <ul class="list-unstyled mb-0 d-grid gap-2">
                        <?php foreach ($documents as $d): ?><li><a href="<?= site_url('documents/' . $d['uuid']) ?>"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i><?= esc($d['title']) ?></a> <span class="small text-muted">(<?= esc(str_replace('_', ' ', $d['doc_type'])) ?>)</span></li><?php endforeach ?>
                        </ul>
                    <?php else: ?>
                        <p class="small text-muted mb-0"><?= $viewer->isGuest() ? 'Sign in to see supporting documents shared with registered buyers.' : 'No supporting documents shared for this listing.' ?></p>
                    <?php endif ?>
                </div>
            </section>
        </div>
    </div>

    <?php if ($related): ?>
    <section class="mt-2" aria-labelledby="relH">
        <h2 id="relH" class="h5 mb-3">Related inventory</h2>
        <div class="row g-3"><?php foreach ($related as $r): ?><div class="col-6 col-md-3"><?= view('components/product_card', ['p' => $r, 'viewer' => $viewer]) ?></div><?php endforeach ?></div>
    </section>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

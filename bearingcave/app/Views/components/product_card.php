<?php
/**
 * @var array $p search row (products + brand/company columns)
 * @var \App\Libraries\Viewer $viewer
 */
$vis      = service('visibility');
$supplier = ['id' => $p['supplier_id'] ?? $p['company_id'], 'legal_name' => $p['legal_name'] ?? '', 'trade_name' => $p['trade_name'] ?? null, 'public_alias' => $p['public_alias'] ?? '',
    'verification_status' => $p['verification_status'] ?? 'unverified', 'public_profile_enabled' => $p['public_profile_enabled'] ?? 0, 'show_contact_public' => $p['show_contact_public'] ?? 0,
    'status' => $p['company_status'] ?? 'active', 'company_type' => 'supplier'];
$verified = service('entitlements')->isVerifiedSupplier($supplier);
$canPrice = $vis->canSeePrice($p, $viewer);
$price    = $canPrice ? service('products')->priceFor($p, max(1, (int) $p['moq'])) : null;
$avail    = max(0, (int) $p['stock_on_hand'] - (int) $p['stock_reserved']);
$img      = product_image_url($p['image_path'] ?? null);
?>
<article class="product-card">
    <a class="thumb" href="<?= site_url('product/' . $p['slug']) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($img): ?><img src="<?= esc($img, 'attr') ?>" alt="" loading="lazy" decoding="async"><?php else: ?><i class="bi bi-gear-wide-connected thumb-placeholder"></i><?php endif ?>
    </a>
    <div class="body">
        <div class="d-flex justify-content-between gap-2 small">
            <span class="part-no fw-semibold text-navy"><?= esc($p['part_number']) ?></span>
            <span class="text-muted text-truncate"><?= esc($p['brand_name'] ?? 'Unbranded') ?></span>
        </div>
        <a class="title" href="<?= site_url('product/' . $p['slug']) ?>"><?= esc($p['name']) ?></a>
        <?= match_note($p['match_type'] ?? null) ?>
        <div class="meta d-flex flex-wrap gap-2">
            <span><i class="bi bi-box" aria-hidden="true"></i> <?= number_format($avail) ?> <?= esc($p['unit_of_measure']) ?> available</span>
            <span><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= esc(country_name($p['warehouse_country'])) ?></span>
        </div>
        <div class="meta"><?= esc(\App\Services\ProductService::CONDITIONS[$p['item_condition']] ?? $p['item_condition']) ?> · MOQ <?= (int) $p['moq'] ?> · Age: <?= age_label($p['inventory_age_date']) ?></div>
        <div class="mt-auto pt-2 d-flex justify-content-between align-items-end gap-2">
            <div>
                <?php if ($canPrice && $price['total'] !== null): ?>
                    <div class="price"><?= money($price['mode'] === 'lot' ? $p['lot_price'] : $price['unit'], $p['currency']) ?></div>
                    <div class="meta"><?= $price['mode'] === 'lot' ? 'per lot of ' . number_format((int) $p['lot_quantity']) : 'per piece' ?></div>
                <?php elseif ($p['price_visibility'] === 'on_request'): ?>
                    <div class="fw-semibold small text-navy">Price on request</div>
                <?php else: ?>
                    <a class="small fw-semibold" href="<?= site_url('login') ?>">Sign in for price</a>
                <?php endif ?>
            </div>
            <div class="text-end small">
                <?= $verified ? '<span class="badge-verified"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Verified</span>' : '<span class="text-muted">' . esc($vis->supplierLabel($supplier, $p, $viewer)) . '</span>' ?>
            </div>
        </div>
    </div>
</article>

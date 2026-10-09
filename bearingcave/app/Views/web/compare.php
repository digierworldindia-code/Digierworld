<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
        <div><h1 class="h3 mb-1">Compare inventory</h1><p class="text-muted mb-0">Compare price, stock, delivery options, inspection and supplier verification side by side.</p></div>
        <?php if ($rows): ?><?= post_button('compare/clear', 'Clear comparison', 'btn btn-light btn-sm') ?><?php endif ?>
    </div>
    <?php if (! $rows): ?>
        <div class="bc-card"><?= empty_state('bi-layout-three-columns', 'Nothing to compare yet', 'Use “Compare” on product pages to add up to ' . (int) policy('catalog.max_compare', 4) . ' listings.', '<a class="btn btn-primary" href="' . site_url('marketplace') . '">Browse marketplace</a>') ?></div>
    <?php else: ?>
    <div class="bc-card table-responsive">
        <table class="table table-bc align-top mb-0">
            <caption class="visually-hidden">Product comparison</caption>
            <thead><tr><th scope="col" style="min-width:160px">Attribute</th><?php foreach ($rows as $r): ?><th scope="col" style="min-width:200px"><a href="<?= site_url('product/' . $r['product']['slug']) ?>" class="part-no"><?= esc($r['product']['part_number']) ?></a><div class="fw-normal text-body text-none" style="text-transform:none;letter-spacing:0"><?= esc($r['product']['name']) ?></div></th><?php endforeach ?></tr></thead>
            <tbody>
                <?php
                $line = static function (string $label, callable $fn) use ($rows) {
                    echo '<tr><th scope="row" class="text-muted fw-semibold small">' . esc($label) . '</th>';
                    foreach ($rows as $r) { echo '<td>' . $fn($r) . '</td>'; }
                    echo '</tr>';
                };
                $line('Brand', static fn ($r) => esc($r['brand']['name'] ?? 'Unbranded'));
                $line('Supplier', static fn ($r) => esc($r['label']) . ' ' . ($r['verified'] ? '<span class="badge-verified"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Verified</span>' : '<span class="badge-soft neutral">Unverified</span>'));
                $line('Price', static fn ($r) => $r['price'] ? (($pr = service('products')->priceFor($r['product'], max(1, (int) $r['product']['moq'])))['total'] !== null ? money($pr['unit'], $r['product']['currency'], 4) . ' <span class="small text-muted">/pc</span>' : 'On request') : '<span class="text-muted">' . ($r['product']['price_visibility'] === 'on_request' ? 'On request' : 'Sign in to view') . '</span>');
                $line('Available stock', static fn ($r) => number_format($r['available']) . ' ' . esc($r['product']['unit_of_measure']));
                $line('Minimum order', static fn ($r) => number_format((int) $r['product']['moq']));
                $line('Condition', static fn ($r) => esc(\App\Services\ProductService::CONDITIONS[$r['product']['item_condition']] ?? ''));
                $line('Inventory age', static fn ($r) => age_label($r['product']['inventory_age_date']));
                $line('Stock location', static fn ($r) => esc(country_name($r['product']['warehouse_country'])));
                $line('Delivery options', static fn ($r) => esc(implode(', ', array_map(static fn ($o) => \App\Services\ProductService::SHIPPING_OPTIONS[$o] ?? $o, explode(',', $r['product']['shipping_options'])))));
                $line('Inspection', static fn ($r) => $r['product']['inspection_status'] === 'none' ? 'Available on request' : status_badge($r['product']['inspection_status']));
                if ($advanced) {
                    $line('Supplier performance', static function ($r) { $perf = service('performance')->latestSupplier((int) $r['supplier']['id']); return $perf && $perf['overall_score'] !== null ? esc($perf['overall_score']) . '/100' : '<span class="text-muted">Not enough data</span>'; });
                    $line('Supplier risk level', static function ($r) { $sp = db_connect()->table('supplier_profiles')->select('risk_level')->where('company_id', $r['supplier']['id'])->get()->getRowArray(); return $sp && $sp['risk_level'] ? status_badge($sp['risk_level']) : '<span class="text-muted">Not assessed</span>'; });
                }
                ?>
                <tr><th scope="row"></th><?php foreach ($rows as $r): ?><td><?= post_button('compare/remove/' . $r['product']['id'], 'Remove', 'btn btn-sm btn-link text-danger p-0') ?></td><?php endforeach ?></tr>
            </tbody>
        </table>
    </div>
    <?php if (! $advanced): ?><p class="small text-muted mt-3"><i class="bi bi-lock me-1" aria-hidden="true"></i>Verified buyers also see supplier performance and risk levels in comparisons.</p><?php endif ?>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

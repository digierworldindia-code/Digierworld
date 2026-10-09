<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?php
$f = $filters;
$q = static fn (array $extra = [], array $remove = []) => site_url('marketplace') . '?' . http_build_query(array_diff_key(array_merge($f, $extra), array_flip(array_merge($remove, ['page']))));
$active = array_diff_key($f, array_flip(['sort']));
?>
<div class="bg-soft border-bottom">
    <div class="container py-4">
        <nav aria-label="Breadcrumb"><ol class="breadcrumb mb-1"><li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Home</a></li><li class="breadcrumb-item"><a href="<?= site_url('marketplace') ?>">Marketplace</a></li><?php if (! empty($f['category'])): ?><li class="breadcrumb-item active" aria-current="page"><?= esc($heading) ?></li><?php endif ?></ol></nav>
        <h1 class="h3 mb-1"><?= esc($heading) ?></h1>
        <p class="text-muted mb-0"><?= number_format($result['total']) ?> listing<?= $result['total'] === 1 ? '' : 's' ?> visible to you<?= $viewer->isGuest() ? ' · sign in to see listings restricted to registered buyers' : '' ?></p>
    </div>
</div>
<div class="container py-4">
    <div class="row g-4">
        <aside class="col-lg-3">
            <button class="btn btn-light w-100 d-lg-none mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#filters" aria-expanded="false" aria-controls="filters"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filters<?= $active ? ' (' . count($active) . ')' : '' ?></button>
            <form id="filters" class="collapse d-lg-block filter-panel" method="get" action="<?= site_url('marketplace') ?>" aria-label="Search filters">
                <div class="filter-group pt-0">
                    <label class="form-label" for="flt-q">Keyword or part number</label>
                    <div class="position-relative"><input id="flt-q" class="form-control form-control-sm" type="search" name="q" value="<?= esc($f['q'] ?? '', 'attr') ?>" data-autocomplete="<?= site_url('search/suggest') ?>"></div>
                    <div class="form-text">Matches manufacturer, OEM, alternate and supplier-declared cross-reference numbers.</div>
                </div>
                <div class="filter-group">
                    <?= select_field('category', 'Category', array_column($categories, 'name', 'slug'), $f['category'] ?? '', ['placeholder' => 'All categories', 'class' => 'mb-2']) ?>
                    <?= select_field('brand', 'Brand', $brands, $f['brand'] ?? '', ['placeholder' => 'All brands', 'class' => 'mb-0']) ?>
                </div>
                <div class="filter-group">
                    <?= select_field('condition', 'Condition', $conditions, $f['condition'] ?? '', ['placeholder' => 'Any condition', 'class' => 'mb-2']) ?>
                    <?= select_field('age', 'Inventory age', $ages, $f['age'] ?? '', ['placeholder' => 'Any age', 'class' => 'mb-2']) ?>
                    <?= select_field('country', 'Stock location', country_options(), $f['country'] ?? '', ['placeholder' => 'Any country', 'class' => 'mb-0']) ?>
                </div>
                <div class="filter-group">
                    <span class="form-label d-block">Bearing dimensions (mm)</span>
                    <div class="row g-2">
                        <div class="col-4"><label class="visually-hidden" for="flt-id">Inner diameter</label><input id="flt-id" class="form-control form-control-sm" name="id" inputmode="decimal" placeholder="ID" value="<?= esc($f['id'] ?? '', 'attr') ?>"></div>
                        <div class="col-4"><label class="visually-hidden" for="flt-od">Outer diameter</label><input id="flt-od" class="form-control form-control-sm" name="od" inputmode="decimal" placeholder="OD" value="<?= esc($f['od'] ?? '', 'attr') ?>"></div>
                        <div class="col-4"><label class="visually-hidden" for="flt-w">Width</label><input id="flt-w" class="form-control form-control-sm" name="w" inputmode="decimal" placeholder="Width" value="<?= esc($f['w'] ?? '', 'attr') ?>"></div>
                    </div>
                    <label class="form-label mt-2" for="flt-spec">Specification contains</label>
                    <input id="flt-spec" class="form-control form-control-sm" name="spec" value="<?= esc($f['spec'] ?? '', 'attr') ?>" placeholder="e.g. C3, 2RS, steel">
                </div>
                <div class="filter-group">
                    <span class="form-label d-block">Vehicle compatibility</span>
                    <label class="visually-hidden" for="flt-make">Make</label>
                    <select id="flt-make" name="make" class="form-select form-select-sm mb-2"><option value="">Any make</option><?php foreach ($makes as $m): ?><option value="<?= esc($m, 'attr') ?>"<?= ($f['make'] ?? '') === $m ? ' selected' : '' ?>><?= esc($m) ?></option><?php endforeach ?></select>
                    <div class="row g-2"><div class="col-7"><label class="visually-hidden" for="flt-model">Model</label><input id="flt-model" class="form-control form-control-sm" name="model" placeholder="Model" value="<?= esc($f['model'] ?? '', 'attr') ?>"></div><div class="col-5"><label class="visually-hidden" for="flt-year">Year</label><input id="flt-year" class="form-control form-control-sm" name="year" inputmode="numeric" placeholder="Year" value="<?= esc($f['year'] ?? '', 'attr') ?>"></div></div>
                </div>
                <div class="filter-group">
                    <span class="form-label d-block">Price per piece &amp; quantity</span>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="visually-hidden" for="flt-min">Min price</label><input id="flt-min" class="form-control form-control-sm" name="min_price" inputmode="decimal" placeholder="Min" value="<?= esc($f['min_price'] ?? '', 'attr') ?>"></div>
                        <div class="col-6"><label class="visually-hidden" for="flt-max">Max price</label><input id="flt-max" class="form-control form-control-sm" name="max_price" inputmode="decimal" placeholder="Max" value="<?= esc($f['max_price'] ?? '', 'attr') ?>"></div>
                    </div>
                    <label class="visually-hidden" for="flt-qty">Minimum available quantity</label>
                    <input id="flt-qty" class="form-control form-control-sm" name="min_qty" inputmode="numeric" placeholder="Min. available quantity" value="<?= esc($f['min_qty'] ?? '', 'attr') ?>">
                </div>
                <div class="filter-group">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="verified" value="1" id="flt-ver"<?= ! empty($f['verified']) ? ' checked' : '' ?>><label class="form-check-label" for="flt-ver">Verified suppliers only</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="in_stock" value="1" id="flt-stock"<?= ! empty($f['in_stock']) ? ' checked' : '' ?>><label class="form-check-label" for="flt-stock">Available stock only</label></div>
                </div>
                <input type="hidden" name="sort" value="<?= esc($f['sort'] ?? 'relevance', 'attr') ?>">
                <div class="d-grid gap-2 pt-3"><button class="btn btn-primary btn-sm" type="submit">Apply filters</button><?php if ($active): ?><a class="btn btn-link btn-sm" href="<?= site_url('marketplace') ?>">Clear all</a><?php endif ?></div>
            </form>
        </aside>
        <div class="col-lg-9">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($active as $k => $v): ?>
                        <a class="badge-soft neutral text-decoration-none" href="<?= esc($q([], [$k]), 'attr') ?>" aria-label="Remove filter <?= esc($k, 'attr') ?>"><?= esc(str_replace('_', ' ', $k)) ?>: <?= esc($v) ?> <i class="bi bi-x" aria-hidden="true"></i></a>
                    <?php endforeach ?>
                </div>
                <form method="get" action="<?= site_url('marketplace') ?>" class="d-flex align-items-center gap-2">
                    <?php foreach (array_diff_key($f, ['sort' => 1]) as $k => $v): ?><input type="hidden" name="<?= esc($k, 'attr') ?>" value="<?= esc($v, 'attr') ?>"><?php endforeach ?>
                    <label class="small text-muted text-nowrap" for="sort">Sort by</label>
                    <select id="sort" name="sort" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($sorts as $k => $l): ?><option value="<?= $k ?>"<?= ($f['sort'] ?? 'relevance') === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?>
                    </select>
                    <noscript><button class="btn btn-sm btn-light">Sort</button></noscript>
                </form>
            </div>
            <?php if ($result['items']): ?>
                <div class="row g-3">
                    <?php foreach ($result['items'] as $p): ?><div class="col-6 col-md-4"><?= view('components/product_card', ['p' => $p, 'viewer' => $viewer]) ?></div><?php endforeach ?>
                </div>
                <?= pagination_links($result['page'], $result['pages'], $f) ?>
            <?php else: ?>
                <div class="bc-card"><?= empty_state('bi-search', 'No matching listings', 'Try a shorter part number, remove some filters, or send an RFQ — BearingCave will match it with verified suppliers who may hold unlisted stock.',
                    '<a class="btn btn-primary" href="' . site_url(auth()->loggedIn() ? 'buyer/rfqs/new' . (! empty($f['q']) ? '?part_number=' . rawurlencode($f['q']) : '') : 'register/buyer') . '">Request a quotation</a>') ?></div>
            <?php endif ?>
            <p class="small text-muted mt-4 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Part-number matches identify listings carrying the searched reference. A supplier-declared cross reference does not confirm interchangeability — check specifications or request inspection before ordering.</p>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

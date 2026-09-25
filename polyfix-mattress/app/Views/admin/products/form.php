<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/** @var array|null $p @var list<array> $variants @var int|null $units */
$new   = $p === null;
$v     = static fn (string $k, string $default = ''): string => esc(old($k, $p[$k] ?? $default), 'attr');
$lines = static fn (?string $json): string => implode("\n", json_decode((string) $json, true) ?: []);
?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/products') ?>">Products</a></div>
    <h1><?= $new ? 'New product' : esc($p['name']) ?> <?= $new ? '' : pill($p['status']) ?></h1>
    <?php if (! $new): ?><p><?= (int) ($units ?? 0) ?> unit(s) made · <a href="<?= site_url('admin/content/products/' . $p['id']) ?>">website copy</a></p><?php endif ?></div>
    <?php if (! $new): ?><a class="btn btn-light" href="<?= site_url('mattresses/' . $p['slug']) ?>" target="_blank" rel="noopener">View page</a><?php endif ?>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <form class="panel" method="post" action="<?= $new ? site_url('admin/products') : site_url('admin/products/' . $p['id']) ?>">
            <div class="panel-head"><h2>Details</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label" for="name">Name</label><input class="form-control<?= invalid('name') ?>" id="name" name="name" required maxlength="160" value="<?= $v('name') ?>"><?= field_error('name') ?></div>
                    <div class="col-md-4"><label class="form-label" for="category">Category</label><input class="form-control<?= invalid('category') ?>" id="category" name="category" required maxlength="60" value="<?= $v('category') ?>"><?= field_error('category') ?></div>
                    <div class="col-md-6"><label class="form-label" for="slug">Web address</label><div class="input-group"><span class="input-group-text small">/mattresses/</span>
                        <input class="form-control<?= invalid('slug') ?>" id="slug" name="slug" required maxlength="160" value="<?= $v('slug') ?>"></div><?= field_error('slug') ?></div>
                    <div class="col-md-3"><label class="form-label" for="sku_prefix">SKU prefix</label><input class="form-control<?= invalid('sku_prefix') ?>" id="sku_prefix" name="sku_prefix" required maxlength="20" value="<?= $v('sku_prefix') ?>"><?= field_error('sku_prefix') ?></div>
                    <div class="col-md-3"><label class="form-label" for="sort_order">Order</label><input class="form-control" type="number" id="sort_order" name="sort_order" min="0" value="<?= $v('sort_order', '0') ?>"></div>
                    <div class="col-12"><label class="form-label" for="tagline">Tagline</label><input class="form-control" id="tagline" name="tagline" maxlength="200" value="<?= $v('tagline') ?>"></div>
                    <div class="col-12"><label class="form-label" for="short_description">Short description</label><textarea class="form-control<?= invalid('short_description') ?>" id="short_description" name="short_description" rows="2" required maxlength="400"><?= esc(old('short_description', $p['short_description'] ?? '')) ?></textarea><?= field_error('short_description') ?></div>
                    <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control<?= invalid('description') ?>" id="description" name="description" rows="5" required maxlength="8000"><?= esc(old('description', $p['description'] ?? '')) ?></textarea><?= field_error('description') ?></div>
                    <div class="col-md-4"><label class="form-label" for="comfort_level">Comfort level</label><input class="form-control" id="comfort_level" name="comfort_level" required maxlength="40" value="<?= $v('comfort_level') ?>" placeholder="Medium-firm"></div>
                    <div class="col-md-2"><label class="form-label" for="firmness_score">Firmness</label><input class="form-control" type="number" id="firmness_score" name="firmness_score" min="1" max="10" required value="<?= $v('firmness_score', '5') ?>"><div class="form-text">1–10</div></div>
                    <div class="col-md-3"><label class="form-label" for="warranty_years">Warranty years</label><input class="form-control" type="number" id="warranty_years" name="warranty_years" min="1" max="25" required value="<?= $v('warranty_years', '10') ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="trial_nights">Trial nights</label><input class="form-control" type="number" id="trial_nights" name="trial_nights" min="0" max="365" value="<?= $v('trial_nights', '0') ?>"></div>
                    <?php if (! $new): ?>
                    <div class="col-md-6"><label class="form-label" for="materials">Materials</label><textarea class="form-control" id="materials" name="materials" rows="5" maxlength="4000"><?= esc($lines($p['materials'])) ?></textarea><div class="form-text">One per line.</div></div>
                    <div class="col-md-6"><label class="form-label" for="features">Features</label><textarea class="form-control" id="features" name="features" rows="5" maxlength="4000"><?= esc($lines($p['features'])) ?></textarea><div class="form-text">One per line.</div></div>
                    <div class="col-12"><label class="form-label" for="specifications">Specifications</label>
                        <textarea class="form-control mono" id="specifications" name="specifications" rows="8" spellcheck="false"><?= esc(json_encode(json_decode((string) $p['specifications'], true) ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
                        <div class="form-text">Name and value pairs, shown as the spec table on the product page.</div></div>
                    <div class="col-12"><label class="form-label" for="care_instructions">Care</label><textarea class="form-control" id="care_instructions" name="care_instructions" rows="2" maxlength="2000"><?= esc(old('care_instructions', $p['care_instructions'] ?? '')) ?></textarea></div>
                    <?php endif ?>
                </div>
                <button class="btn btn-primary mt-3" type="submit"><?= $new ? 'Create product' : 'Save product' ?></button>
            </div>
        </form>
    </div>

    <?php if (! $new): ?>
    <div class="col-xl-5">
        <?php if ($ctx->can('product:publish')): ?>
        <form class="panel" method="post" action="<?= site_url('admin/products/' . $p['id'] . '/status') ?>">
            <div class="panel-head"><h2>Publishing</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <div class="row g-2 align-items-end">
                    <div class="col-7"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status">
                        <?php foreach (['DRAFT', 'PUBLISHED', 'ARCHIVED'] as $s): ?><option value="<?= $s ?>"<?= $p['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
                    <div class="col-5"><button class="btn btn-light w-100" type="submit">Apply</button></div>
                    <div class="col-12"><div class="form-check"><input type="hidden" name="is_featured" value="0">
                        <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" value="1"<?= $p['is_featured'] ? ' checked' : '' ?>>
                        <label class="form-check-label" for="is_featured">Feature on the home page</label></div></div>
                </div>
            </div>
        </form>
        <?php endif ?>

        <div class="panel">
            <div class="panel-head"><h2>Sizes</h2><span class="small text-muted"><?= count($variants) ?></span></div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Size</th><th>SKU</th><th>L×W×H</th><th class="num">MRP</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($variants as $x): ?>
                    <tr><td class="small"><?= esc($x['size_label']) ?></td><td class="mono small"><?= esc($x['sku']) ?></td>
                    <td class="small"><?= esc(rtrim(rtrim($x['length_in'], '0'), '.')) ?>×<?= esc(rtrim(rtrim($x['width_in'], '0'), '.')) ?>×<?= esc(rtrim(rtrim($x['height_in'], '0'), '.')) ?></td>
                    <td class="num"><?= inr((float) $x['mrp']) ?></td><td><?= pill($x['status']) ?></td></tr>
                <?php endforeach ?></tbody>
            </table></div>
            <?php if ($ctx->can('product:write')): ?>
            <form class="panel-body border-top" method="post" action="<?= site_url('admin/products/' . $p['id'] . '/variants') ?>">
                <?= csrf_field() ?>
                <div class="mb-2"><label class="form-label" for="variant_id">Edit an existing size</label>
                    <select class="form-select form-select-sm" id="variant_id" name="variant_id"><option value="">Add a new size</option>
                        <?php foreach ($variants as $x): ?><option value="<?= $x['id'] ?>"><?= esc($x['size_label']) ?> (<?= esc($x['sku']) ?>)</option><?php endforeach ?></select>
                    <div class="form-text">Choosing a size here updates it with the values below.</div></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label" for="size_label">Size</label><input class="form-control form-control-sm" id="size_label" name="size_label" required maxlength="60" placeholder="Queen 78 x 60"></div>
                    <div class="col-6"><label class="form-label" for="sku">SKU</label><input class="form-control form-control-sm" id="sku" name="sku" required maxlength="40" placeholder="<?= esc($p['sku_prefix'], 'attr') ?>-Q"></div>
                    <div class="col-4"><label class="form-label" for="length_in">Length</label><input class="form-control form-control-sm" id="length_in" name="length_in" required inputmode="decimal"></div>
                    <div class="col-4"><label class="form-label" for="width_in">Width</label><input class="form-control form-control-sm" id="width_in" name="width_in" required inputmode="decimal"></div>
                    <div class="col-4"><label class="form-label" for="height_in">Height</label><input class="form-control form-control-sm" id="height_in" name="height_in" required inputmode="decimal"></div>
                    <div class="col-4"><label class="form-label" for="mrp">MRP</label><input class="form-control form-control-sm" id="mrp" name="mrp" required inputmode="decimal"></div>
                    <div class="col-4"><label class="form-label" for="weight_kg">Weight kg</label><input class="form-control form-control-sm" id="weight_kg" name="weight_kg" inputmode="decimal"></div>
                    <div class="col-4"><label class="form-label" for="variant-status">Status</label><select class="form-select form-select-sm" id="variant-status" name="status">
                        <option value="DRAFT">Draft</option><option value="PUBLISHED" selected>Published</option><option value="ARCHIVED">Archived</option></select></div>
                </div>
                <button class="btn btn-light btn-sm mt-3" type="submit">Save size</button>
            </form>
            <?php endif ?>
        </div>

        <?php if ($ctx->can('product:delete') && (int) ($units ?? 0) === 0): ?>
        <form class="panel" method="post" action="<?= site_url('admin/products/' . $p['id'] . '/delete') ?>" data-confirm="Remove this product?">
            <div class="panel-head"><h2>Remove</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <p class="small text-muted">Only possible while no mattress of this product exists. Otherwise archive it: the page leaves the website and the history stays.</p>
                <div class="d-flex gap-2"><input class="form-control" name="reason" required minlength="5" maxlength="500" placeholder="Reason"><button class="btn btn-danger" type="submit">Remove</button></div>
            </div>
        </form>
        <?php endif ?>
    </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

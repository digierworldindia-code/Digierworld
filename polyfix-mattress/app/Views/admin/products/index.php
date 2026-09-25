<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list */ ?>
<div class="page-head">
    <div><h1>Products</h1><p>Ranges and sizes. The warranty term on a sale comes from the product record.</p></div>
    <?php if ($ctx->can('product:write')): ?><a class="btn btn-primary" href="<?= site_url('admin/products/new') ?>"><i class="bi bi-plus-lg"></i> New product</a><?php endif ?>
</div>
<div class="panel">
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Product</th><th>Category</th><th>Status</th><th class="num">Sizes</th><th class="num">From</th><th class="num">Units made</th><th>Warranty</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $p): ?>
        <tr><td><a class="fw-semibold" href="<?= site_url('admin/products/' . $p['id']) ?>"><?= esc($p['name']) ?></a>
                <?= $p['is_featured'] ? ' <span class="pill pill-accent">Featured</span>' : '' ?>
                <div class="small text-muted">/mattresses/<?= esc($p['slug']) ?></div></td>
            <td class="small"><?= esc($p['category']) ?></td><td><?= pill($p['status']) ?></td>
            <td class="num"><?= (int) $p['sizes'] ?></td>
            <td class="num"><?= $p['price_from'] !== null ? inr((float) $p['price_from']) : '—' ?></td>
            <td class="num"><?= (int) $p['units'] ?></td><td class="small"><?= (int) $p['warranty_years'] ?> years</td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="7" class="table-empty">No products.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>

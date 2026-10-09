<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Categories']) ?>
<div class="row g-4">
    <div class="col-xl-8"><div class="bc-card"><div class="table-responsive"><table class="table table-bc mb-0">
        <thead><tr><th>Category</th><th>Parent</th><th class="text-end">Products</th><th>Active</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $c): ?><tr><td><?= $c['parent_id'] ? '<span class="text-muted ms-3">↳</span> ' : '<i class="bi ' . esc($c['icon'] ?: 'bi-folder', 'attr') . ' me-1" aria-hidden="true"></i>' ?><?= esc($c['name']) ?> <span class="small text-muted"><?= esc($c['slug']) ?></span></td><td class="small"><?= esc($c['parent_name'] ?? '—') ?></td><td class="text-end"><?= (int) $c['products'] ?></td><td><?= $c['is_active'] ? status_badge('active') : status_badge('neutral', 'Hidden') ?></td>
            <td><button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#cat<?= $c['id'] ?>">Edit</button></td></tr>
            <tr class="collapse" id="cat<?= $c['id'] ?>"><td colspan="5" class="bg-soft"><form method="post" action="<?= site_url('admin/categories/' . $c['id']) ?>" class="row g-2"><?= csrf_field() ?>
                <div class="col-md-4"><?= field('name', 'Name', $c['name'], ['class' => '']) ?></div><div class="col-md-3"><?= select_field('parent_id', 'Parent', $parents, $c['parent_id'], ['placeholder' => '— Top level —', 'class' => '']) ?></div>
                <div class="col-md-2"><?= field('sort_order', 'Order', $c['sort_order'], ['type' => 'number', 'class' => '']) ?></div><div class="col-md-3"><?= select_field('is_active', 'Visible', ['1' => 'Yes', '0' => 'No'], $c['is_active'], ['class' => '']) ?></div>
                <div class="col-md-4"><?= field('icon', 'Bootstrap icon', $c['icon'], ['class' => '']) ?></div><div class="col-md-8"><?= field('meta_description', 'Meta description', $c['meta_description'], ['class' => '']) ?></div>
                <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save</button></div></form></td></tr>
        <?php endforeach ?></tbody></table></div></div></div>
    <div class="col-xl-4"><div class="bc-card"><div class="bc-card-header"><h2>Add category</h2></div><div class="bc-card-body">
        <form method="post" action="<?= site_url('admin/categories') ?>" novalidate><?= csrf_field() ?><?= field('name', 'Name', null, ['required' => true]) ?><?= select_field('parent_id', 'Parent', $parents, null, ['placeholder' => '— Top level —']) ?><?= field('sort_order', 'Sort order', 0, ['type' => 'number']) ?><?= field('icon', 'Bootstrap icon (e.g. bi-gear)', null) ?><?= field('meta_description', 'Meta description', null) ?><button class="btn btn-primary w-100" type="submit">Add category</button></form>
    </div></div></div>
</div>
<?= $this->endSection() ?>

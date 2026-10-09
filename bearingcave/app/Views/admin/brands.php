<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Brands', 'subtitle' => 'Brand names classify listings; BearingCave does not claim affiliation with manufacturers.']) ?>
<div class="row g-4">
    <div class="col-xl-8"><div class="bc-card"><div class="table-responsive"><table class="table table-bc mb-0">
        <thead><tr><th>Brand</th><th>Type</th><th>Country</th><th class="text-end">Products</th><th>Active</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $b): ?><tr><td><?= esc($b['name']) ?></td><td class="small"><?= esc(ucfirst($b['brand_type'])) ?></td><td class="small"><?= esc(country_name($b['country_code'])) ?></td><td class="text-end"><?= (int) $b['products'] ?></td><td><?= $b['is_active'] ? status_badge('active') : status_badge('neutral', 'Hidden') ?></td><td><button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#br<?= $b['id'] ?>">Edit</button></td></tr>
            <tr class="collapse" id="br<?= $b['id'] ?>"><td colspan="6" class="bg-soft"><form method="post" action="<?= site_url('admin/brands/' . $b['id']) ?>" class="row g-2"><?= csrf_field() ?><div class="col-md-4"><?= field('name', 'Name', $b['name'], ['class' => '']) ?></div><div class="col-md-3"><?= select_field('brand_type', 'Type', ['oem' => 'OEM', 'aftermarket' => 'Aftermarket', 'both' => 'Both'], $b['brand_type'], ['class' => '']) ?></div><div class="col-md-3"><?= select_field('country_code', 'Country', country_options(), $b['country_code'], ['placeholder' => '—', 'class' => '']) ?></div><div class="col-md-2"><?= select_field('is_active', 'Active', ['1' => 'Yes', '0' => 'No'], $b['is_active'], ['class' => '']) ?></div><div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save</button></div></form></td></tr>
        <?php endforeach ?></tbody></table></div></div></div>
    <div class="col-xl-4"><div class="bc-card"><div class="bc-card-header"><h2>Add brand</h2></div><div class="bc-card-body">
        <form method="post" action="<?= site_url('admin/brands') ?>" novalidate><?= csrf_field() ?><?= field('name', 'Name', null, ['required' => true]) ?><?= select_field('brand_type', 'Type', ['both' => 'Both', 'oem' => 'OEM', 'aftermarket' => 'Aftermarket'], 'both') ?><?= select_field('country_code', 'Country', country_options(), null, ['placeholder' => '—']) ?><button class="btn btn-primary w-100" type="submit">Add brand</button></form>
    </div></div></div>
</div>
<?= $this->endSection() ?>

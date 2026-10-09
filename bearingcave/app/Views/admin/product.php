<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => $title, 'breadcrumbs' => ['Product review' => 'admin/products', $p['part_number'] => null], 'actions' => '<a class="btn btn-sm btn-light" href="' . site_url('product/' . $p['slug']) . '" target="_blank">View public page</a>']) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="bc-card mb-4"><div class="bc-card-body"><dl class="dl-grid small mb-0">
            <dt>Supplier</dt><dd><a href="<?= site_url('admin/companies/' . $supplier['id']) ?>"><?= esc($supplier['legal_name']) ?></a> · <?= status_badge($supplier['verification_status']) ?> <?= sample_badge($supplier) ?></dd>
            <dt>Category / brand</dt><dd><?= esc($category['name']) ?> · <?= esc($brand['name'] ?? 'Unbranded') ?></dd><dt>Part / OEM</dt><dd class="part-no"><?= esc($p['part_number']) ?> / <?= esc($p['oem_part_number'] ?? '—') ?></dd>
            <dt>Condition</dt><dd><?= esc($p['item_condition']) ?></dd><dt>Stock</dt><dd><?= number_format((int) $p['stock_on_hand']) ?> on hand · <?= number_format((int) $p['stock_reserved']) ?> reserved · age <?= age_label($p['inventory_age_date']) ?></dd>
            <dt>Pricing</dt><dd><?= esc($p['sale_mode']) ?> · unit <?= money($p['unit_price'], $p['currency'], 2) ?> · lot <?= money($p['lot_price'], $p['currency']) ?> × <?= esc((string) $p['lot_quantity']) ?> · MOQ <?= (int) $p['moq'] ?> · <?= esc($p['price_visibility']) ?></dd>
            <dt>Visibility</dt><dd><?= esc($p['visibility']) ?> · countries: <?= esc($p['country_mode']) ?> <?= $countries ? '(' . esc(implode(', ', array_column($countries, 'country_code'))) . ')' : '' ?> · identity <?= $p['hide_supplier_identity'] ? 'hidden' : 'shown' ?></dd>
            <dt>Description</dt><dd><?= esc($p['description'] ?? '—') ?></dd>
        </dl></div></div>
        <?php if ($xrefs): ?><div class="bc-card mb-4"><div class="bc-card-header"><h2>Cross references</h2></div><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Brand</th><th>Number</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($xrefs as $x): ?><tr><td><?= esc($x['ref_brand']) ?></td><td class="part-no"><?= esc($x['ref_part_number']) ?></td><td><?= $x['relation'] === 'verified_interchange' ? status_badge('verified') : status_badge('pending', 'Supplier-declared') ?></td><td><?= post_button('admin/products/' . $p['id'] . '/xref/' . $x['id'] . '/verify', $x['relation'] === 'verified_interchange' ? 'Remove verification' : 'Mark verified interchange', 'btn btn-sm btn-light', $x['relation'] === 'verified_interchange' ? null : 'Only verify interchangeability that has been technically confirmed. Continue?', ['verify' => $x['relation'] === 'verified_interchange' ? '0' : '1']) ?></td></tr><?php endforeach ?>
        </tbody></table></div></div><?php endif ?>
        <?php if ($images): ?><div class="bc-card mb-4"><div class="bc-card-body d-flex flex-wrap gap-2"><?php foreach ($images as $i): ?><img src="<?= esc(product_image_url($i['path']), 'attr') ?>" alt="<?= esc($i['alt_text'] ?? '', 'attr') ?>" style="width:120px;height:120px;object-fit:contain" class="border rounded"><?php endforeach ?></div></div><?php endif ?>
    </div>
    <div class="col-xl-4">
        <div class="bc-card mb-3"><div class="bc-card-body">
            <h2 class="h6">Review</h2><p class="small">Status: <?= status_badge($p['status']) ?></p>
            <?php if ($p['status'] === 'pending_review'): ?>
                <p class="small text-muted">Check that the listing describes genuine goods accurately, contains no contact details on confidential listings, and that images/documents do not reveal the supplier if identity is hidden.</p>
                <form method="post" action="<?= site_url('admin/products/' . $p['id'] . '/review') ?>"><?= csrf_field() ?><label class="form-label" for="rn">Notes to supplier</label><textarea id="rn" class="form-control form-control-sm mb-2" name="notes" rows="3"></textarea>
                    <div class="d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit" name="decision" value="approve">Approve &amp; publish</button><button class="btn btn-sm btn-outline-danger" type="submit" name="decision" value="reject">Return for changes</button></div></form>
            <?php elseif ($p['status'] === 'published'): ?><?= post_button('admin/products/' . $p['id'] . '/status', 'Unpublish', 'btn btn-sm btn-outline-danger', 'Unpublish this listing?', ['status' => 'unpublished']) ?><?php endif ?>
        </div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Documents</h2></div><div class="bc-card-body small"><?php foreach ($documents as $d): ?><div><a href="<?= site_url('documents/' . $d['uuid']) ?>" target="_blank"><?= esc($d['title']) ?></a> · <?= esc($d['visibility']) ?></div><?php endforeach ?><?= $documents ? '' : '<span class="text-muted">None</span>' ?></div></div>
    </div>
</div>
<?= $this->endSection() ?>

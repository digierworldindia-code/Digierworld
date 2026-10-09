<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Approved Vendor List', 'subtitle' => 'Category, brand and product approvals for verified suppliers. Used in RFQ matching.']) ?>
<div class="row g-4">
    <div class="col-xl-8"><div class="bc-card">
        <?php if (! $rows): ?><?= empty_state('bi-list-check', 'No approvals yet') ?><?php else: ?>
        <div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Supplier</th><th>Scope</th><th>Valid until</th><th>Status</th><th>Change</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td data-label="Supplier"><a href="<?= site_url('admin/companies/' . $r['company_id']) ?>"><?= esc($r['legal_name']) ?></a></td><td data-label="Scope"><?= esc(ucfirst($r['scope_type'])) ?>: <?= esc($r['scope_label'] ?? '') ?></td><td data-label="Valid until"><?= fdate($r['valid_until']) ?></td><td data-label="Status"><?= status_badge($r['status']) ?></td>
            <td data-label="Change"><form method="post" action="<?= site_url('admin/avl/' . $r['id']) ?>" class="d-flex gap-1"><?= csrf_field() ?><select class="form-select form-select-sm" name="status" aria-label="Status"><?php foreach (['approved', 'suspended', 'revoked'] as $s): ?><option value="<?= $s ?>"<?= $r['status'] === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach ?></select><button class="btn btn-sm btn-light" type="submit">Save</button></form></td></tr><?php endforeach ?>
        </tbody></table></div><?php endif ?>
    </div></div>
    <div class="col-xl-4"><div class="bc-card"><div class="bc-card-header"><h2>Add approval</h2></div><div class="bc-card-body">
        <form method="post" action="<?= site_url('admin/avl') ?>" novalidate><?= csrf_field() ?>
            <?= select_field('company_id', 'Verified supplier', $suppliers, null, ['required' => true, 'placeholder' => 'Select']) ?>
            <?= select_field('scope_type', 'Scope', ['general' => 'General (all categories)', 'category' => 'Category', 'brand' => 'Brand', 'product' => 'Specific product'], 'category', ['required' => true]) ?>
            <?= select_field('category_id', 'Category', $categories, null, ['placeholder' => '—']) ?>
            <?= select_field('brand_id', 'Brand', $brands, null, ['placeholder' => '—']) ?>
            <?= field('product_id', 'Product ID (for product scope)', null, ['type' => 'number']) ?>
            <?= field('valid_until', 'Valid until', null, ['type' => 'date']) ?>
            <?= textarea_field('notes', 'Notes', null, ['rows' => 2]) ?>
            <button class="btn btn-primary w-100" type="submit">Save approval</button>
        </form>
    </div></div></div>
</div>
<?= $this->endSection() ?>

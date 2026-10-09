<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php
$items = old('items') ?: [$prefill ?: ['part_number' => '', 'description' => '', 'quantity' => 1]];
$catOpts = $categories; $brandOpts = $brands;
$row = static function ($i, array $it) use ($catOpts, $brandOpts) {
    ob_start(); ?>
    <div class="border rounded p-3 mb-2 bg-white" data-repeater-item>
        <?php if (! empty($it['product_id'])): ?><input type="hidden" name="items[<?= $i ?>][product_id]" value="<?= (int) $it['product_id'] ?>"><?php endif ?>
        <div class="row g-2">
            <div class="col-md-3"><label class="form-label">Part number</label><input class="form-control form-control-sm part-no" name="items[<?= $i ?>][part_number]" value="<?= esc($it['part_number'] ?? '', 'attr') ?>"></div>
            <div class="col-md-5"><label class="form-label">Description<span class="required-mark" aria-hidden="true">*</span></label><input class="form-control form-control-sm" name="items[<?= $i ?>][description]" value="<?= esc($it['description'] ?? '', 'attr') ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label">Quantity<span class="required-mark" aria-hidden="true">*</span></label><input class="form-control form-control-sm" type="number" min="1" name="items[<?= $i ?>][quantity]" value="<?= esc((string) ($it['quantity'] ?? 1), 'attr') ?>" required></div>
            <div class="col-6 col-md-2"><label class="form-label">Unit</label><input class="form-control form-control-sm" name="items[<?= $i ?>][unit]" value="<?= esc($it['unit'] ?? 'pcs', 'attr') ?>"></div>
            <div class="col-md-3"><label class="form-label">Category</label><select class="form-select form-select-sm" name="items[<?= $i ?>][category_id]"><option value="">—</option><?php foreach ($catOpts as $k => $l): ?><option value="<?= $k ?>"<?= (string) ($it['category_id'] ?? '') === (string) $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
            <div class="col-md-3"><label class="form-label">Brand</label><select class="form-select form-select-sm" name="items[<?= $i ?>][brand_id]"><option value="">— Any / other —</option><?php foreach ($brandOpts as $k => $l): ?><option value="<?= $k ?>"<?= (string) ($it['brand_id'] ?? '') === (string) $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
            <div class="col-md-2"><label class="form-label">Target unit price</label><input class="form-control form-control-sm" inputmode="decimal" name="items[<?= $i ?>][target_unit_price]" value="<?= esc((string) ($it['target_unit_price'] ?? ''), 'attr') ?>" placeholder="Optional"></div>
            <div class="col-md-4"><label class="form-label">Specifications</label><input class="form-control form-control-sm" name="items[<?= $i ?>][specifications]" value="<?= esc($it['specifications'] ?? '', 'attr') ?>" placeholder="Clearance, sealing, material…"></div>
        </div>
        <button type="button" class="btn btn-link btn-sm text-danger px-0 mt-1" data-repeater-remove><i class="bi bi-trash" aria-hidden="true"></i> Remove item</button>
    </div>
    <?php return ob_get_clean();
};
?>
<?= view('components/page_header', ['title' => 'New request for quotation', 'subtitle' => 'BearingCave reviews each RFQ and sends it to verified suppliers that match. Your company identity is not shown to suppliers.', 'breadcrumbs' => ['RFQs' => 'buyer/rfqs', 'New' => null]]) ?>
<form method="post" action="<?= site_url('buyer/rfqs') ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <fieldset class="bc-fieldset"><legend>Request</legend>
                <?= field('title', 'RFQ title', $prefill ? 'Quotation for ' . ($prefill['part_number'] ?? '') : null, ['required' => true, 'placeholder' => 'e.g. Wheel hub bearings for annual requirement']) ?>
            </fieldset>
            <fieldset class="bc-fieldset" data-repeater="items"><legend>Items</legend>
                <div data-repeater-list><?php foreach (array_values($items) as $i => $it) { echo $row($i, (array) $it); } ?></div>
                <template><?= $row('__INDEX__', []) ?></template>
                <button type="button" class="btn btn-light btn-sm mb-3" data-repeater-add><i class="bi bi-plus-lg" aria-hidden="true"></i> Add item</button>
            </fieldset>
            <fieldset class="bc-fieldset"><legend>Delivery</legend>
                <div class="row g-3">
                    <div class="col-md-4"><?= select_field('destination_country', 'Destination country', country_options(), $company['country_code'], ['required' => true, 'class' => '']) ?></div>
                    <div class="col-md-4"><?= select_field('delivery_terms', 'Preferred Incoterm', ['EXW' => 'EXW', 'FCA' => 'FCA', 'FOB' => 'FOB', 'CIF' => 'CIF', 'CPT' => 'CPT', 'DAP' => 'DAP', 'DDP' => 'DDP'], null, ['class' => '', 'placeholder' => 'No preference']) ?></div>
                    <div class="col-md-4"><?= field('required_by', 'Required by', null, ['type' => 'date', 'class' => '']) ?></div>
                    <div class="col-12"><?= textarea_field('delivery_requirements', 'Delivery requirements', null, ['rows' => 2, 'class' => '', 'placeholder' => 'Packaging, labelling, partial shipments…']) ?></div>
                    <div class="col-md-6"><?= check_field('inspection_required', 'I may want inspection before dispatch', false) ?></div>
                    <div class="col-md-6"><?= check_field('logistics_required', 'I need BearingCave to arrange logistics', false) ?></div>
                </div>
            </fieldset>
        </div>
        <div class="col-xl-4">
            <div class="bc-card mb-3"><div class="bc-card-body">
                <?= field('deadline_at', 'Quotation deadline', $deadline, ['type' => 'datetime-local', 'required' => true]) ?>
                <?= select_field('currency', 'Quotation currency', currency_options(), 'INR', ['required' => true]) ?>
                <?= textarea_field('comments', 'Additional comments', null, ['rows' => 3]) ?>
                <div class="mb-3"><label class="form-label" for="att">Drawings / specification (optional)</label><input class="form-control" type="file" id="att" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.csv,.docx"><div class="form-text">Shared only with invited suppliers.</div></div>
                <button class="btn btn-primary w-100" type="submit">Submit RFQ</button>
                <?php if ($limit !== null): ?><p class="small text-muted mt-2 mb-0">Your plan allows <?= (int) $limit ?> open RFQs at a time.</p><?php endif ?>
            </div></div>
        </div>
    </div>
</form>
<?= $this->endSection() ?>

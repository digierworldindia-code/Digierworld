<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Country visibility', 'subtitle' => 'Control which buyer countries can see any of your listings. Per-listing rules apply on top of this.']) ?>
<div class="row"><div class="col-lg-8">
<?php if (! $entitled): ?>
    <div class="bc-card"><?= empty_state('bi-globe2', 'Selected-country visibility is a Verified Supplier feature', 'Verified Suppliers can restrict discounted surplus to selected markets.', '<a class="btn btn-primary btn-sm" href="' . site_url('supplier/membership') . '">View membership</a>') ?></div>
    <?php if ($selected): ?><p class="small text-muted mt-2">Previously saved restrictions (<?= count($selected) ?> countries) remain enforced.</p><?php endif ?>
<?php else: ?>
<div class="bc-card"><div class="bc-card-body">
    <form method="post" action="<?= site_url('supplier/visibility') ?>">
        <?= csrf_field() ?>
        <fieldset class="mb-3"><legend class="form-label">Company-wide rule</legend>
            <?php foreach (['all' => 'Visible to buyers in all countries', 'allow' => 'Visible ONLY to buyers in selected countries', 'deny' => 'Hidden from buyers in selected countries'] as $k => $l): ?>
                <div class="form-check"><input class="form-check-input" type="radio" name="mode" value="<?= $k ?>" id="m<?= $k ?>"<?= $mode === $k ? ' checked' : '' ?>><label class="form-check-label" for="m<?= $k ?>"><?= $l ?></label></div>
            <?php endforeach ?>
        </fieldset>
        <?= select_field('countries[]', 'Countries', country_options(), $selected, ['multiple' => true, 'attrs' => 'size="12"', 'help' => 'Hold Ctrl/Cmd to select multiple countries.']) ?>
        <div class="alert alert-light border small">Restrictions are enforced in search, product pages, APIs, RFQ matching, recommendations, exports and sitemaps. Guests (whose country is unknown) never see restricted listings.</div>
        <button class="btn btn-primary" type="submit">Save visibility</button>
    </form>
</div></div>
<?php endif ?>
</div></div>
<?= $this->endSection() ?>

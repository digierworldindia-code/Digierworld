<?= $this->extend('layouts/site') ?>
<?= $this->section('content') ?>
<?= view('partials/breadcrumbs', ['trail' => $trail]) ?>
<section class="section-tight">
    <div class="container container-narrow prose">
        <h1>Terms of use</h1>

        <div class="alert alert-warning my-4"><strong>Before launch:</strong> have these reviewed by your legal adviser and add your registered entity name, address and jurisdiction.</div>

        <h2 class="mt-5">Product information</h2>
        <p class="text-muted-ink">Specifications, sizes and recommended retail prices on this site are provided in good faith and may change. Your dealer sets the final selling price. Where a specification here differs from the label on the product you received, the label governs.</p>

        <h2 class="mt-5">Warranty</h2>
        <p class="text-muted-ink">The warranty term for each product is stated on its page and runs from the date of sale recorded by your dealer. What the warranty covers, and what it does not, is set out on the <a href="<?= site_url('warranty') ?>">warranty page</a>. A mattress bought from someone other than an appointed <?= esc(brand('shortName')) ?> dealer is not covered.</p>

        <h2 class="mt-5">Acceptable use</h2>
        <p class="text-muted-ink">Do not use this site to submit false information, to attempt to gain access to accounts or systems you are not authorised to use, or to place automated load on the site. The verification service is provided for checking mattresses you own or are considering.</p>

        <h2 class="mt-5">Dealer accounts</h2>
        <p class="text-muted-ink">The dealer portal is for appointed dealers. Credentials are personal and must not be shared. Activity in the portal is logged.</p>
    </div>
</section>
<?= $this->endSection() ?>

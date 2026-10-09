<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?= view('web/pages/_hero', ['eyebrow' => 'About', 'heading' => 'Unlocking value trapped in surplus automotive inventory', 'lead' => 'Manufacturers worldwide hold surplus, obsolete and dead stock that blocks working capital, occupies warehouses and loses value every month. BearingCave exists to connect that inventory with buyers who need genuine branded parts at competitive prices.']) ?>
<div class="container py-5" style="max-width:900px">
    <h2 class="h5">What we focus on</h2>
    <ul class="small"><li><strong>Trust</strong> — structured supplier and buyer verification.</li><li><strong>Confidentiality</strong> — anonymous listings, country restrictions and confidential bidding.</li><li><strong>Genuine product information</strong> — part numbers, specifications and documents, with honest labelling of unverified cross references.</li><li><strong>Global access</strong> — international buyers, multi-currency listings and managed logistics.</li><li><strong>Effective transactions</strong> — RFQs, quotations, inspection and order tracking in one place.</li></ul>
    <a class="btn btn-primary mt-2" href="<?= site_url('contact') ?>">Get in touch</a>
</div>
<?= $this->endSection() ?>

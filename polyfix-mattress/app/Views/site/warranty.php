<?= $this->extend('layouts/site') ?>
<?= $this->section('content') ?>
<?php /** @var array $hero @var array $sections @var array $slots @var array $trail @var array $faqs */ ?>
<?= view('partials/breadcrumbs', ['trail' => $trail]) ?>
<?= view('partials/page_hero', ['hero' => $hero]) ?>
<p class="container text-muted-ink small">This check shows product and warranty information only. It never displays customer details, dealer information or claim history.</p>
<?= view('sections/render', ['sections' => $sections, 'slots' => $slots]) ?>
<section class="section-tight" id="claim">
    <div class="container">
        <div class="alert alert-secondary container-narrow ms-0"><strong>Keep the law label.</strong> It carries the serial number, and it is what makes the warranty verifiable. A mattress without its label cannot be matched to our records.</div>
        <a class="btn btn-outline-dark" href="<?= site_url('dealers') ?>">Find your dealer</a>
    </div>
</section>
<?= view('partials/faq_list', ['faqs' => $faqs, 'heading' => 'Warranty questions']) ?>
<?= $this->endSection() ?>

<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Saved inventory', 'subtitle' => 'Listings you bookmarked. Listings that become unavailable in your market disappear automatically.']) ?>
<?php if (! $items): ?><div class="bc-card"><?= empty_state('bi-bookmark', 'Nothing saved yet', 'Use “Save” on any product page.', '<a class="btn btn-primary btn-sm" href="' . site_url('marketplace') . '">Browse marketplace</a>') ?></div>
<?php else: ?><div class="row g-3"><?php foreach ($items as $p): ?><div class="col-6 col-md-4 col-xl-3"><?= view('components/product_card', ['p' => $p, 'viewer' => $viewer]) ?></div><?php endforeach ?></div><?php endif ?>
<?= $this->endSection() ?>

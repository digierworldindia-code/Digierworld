<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Reports & analytics', 'subtitle' => 'All reports are generated live from stored records, with date filters and authorised CSV/Excel export.']) ?>
<div class="row g-3"><?php foreach ($reports as $k => [$t, $d]): ?><div class="col-md-6 col-xl-4"><a class="category-tile flex-column align-items-start h-100" href="<?= site_url('admin/reports/' . $k) ?>"><span class="fw-semibold text-navy"><i class="bi bi-bar-chart me-1" aria-hidden="true"></i><?= esc($t) ?></span><span class="small text-muted"><?= esc($d) ?></span></a></div><?php endforeach ?></div>
<?= $this->endSection() ?>

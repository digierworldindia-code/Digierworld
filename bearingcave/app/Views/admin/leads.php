<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Contact leads']) ?>
<div class="bc-card"><?php if (! $rows): ?><?= empty_state('bi-inbox', 'No enquiries yet') ?><?php else: ?><div class="list-group list-group-flush">
<?php foreach ($rows as $l): ?><div class="list-group-item"><div class="d-flex justify-content-between flex-wrap gap-2"><div><strong><?= esc($l['name']) ?></strong> <?= $l['company_name'] ? '· ' . esc($l['company_name']) : '' ?> · <a href="mailto:<?= esc($l['email'], 'attr') ?>"><?= esc($l['email']) ?></a> <?= $l['phone'] ? '· ' . esc($l['phone']) : '' ?> · <?= esc(country_name($l['country_code'])) ?><div class="small text-muted"><?= esc(ucfirst($l['enquiry_type'])) ?> · <?= fdt($l['created_at']) ?> · #<?= (int) $l['id'] ?></div></div>
<form method="post" action="<?= site_url('admin/leads/' . $l['id']) ?>" class="d-flex gap-1"><?= csrf_field() ?><select class="form-select form-select-sm" name="status" aria-label="Status"><?php foreach (['new', 'contacted', 'closed'] as $s): ?><option value="<?= $s ?>"<?= $l['status'] === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach ?></select><button class="btn btn-sm btn-light">Save</button></form></div><p class="small mb-0 mt-2" style="white-space:pre-line"><?= esc($l['message']) ?></p></div><?php endforeach ?>
</div><?= $pager->links() ?><?php endif ?></div>
<?= $this->endSection() ?>

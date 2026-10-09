<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => $title, 'actions' => '<a class="btn btn-sm ' . ($type === 'supplier' ? 'btn-primary' : 'btn-light') . '" href="' . site_url('admin/companies?type=supplier') . '">Suppliers</a><a class="btn btn-sm ' . ($type === 'buyer' ? 'btn-primary' : 'btn-light') . '" href="' . site_url('admin/companies?type=buyer') . '">Buyers</a>']) ?>
<form class="row g-2 mb-3" method="get"><input type="hidden" name="type" value="<?= $type ?>">
    <div class="col-md-4"><label class="visually-hidden" for="cq">Search</label><input id="cq" class="form-control form-control-sm" name="q" value="<?= esc((string) service('request')->getGet('q'), 'attr') ?>" placeholder="Name, registration no. or alias"></div>
    <div class="col-md-3"><label class="visually-hidden" for="cv">Verification</label><select id="cv" class="form-select form-select-sm" name="verification_status"><option value="">Any verification</option><?php foreach (['unverified', 'in_review', 'verified', 'rejected', 'suspended'] as $s): ?><option value="<?= $s ?>"<?= service('request')->getGet('verification_status') === $s ? ' selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option><?php endforeach ?></select></div>
    <div class="col-md-3"><label class="visually-hidden" for="cc">Country</label><select id="cc" class="form-select form-select-sm" name="country_code"><option value="">Any country</option><?php foreach (country_options() as $k => $n): ?><option value="<?= $k ?>"<?= service('request')->getGet('country_code') === $k ? ' selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-light w-100">Filter</button></div></form>
<div class="bc-card">
<?php if (! $rows): ?><?= empty_state('bi-building', 'No companies found') ?><?php else: ?>
<div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Company</th><th>Alias</th><th>Country</th><th>Verification</th><th>Plan</th><th>Status</th><th>Registered</th></tr></thead><tbody>
<?php foreach ($rows as $c): ?><tr>
    <td data-label="Company"><a class="fw-semibold" href="<?= site_url('admin/companies/' . $c['id']) ?>"><?= esc($c['legal_name']) ?></a> <?= sample_badge($c) ?></td><td data-label="Alias" class="part-no small"><?= esc($c['public_alias']) ?></td>
    <td data-label="Country"><?= esc(country_name($c['country_code'])) ?></td><td data-label="Verification"><?= status_badge($c['verification_status']) ?></td>
    <td data-label="Plan" class="small"><?= esc(service('entitlements')->plan($c)['name'] ?? '—') ?></td><td data-label="Status"><?= status_badge($c['status']) ?></td><td data-label="Registered" class="small"><?= fdate($c['created_at']) ?></td>
</tr><?php endforeach ?></tbody></table></div><?= $pager->links() ?>
<?php endif ?>
</div>
<?= $this->endSection() ?>

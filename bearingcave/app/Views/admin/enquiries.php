<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Product enquiries', 'subtitle' => 'Enquiries for listings of suppliers without direct enquiries come to BearingCave. Answer the buyer or pass the enquiry to the supplier.']) ?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link<?= $routing === 'platform' ? ' active' : '' ?>" href="<?= site_url('admin/enquiries') ?>">Via BearingCave<?= $openPlatform ? ' <span class="badge bg-danger">' . (int) $openPlatform . '</span>' : '' ?></a></li>
    <li class="nav-item"><a class="nav-link<?= $routing === 'direct' ? ' active' : '' ?>" href="<?= site_url('admin/enquiries?routing=direct') ?>">Direct to supplier (read-only)</a></li>
</ul>
<form class="d-flex gap-2 mb-3" method="get"><input type="hidden" name="routing" value="<?= esc($routing, 'attr') ?>">
    <label class="visually-hidden" for="st">Status</label><select id="st" class="form-select form-select-sm w-auto" name="status"><option value="">All statuses</option><?php foreach (['open', 'responded', 'closed', 'converted'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach ?></select>
    <button class="btn btn-sm btn-light" type="submit">Filter</button></form>
<div class="bc-card">
<?php if (! $rows): ?><?= empty_state('bi-chat-left-text', 'No enquiries here') ?><?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($rows as $e): ?>
        <div class="list-group-item">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div><a class="fw-semibold" href="<?= site_url('product/' . $e['slug']) ?>"><span class="part-no"><?= esc($e['part_number']) ?></span> — <?= esc($e['name']) ?></a>
                    <div class="small text-muted">Buyer: <a href="<?= site_url('admin/companies/' . $e['buyer_company_id']) ?>"><?= esc($e['buyer_name']) ?></a> (<?= esc(country_name($e['buyer_country'])) ?>) · Supplier: <a href="<?= site_url('admin/companies/' . $e['supplier_id']) ?>"><?= esc($e['supplier_name']) ?></a> <?= $e['supplier_verification'] === 'verified' ? '' : '<span class="badge-soft neutral">Unverified</span>' ?> · <?= fdt($e['created_at']) ?><?= $e['quantity'] ? ' · Qty ' . number_format((int) $e['quantity']) : '' ?></div></div>
                <div class="d-flex gap-1 align-items-start"><?= status_badge($e['status']) ?><?= $e['forwarded_at'] ? status_badge('neutral', 'Passed to supplier ' . fdate($e['forwarded_at'])) : '' ?></div>
            </div>
            <div class="chat-msg mt-2 small"><?= esc($e['message']) ?></div>
            <?php if ($e['response']): ?><div class="chat-msg mine mt-2 small"><strong>Reply (<?= fdt($e['responded_at']) ?>):</strong> <?= esc($e['response']) ?></div><?php endif ?>
            <?php if ($routing === 'platform' && $e['status'] === 'open' && auth()->user()->can('enquiries.manage')): ?>
                <form method="post" action="<?= site_url('admin/enquiries/' . $e['id'] . '/respond') ?>" class="mt-2"><?= csrf_field() ?><label class="visually-hidden" for="r<?= $e['id'] ?>">Reply to the buyer</label><textarea id="r<?= $e['id'] ?>" class="form-control form-control-sm mb-2" name="response" rows="2" required placeholder="Answer the buyer as BearingCave (do not reveal a confidential supplier's identity)"></textarea>
                    <div class="d-flex flex-wrap gap-2"><button class="btn btn-sm btn-primary" type="submit">Send reply</button>
                    <?php if (! $e['forwarded_at']): ?><button class="btn btn-sm btn-outline-primary" type="submit" formaction="<?= site_url('admin/enquiries/' . $e['id'] . '/forward') ?>" formnovalidate>Pass to supplier</button><?php endif ?>
                    <button class="btn btn-sm btn-light" type="submit" formaction="<?= site_url('admin/enquiries/' . $e['id'] . '/close') ?>" formnovalidate data-confirm="Close this enquiry without a reply?">Close</button></div></form>
            <?php endif ?>
        </div>
    <?php endforeach ?>
    </div>
<?php endif ?>
</div>
<?= pagination_links($page, $pages, array_filter(service('request')->getGet() ?? [])) ?>
<?= $this->endSection() ?>

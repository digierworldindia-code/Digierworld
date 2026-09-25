<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
use App\Services\PublicFormService;

/** @var array $list @var list<string> $statuses @var array $f */
?>
<div class="page-head"><div><h1>Leads</h1><p>Enquiries from the website contact form.</p></div></div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><option value="">Any</option>
            <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="requirement">About</label><select class="form-select" id="requirement" name="requirement" data-autosubmit><option value="">Anything</option>
            <?php foreach (PublicFormService::REQUIREMENTS as $k => $label): ?><option value="<?= $k ?>"<?= $f['need'] === $k ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach ?></select></div>
    </form>
    <?php if ($list['rows'] === []): ?><div class="table-empty">No leads match.</div><?php endif ?>
    <?php foreach ($list['rows'] as $l): ?>
    <div class="panel-body border-bottom">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div>
                <strong><?= esc($l['name']) ?></strong> <?= pill($l['status']) ?>
                <?= (int) $l['spam_score'] >= 50 ? '<span class="pill pill-caution">Likely spam</span>' : '' ?>
                <div class="small text-muted"><?= esc(PublicFormService::REQUIREMENTS[$l['requirement']] ?? $l['requirement']) ?> ·
                    <a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $l['phone']), 'attr') ?>"><?= esc($l['phone']) ?></a>
                    <?php if ($l['email']): ?> · <a href="mailto:<?= esc($l['email'], 'attr') ?>"><?= esc($l['email']) ?></a><?php endif ?>
                    <?php if ($l['city']): ?> · <?= esc($l['city']) ?><?php endif ?></div>
            </div>
            <div class="small text-muted text-nowrap"><?= esc(local_time($l['created_at'])) ?></div>
        </div>
        <p class="mt-2 mb-2" style="white-space:pre-line"><?= esc($l['message']) ?></p>
        <?php if ($ctx->can('lead:update')): ?>
        <form class="d-flex flex-wrap gap-2 align-items-center" method="post" action="<?= site_url('admin/leads/' . $l['id']) ?>">
            <?= csrf_field() ?>
            <select class="form-select form-select-sm" name="status" style="max-width:10rem" aria-label="Status">
                <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= $l['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?>
            </select>
            <input class="form-control form-control-sm" style="max-width:28rem" name="internal_notes" maxlength="2000" placeholder="Internal note" value="<?= esc($l['internal_notes'] ?? '', 'attr') ?>" aria-label="Internal note">
            <button class="btn btn-light btn-sm" type="submit">Save</button>
            <?php if ($l['assigned_to']): ?><span class="small text-muted">Last handled by <?= esc($l['assigned_to']) ?></span><?php endif ?>
        </form>
        <?php elseif ($l['internal_notes']): ?><p class="small text-muted mb-0"><?= esc($l['internal_notes']) ?></p><?php endif ?>
    </div>
    <?php endforeach ?>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/** @var array $page */
$pretty = static fn (?string $json): string => json_encode(json_decode((string) $json, true) ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/content') ?>">Content</a></div><h1><?= esc($page['title']) ?> <?= pill($page['status']) ?></h1>
    <p>/<?= esc($page['slug'] === 'home' ? '' : $page['slug']) ?> · last edited <?= esc(local_time($page['updated_at'])) ?></p></div>
    <a class="btn btn-light" href="<?= site_url($page['slug'] === 'home' ? '/' : $page['slug']) ?>" target="_blank" rel="noopener">View page</a>
</div>
<form class="panel" method="post" action="<?= site_url('admin/content/pages/' . $page['slug']) ?>">
    <div class="panel-body">
        <?= csrf_field() ?>
        <div class="row g-3 mb-3">
            <div class="col-md-8"><label class="form-label" for="title">Title</label><input class="form-control" id="title" name="title" maxlength="200" required value="<?= esc($page['title'], 'attr') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"<?= $ctx->can('cms:publish') ? '' : ' disabled' ?>>
                <?php foreach (['DRAFT', 'PUBLISHED', 'ARCHIVED'] as $s): ?><option value="<?= $s ?>"<?= $page['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select>
                <?php if (! $ctx->can('cms:publish')): ?><input type="hidden" name="status" value="<?= esc($page['status'], 'attr') ?>"><div class="form-text">Publishing needs cms:publish.</div><?php endif ?></div>
        </div>
        <div class="mb-3"><label class="form-label" for="hero">Hero</label>
            <textarea class="form-control mono" id="hero" name="hero" rows="10" spellcheck="false"><?= esc($pretty($page['hero'])) ?></textarea>
            <div class="form-text">Keys the website understands: eyebrow, heading, body, image {src, alt, width, height}, primaryCta/secondaryCta/tertiaryCta {label, href}.</div></div>
        <div class="mb-3"><label class="form-label" for="sections">Sections</label>
            <textarea class="form-control mono" id="sections" name="sections" rows="24" spellcheck="false"><?= esc($pretty($page['sections'])) ?></textarea>
            <div class="form-text">A list of blocks, each with a <span class="mono">type</span>. Available types: benefits, featured-products, craftsmanship, verification, dealers, closing-cta, text, values, reasons, steps, verify-form, contact-form, contact-details, dealer-locator, become-dealer. A type with no renderer is refused on save.</div></div>
        <button class="btn btn-primary" type="submit">Save page</button>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/** @var array $p @var array|null $website */
$pretty = static fn (?string $json): string => json_encode(json_decode((string) $json, true) ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/content') ?>">Content</a></div><h1><?= esc($p['name']) ?></h1>
    <p>Website copy. The specification itself lives with the product record.</p></div>
    <a class="btn btn-light" href="<?= site_url('mattresses/' . $p['slug']) ?>" target="_blank" rel="noopener">View page</a>
</div>
<form class="panel" method="post" action="<?= site_url('admin/content/products/' . $p['id']) ?>">
    <div class="panel-body">
        <?= csrf_field() ?>
        <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label" for="headline">Headline</label><input class="form-control" id="headline" name="headline" maxlength="200" value="<?= esc($website['headline'] ?? '', 'attr') ?>"></div>
            <div class="col-md-6"><label class="form-label" for="subheadline">Subheadline</label><input class="form-control" id="subheadline" name="subheadline" maxlength="300" value="<?= esc($website['subheadline'] ?? '', 'attr') ?>"></div>
        </div>
        <div class="mb-3"><label class="form-label" for="gallery">Gallery</label>
            <textarea class="form-control mono" id="gallery" name="gallery" rows="10" spellcheck="false"><?= esc($pretty($website['gallery'] ?? '[]')) ?></textarea>
            <div class="form-text">A list of {src, alt, width, height}. Paths must sit under /images. Alt text is what a screen reader announces, so describe the picture.</div></div>
        <div class="mb-3"><label class="form-label" for="highlights">Highlights</label>
            <textarea class="form-control mono" id="highlights" name="highlights" rows="10" spellcheck="false"><?= esc($pretty($website['highlights'] ?? '[]')) ?></textarea>
            <div class="form-text">A list of {title, body}, shown as cards under "Why this one".</div></div>
        <div class="mb-3"><label class="form-label" for="body_html">Long copy</label>
            <textarea class="form-control" id="body_html" name="body_html" rows="6" maxlength="20000"><?= esc($website['body_html'] ?? '') ?></textarea></div>
        <div class="form-check mb-3"><input type="hidden" name="is_published" value="0">
            <input class="form-check-input" type="checkbox" id="is_published" name="is_published" value="1"<?= ! empty($website['is_published']) ? ' checked' : '' ?>>
            <label class="form-check-label" for="is_published">Show this product on the website</label></div>
        <button class="btn btn-primary" type="submit">Save copy</button>
    </div>
</form>
<?= $this->endSection() ?>

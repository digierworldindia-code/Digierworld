<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var list<array> $rows @var bool $indexing @var bool $sitemap */ ?>
<div class="page-head"><div><h1>SEO</h1><p>Titles, descriptions and indexing for each page. Saved here, live on the next request.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-light" href="<?= site_url('sitemap.xml') ?>" target="_blank" rel="noopener">sitemap.xml</a>
    <a class="btn btn-light" href="<?= site_url('robots.txt') ?>" target="_blank" rel="noopener">robots.txt</a></div></div>

<?php if (! $indexing): ?>
<div class="alert alert-warning">Indexing is switched off site-wide (<span class="mono">seo.robots_allow_indexing</span>), so every page sends <span class="mono">noindex</span> and robots.txt disallows everything. Turn it on in <a href="<?= site_url('admin/settings') ?>">Settings</a> when the site goes live.</div>
<?php endif ?>
<?php if (! $sitemap): ?><div class="alert alert-warning">The sitemap is switched off in Settings, so /sitemap.xml answers 404.</div><?php endif ?>

<?php foreach ($rows as $r): ?>
<div class="panel">
    <div class="panel-head">
        <h2><?= esc($r['scope']) ?> · <?= esc($r['entity_key']) ?></h2>
        <div class="d-flex gap-2 align-items-center">
            <?php if ($r['path']): ?><a class="small" href="<?= site_url(ltrim($r['path'], '/')) ?>" target="_blank" rel="noopener"><?= esc($r['path']) ?></a><?php endif ?>
            <?= $r['robots_index'] ? '<span class="pill pill-positive">Indexed</span>' : '<span class="pill pill-caution">noindex</span>' ?>
        </div>
    </div>
    <form class="panel-body" method="post" action="<?= site_url('admin/seo/' . $r['id']) ?>">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label" for="title-<?= $r['id'] ?>">Title</label>
                <input class="form-control" id="title-<?= $r['id'] ?>" name="title" maxlength="200" required value="<?= esc($r['title'], 'attr') ?>">
                <div class="form-text"><?= mb_strlen((string) $r['title']) ?> characters — search results show roughly 60.</div></div>
            <div class="col-md-4"><label class="form-label" for="canonical-<?= $r['id'] ?>">Canonical path</label>
                <input class="form-control" id="canonical-<?= $r['id'] ?>" name="canonical_path" maxlength="300" value="<?= esc($r['canonical_path'] ?? '', 'attr') ?>" placeholder="<?= esc($r['path'] ?? '/', 'attr') ?>"></div>
            <div class="col-12"><label class="form-label" for="description-<?= $r['id'] ?>">Description</label>
                <textarea class="form-control" id="description-<?= $r['id'] ?>" name="description" rows="2" maxlength="400" required><?= esc($r['description']) ?></textarea>
                <div class="form-text"><?= mb_strlen((string) $r['description']) ?> characters — roughly 155 are shown.</div></div>
            <div class="col-md-6"><label class="form-label" for="ogtitle-<?= $r['id'] ?>">Social title</label>
                <input class="form-control" id="ogtitle-<?= $r['id'] ?>" name="og_title" maxlength="200" value="<?= esc($r['og_title'] ?? '', 'attr') ?>"></div>
            <div class="col-md-6"><label class="form-label" for="ogimage-<?= $r['id'] ?>">Social image</label>
                <input class="form-control" id="ogimage-<?= $r['id'] ?>" name="og_image_url" maxlength="500" value="<?= esc($r['og_image_url'] ?? '', 'attr') ?>" placeholder="/images/og/…"></div>
            <div class="col-12"><label class="form-label" for="ogdesc-<?= $r['id'] ?>">Social description</label>
                <input class="form-control" id="ogdesc-<?= $r['id'] ?>" name="og_description" maxlength="400" value="<?= esc($r['og_description'] ?? '', 'attr') ?>"></div>
            <div class="col-md-6"><label class="form-label" for="keywords-<?= $r['id'] ?>">Keywords</label>
                <input class="form-control" id="keywords-<?= $r['id'] ?>" name="keywords" maxlength="500" value="<?= esc($r['keywords_text'], 'attr') ?>">
                <div class="form-text">Comma separated. Search engines largely ignore these; they cost nothing to keep.</div></div>
            <div class="col-md-3"><label class="form-label" for="freq-<?= $r['id'] ?>">Change frequency</label>
                <select class="form-select" id="freq-<?= $r['id'] ?>" name="sitemap_changefreq">
                    <?php foreach (['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'] as $f): ?>
                    <option<?= $r['sitemap_changefreq'] === $f ? ' selected' : '' ?>><?= $f ?></option><?php endforeach ?></select></div>
            <div class="col-md-3"><label class="form-label" for="priority-<?= $r['id'] ?>">Sitemap priority</label>
                <input class="form-control" id="priority-<?= $r['id'] ?>" type="number" step="0.1" min="0" max="1" name="sitemap_priority" value="<?= esc($r['sitemap_priority'], 'attr') ?>"></div>
            <div class="col-12 d-flex flex-wrap gap-3">
                <div class="form-check"><input type="hidden" name="robots_index" value="0"><input class="form-check-input" type="checkbox" id="index-<?= $r['id'] ?>" name="robots_index" value="1"<?= $r['robots_index'] ? ' checked' : '' ?>><label class="form-check-label" for="index-<?= $r['id'] ?>">Allow indexing</label></div>
                <div class="form-check"><input type="hidden" name="robots_follow" value="0"><input class="form-check-input" type="checkbox" id="follow-<?= $r['id'] ?>" name="robots_follow" value="1"<?= $r['robots_follow'] ? ' checked' : '' ?>><label class="form-check-label" for="follow-<?= $r['id'] ?>">Follow links</label></div>
                <div class="form-check"><input type="hidden" name="sitemap_include" value="0"><input class="form-check-input" type="checkbox" id="sm-<?= $r['id'] ?>" name="sitemap_include" value="1"<?= $r['sitemap_include'] ? ' checked' : '' ?>><label class="form-check-label" for="sm-<?= $r['id'] ?>">Include in sitemap</label></div>
            </div>
        </div>
        <?php if ($ctx->can('seo:write')): ?><button class="btn btn-primary mt-3" type="submit">Save</button><?php endif ?>
    </form>
</div>
<?php endforeach ?>
<?= $this->endSection() ?>

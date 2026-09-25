<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var list<array> $pages @var list<array> $products @var list<array> $faqs */ ?>
<div class="page-head"><div><h1>Website content</h1><p>Pages, product copy and FAQs. Changes appear on the public site immediately.</p></div>
    <a class="btn btn-light" href="<?= site_url('/') ?>" target="_blank" rel="noopener">View website</a></div>

<div class="row g-3">
    <div class="col-xl-6"><div class="panel h-100">
        <div class="panel-head"><h2>Pages</h2></div>
        <table class="table"><tbody>
        <?php foreach ($pages as $p): ?>
            <tr><td><a href="<?= site_url('admin/content/pages/' . $p['slug']) ?>"><?= esc($p['title']) ?></a>
                <div class="small text-muted">/<?= esc($p['slug'] === 'home' ? '' : $p['slug']) ?></div></td>
                <td><?= pill($p['status']) ?></td><td class="small text-muted text-nowrap"><?= esc(local_time($p['updated_at'], 'd M Y')) ?></td></tr>
        <?php endforeach ?>
        </tbody></table>
    </div></div>

    <div class="col-xl-6"><div class="panel h-100">
        <div class="panel-head"><h2>Product copy</h2></div>
        <table class="table"><tbody>
        <?php foreach ($products as $p): ?>
            <tr><td><a href="<?= site_url('admin/content/products/' . $p['id']) ?>"><?= esc($p['name']) ?></a>
                <div class="small text-muted"><?= esc($p['headline'] ?? 'No website headline yet') ?></div></td>
                <td><?= pill($p['status']) ?></td>
                <td><?= $p['is_published'] ? '<span class="pill pill-positive">On site</span>' : '<span class="pill">Hidden</span>' ?></td></tr>
        <?php endforeach ?>
        </tbody></table>
    </div></div>
</div>

<div class="panel mt-3" id="faqs">
    <div class="panel-head"><h2>FAQs</h2><span class="small text-muted"><?= count($faqs) ?> question(s)</span></div>
    <?php if ($ctx->can('cms:write')): ?>
    <form class="panel-body border-bottom" method="post" action="<?= site_url('admin/content/faqs') ?>">
        <?= csrf_field() ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label" for="question">New question</label><input class="form-control" id="question" name="question" maxlength="300" required></div>
            <div class="col-md-4"><label class="form-label" for="answer">Answer</label><input class="form-control" id="answer" name="answer" maxlength="4000" required></div>
            <div class="col-md-2"><label class="form-label" for="category">Category</label>
                <input class="form-control" id="category" name="category" list="faq-categories" maxlength="40" value="buying">
                <datalist id="faq-categories"><?php foreach (array_unique(array_column($faqs, 'category')) as $c): ?><option value="<?= esc($c, 'attr') ?>"><?php endforeach ?></datalist></div>
            <div class="col-md-2"><input type="hidden" name="is_published" value="1"><button class="btn btn-primary w-100" type="submit">Add</button></div>
        </div>
    </form>
    <?php endif ?>
    <table class="table"><tbody>
    <?php foreach ($faqs as $f): ?>
        <tr>
            <td>
                <?php if ($ctx->can('cms:write')): ?>
                <form class="row g-2 align-items-center" method="post" action="<?= site_url('admin/content/faqs') ?>">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $f['id'] ?>">
                    <div class="col-md-4"><input class="form-control form-control-sm" name="question" value="<?= esc($f['question'], 'attr') ?>" maxlength="300" aria-label="Question"></div>
                    <div class="col-md-4"><textarea class="form-control form-control-sm" name="answer" rows="2" maxlength="4000" aria-label="Answer"><?= esc($f['answer']) ?></textarea></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="category" value="<?= esc($f['category'], 'attr') ?>" maxlength="40" aria-label="Category"></div>
                    <div class="col-md-1"><input class="form-control form-control-sm" type="number" name="sort_order" value="<?= (int) $f['sort_order'] ?>" aria-label="Order"></div>
                    <div class="col-md-1 d-flex gap-1 align-items-center">
                        <input type="hidden" name="is_published" value="0">
                        <input class="form-check-input mt-0" type="checkbox" name="is_published" value="1"<?= $f['is_published'] ? ' checked' : '' ?> aria-label="Published">
                        <button class="btn btn-light btn-sm" type="submit">Save</button>
                    </div>
                </form>
                <?php else: ?>
                <strong><?= esc($f['question']) ?></strong><div class="small text-muted"><?= esc($f['answer']) ?></div>
                <?php endif ?>
                <?php if ($f['product']): ?><div class="small text-muted mt-1">Shown on <?= esc($f['product']) ?></div><?php endif ?>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody></table>
</div>
<?= $this->endSection() ?>

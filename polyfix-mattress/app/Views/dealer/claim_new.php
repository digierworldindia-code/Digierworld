<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php /** @var string $serial @var list<array> $sold @var array<string,string> $categories */ ?>
<div class="small"><a href="<?= site_url('dealer/claims') ?>">Claims</a></div>
<h1 class="h4 mb-1">Raise a warranty claim</h1>
<p class="small text-muted">Photographs of the problem and of the law label make a claim far quicker to decide.</p>

<?php if ($sold === []): ?>
<div class="alert alert-secondary">A claim is raised against a mattress you have sold. None are recorded yet.</div>
<?php else: ?>
<form method="post" action="<?= site_url('dealer/claims') ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="panel mb-3">
        <div class="panel-body">
            <label class="form-label" for="serial">Mattress serial</label>
            <input class="form-control form-control-lg mono text-uppercase<?= invalid('serial') ?>" id="serial" name="serial" list="sold-serials" required
                   maxlength="20" autocapitalize="characters" autocomplete="off" value="<?= esc(old('serial', $serial), 'attr') ?>">
            <datalist id="sold-serials">
                <?php foreach ($sold as $s): ?><option value="<?= esc($s['serial_number'], 'attr') ?>"><?= esc($s['product'] . ' — sold ' . local_date($s['sold_at'])) ?></option><?php endforeach ?>
            </datalist>
            <?= field_error('serial') ?>
        </div>
    </div>

    <div class="panel mb-3">
        <div class="panel-body row g-3">
            <div class="col-12"><label class="form-label" for="issue_category">What is wrong</label>
                <select class="form-select" id="issue_category" name="issue_category" required>
                    <?php foreach ($categories as $k => $label): ?><option value="<?= $k ?>"<?= old('issue_category') === $k ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach ?>
                </select></div>
            <div class="col-12"><label class="form-label" for="reported_issue">In one line</label>
                <input class="form-control<?= invalid('reported_issue') ?>" id="reported_issue" name="reported_issue" required minlength="5" maxlength="200" value="<?= esc(old('reported_issue', ''), 'attr') ?>" placeholder="Dip on the left side, about 4 cm"><?= field_error('reported_issue') ?></div>
            <div class="col-12"><label class="form-label" for="description">What the customer says</label>
                <textarea class="form-control<?= invalid('description') ?>" id="description" name="description" rows="5" required minlength="20" maxlength="4000"><?= esc(old('description', '')) ?></textarea>
                <div class="form-text">When it started, how the mattress is used, what base it sits on.</div><?= field_error('description') ?></div>
            <div class="col-12"><label class="form-label" for="files">Photographs</label>
                <input class="form-control" type="file" id="files" name="files[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,video/quicktime" capture="environment">
                <div class="form-text">Up to 8 files. JPEG, PNG or WebP up to 8 MB; a short video up to 25 MB. Include the law label.</div></div>
        </div>
    </div>

    <button class="btn btn-primary btn-lg w-100" type="submit">Raise the claim</button>
</form>
<?php endif ?>
<?= $this->endSection() ?>

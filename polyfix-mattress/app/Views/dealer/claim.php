<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php
use App\Services\ClaimService;

/** @var array $c @var list<array> $events @var list<array> $media @var bool $closed */
?>
<div class="small"><a href="<?= site_url('dealer/claims') ?>">Claims</a></div>
<h1 class="h4 mb-1"><?= esc($c['claim_number']) ?> <?= pill($c['status']) ?></h1>
<p class="small text-muted"><?= esc(ClaimService::ISSUE_CATEGORIES[$c['issue_category']] ?? $c['issue_category']) ?> · raised <?= esc(local_date($c['submitted_at'])) ?></p>

<?php if ($c['status'] === 'INFO_REQUESTED'): ?>
<div class="alert alert-warning py-2">The warranty team has asked you for more information. Reply below.</div>
<?php endif ?>

<div class="panel mb-3">
    <div class="panel-body">
        <dl class="dl-grid">
            <dt>Mattress</dt><dd class="mono"><?= esc($c['serial_number']) ?></dd>
            <dt>Product</dt><dd><?= esc($c['product']) ?>, <?= esc($c['size_label']) ?></dd>
            <dt>Customer</dt><dd><?= esc($c['customer'] ?? '—') ?></dd>
            <?php if ($c['end_date']): ?><dt>Warranty</dt><dd><?= pill($c['warranty_status']) ?> to <?= esc(local_date($c['end_date'])) ?></dd><?php endif ?>
        </dl>
        <hr>
        <p class="fw-semibold mb-1"><?= esc($c['reported_issue']) ?></p>
        <p class="mb-0" style="white-space:pre-line"><?= esc($c['description']) ?></p>
        <?php if ($c['decision_reason']): ?>
        <hr><p class="mb-0"><strong>Decision:</strong> <?= esc($c['decision_reason']) ?>
        <?= $c['resolution'] ? '<br><strong>Resolution:</strong> ' . esc(ClaimService::RESOLUTIONS[$c['resolution']] ?? $c['resolution']) : '' ?></p>
        <?php endif ?>
    </div>
</div>

<div class="panel mb-3">
    <div class="panel-head"><h2>Photographs</h2><span class="small text-muted"><?= count($media) ?></span></div>
    <div class="panel-body">
        <?php if ($media === []): ?><p class="small text-muted mb-0">None attached.</p><?php else: ?>
        <div class="media-grid">
        <?php foreach ($media as $f): ?>
            <?php $url = site_url('media/' . $f['id']); ?>
            <?php if (str_starts_with($f['mime_type'], 'image/')): ?>
                <a href="<?= $url ?>" target="_blank" rel="noopener"><img src="<?= $url ?>" alt="<?= esc($f['caption'] ?? humanise($f['kind']), 'attr') ?>" loading="lazy"></a>
            <?php elseif (str_starts_with($f['mime_type'], 'video/')): ?>
                <div class="tile"><video src="<?= $url ?>" controls preload="metadata"></video></div>
            <?php else: ?>
                <a class="d-grid text-center p-2" href="<?= $url ?>" target="_blank" rel="noopener"><span><i class="bi bi-file-earmark-pdf fs-2"></i><br><span class="small"><?= esc(humanise($f['kind'])) ?></span></span></a>
            <?php endif ?>
        <?php endforeach ?>
        </div>
        <?php endif ?>
        <?php if (! $closed): ?>
        <form class="mt-3" method="post" enctype="multipart/form-data" action="<?= site_url('dealer/claims/' . $c['id'] . '/media') ?>">
            <?= csrf_field() ?>
            <input class="form-control form-control-sm" type="file" name="files[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,video/quicktime" capture="environment" aria-label="Add photographs">
            <button class="btn btn-light btn-sm mt-2" type="submit">Add photographs</button>
        </form>
        <?php endif ?>
    </div>
</div>

<div class="panel mb-3">
    <div class="panel-head"><h2>What has happened</h2></div>
    <div class="panel-body">
        <ol class="timeline">
        <?php foreach ($events as $e): ?>
            <li><div class="fw-semibold"><?= esc(humanise($e['event_type'])) ?><?= $e['to_status'] && $e['to_status'] !== $e['from_status'] ? ' → ' . pill($e['to_status']) : '' ?></div>
                <div class="when"><?= esc(local_time($e['created_at'])) ?></div>
                <?php if ($e['note']): ?><div class="small mt-1" style="white-space:pre-line"><?= esc($e['note']) ?></div><?php endif ?></li>
        <?php endforeach ?>
        </ol>
    </div>
</div>

<?php if (! $closed): ?>
<form class="panel" method="post" action="<?= site_url('dealer/claims/' . $c['id'] . '/reply') ?>">
    <div class="panel-head"><h2>Reply</h2></div>
    <div class="panel-body"><?= csrf_field() ?>
        <textarea class="form-control" name="message" rows="3" required minlength="3" maxlength="2000" aria-label="Your reply"></textarea>
        <button class="btn btn-primary mt-2" type="submit">Send reply</button>
    </div>
</form>
<?php endif ?>
<?= $this->endSection() ?>

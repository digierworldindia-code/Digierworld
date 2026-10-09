<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<div class="container py-5" style="max-width:860px">
    <h1 class="h2"><?= esc($page['title']) ?></h1>
    <?php if ($page['approval_status'] !== 'approved'): ?>
        <div class="alert alert-warning small"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i><strong>Draft — pending legal approval.</strong> This text is a placeholder outline and is not yet a binding policy.</div>
    <?php endif ?>
    <div class="cms-body"><?= $page['body'] /* authored by authorised admins; sanitised on save */ ?></div>
    <p class="small text-muted mt-4">Last updated <?= fdate($page['updated_at']) ?></p>
</div>
<?= $this->endSection() ?>

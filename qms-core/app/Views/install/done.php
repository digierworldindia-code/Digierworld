<?php
/**
 * @var list<string> $log
 * @var string       $loginUrl
 * @var string       $username
 */
?>
<?= $this->extend('install/_layout') ?>
<?= $this->section('content') ?>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h4 text-success"><?= qms_icon('bi-check-circle-fill') ?> Setup complete</h2>
        <ul class="small text-muted">
            <?php foreach ($log as $line): ?><li><?= esc($line) ?></li><?php endforeach ?>
        </ul>
        <div class="alert alert-warning">
            <?= qms_icon('bi-shield-lock') ?> The setup page is now switched off (storage/installed.lock).
            Keep the <code>.env</code> file private and set up the cron jobs listed in README.md
            (Google Sheets sync every minute, housekeeping daily).
        </div>
        <a class="btn btn-primary btn-lg" href="<?= esc($loginUrl, 'attr') ?>"><?= qms_icon('bi-box-arrow-in-right') ?> Sign in as <?= esc($username) ?></a>
    </div>
</div>
<?= $this->endSection() ?>

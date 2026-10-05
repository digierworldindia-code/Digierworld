<?php defined('QMS') || exit; require QMS_ROOT . '/includes/views/install_head.php'; ?>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h4 text-success"><?= qms_icon('bi-check-circle-fill') ?> Setup complete</h2>
        <ul class="small text-muted">
            <?php foreach ($log as $line): ?><li><?= e($line) ?></li><?php endforeach ?>
        </ul>
        <div class="alert alert-warning">
            <?= qms_icon('bi-shield-lock') ?> The setup page is now switched off. Keep <code>config/config.php</code> private and add the
            two cron jobs from README.md (Google Sheets sync every minute, housekeeping once a day).
        </div>
        <a class="btn btn-primary btn-lg" href="<?= e(rtrim($values['base_url'], '/') . '/login.php') ?>"><?= qms_icon('bi-box-arrow-in-right') ?> Sign in as <?= e($values['admin_username']) ?></a>
    </div>
</div>
</main>
</body>
</html>

<?php foreach (['success' => ['success', 'bi-check-circle'], 'error' => ['danger', 'bi-exclamation-octagon'], 'warning' => ['warning', 'bi-exclamation-triangle'], 'info' => ['info', 'bi-info-circle'], 'message' => ['info', 'bi-info-circle']] as $key => [$tone, $icon]): ?>
    <?php if ($msg = session()->getFlashdata($key)): ?>
        <div class="alert alert-<?= $tone ?> alert-dismissible d-flex gap-2 align-items-start" role="<?= $tone === 'danger' ? 'alert' : 'status' ?>">
            <i class="bi <?= $icon ?> mt-1" aria-hidden="true"></i>
            <div><?= esc(is_array($msg) ? implode(' ', $msg) : $msg) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif ?>
<?php endforeach ?>
<?php if (session('errors') && is_array(session('errors'))): ?>
    <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach (session('errors') as $e): ?><li><?= esc($e) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

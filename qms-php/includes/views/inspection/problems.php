<?php
/**
 * Items that block submission (re-rendered by inspection-form.js after each save). Variable: $problems.
 */
defined('QMS') || exit;
?>
<div data-problems>
    <?php if ($problems === []): ?>
        <div class="alert alert-success mb-2"><?= qms_icon('bi-check-circle-fill') ?> Everything required is filled in. The report is ready to submit.</div>
    <?php else: ?>
        <div class="alert alert-warning mb-2"><strong><?= qms_icon('bi-list-check') ?> Before you can submit:</strong>
            <ul class="mb-0"><?php foreach ($problems as $problem): ?><li><?= e($problem) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>
</div>

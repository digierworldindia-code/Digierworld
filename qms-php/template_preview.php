<?php
/**
 * Read-only preview of what the inspector fills in.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('template.view');
require_once QMS_ROOT . '/includes/templates.php';

$template  = template_find(get_int('id'));
$structure = template_structure((int) $template['id']);

$page_title = 'Preview';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Preview · <?= e($template['template_code']) ?> v<?= (int) $template['version'] ?></h1>
        <p class="qms-sub"><?= e($template['name']) ?> · what the inspector fills in (read-only).</p></div>
    <a class="btn btn-outline-secondary" href="<?= url('template.php?id=' . (int) $template['id']) ?>"><?= qms_icon('bi-arrow-left') ?> Back to template</a>
</div>
<?php foreach ($structure as $section): ?>
    <h2 class="h5 mt-3"><?= e($section['title']) ?></h2>
    <?php foreach ($section['parameters'] as $p): $choices = obs_choices($p['observation_type']); ?>
        <div class="qms-param">
            <div class="p-title"><?= e($p['name']) ?></div>
            <div class="p-spec">
                <?php if ($p['specification_text'] !== null): ?><span><?= e($p['specification_text']) ?></span><?php endif ?>
                <?php if ($p['lsl'] !== null): ?><span>LSL <b><?= e(qms_decimal($p['lsl'], (int) $p['decimal_places'])) ?></b></span><?php endif ?>
                <?php if ($p['usl'] !== null): ?><span>USL <b><?= e(qms_decimal($p['usl'], (int) $p['decimal_places'])) ?></b></span><?php endif ?>
                <?php if ($p['unit_symbol'] !== null): ?><span><?= e($p['unit_symbol']) ?></span><?php endif ?>
                <?php if ($p['method_label'] !== null): ?><span><?= e($p['method_label']) ?></span><?php endif ?>
                <?php if ($p['gauge_type_name'] !== null): ?><span><?= e($p['gauge_type_name']) ?><?= (int) $p['gauge_required'] === 1 ? ' (Gauge ID required)' : '' ?></span><?php endif ?>
            </div>
            <div class="qms-readings">
                <?php for ($n = 1; $n <= (int) $p['observation_count']; $n++): ?>
                    <div>
                        <div class="qms-reading-label">Observation <?= $n ?></div>
                        <?php if ($choices !== []): ?>
                            <div class="qms-choice"><?php foreach ($choices as $label): ?><button class="btn btn-outline-secondary" type="button" disabled><?= e($label) ?></button><?php endforeach ?></div>
                        <?php else: ?>
                            <input class="form-control qms-reading" disabled placeholder="<?= e(OBSERVATION_TYPES[$p['observation_type']] ?? '') ?>">
                        <?php endif ?>
                    </div>
                <?php endfor ?>
            </div>
        </div>
    <?php endforeach ?>
<?php endforeach ?>
<?php if ($structure === []): ?><div class="qms-empty card"><?= qms_icon('bi-ui-checks-grid') ?>This template has no sections.</div><?php endif ?>
<?php require QMS_ROOT . '/includes/layout/footer.php';

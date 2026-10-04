<?php
/**
 * @var array<string, mixed> $template
 * @var list<array<string, mixed>> $structure
 */
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Preview · <?= esc($template['template_code']) ?> v<?= (int) $template['version'] ?></h1>
        <p class="qms-sub"><?= esc($template['name']) ?> · what the inspector fills in (read-only).</p></div>
    <a class="btn btn-outline-secondary" href="<?= site_url('templates/' . $template['id']) ?>"><?= qms_icon('bi-arrow-left') ?> Back to template</a>
</div>
<?php foreach ($structure as $section): ?>
    <h2 class="h5 mt-3"><?= esc($section['title']) ?></h2>
    <?php foreach ($section['parameters'] as $p):
        $type = \App\Enums\ObservationType::from($p['observation_type']); ?>
        <div class="qms-param">
            <div class="p-title"><?= esc($p['name']) ?></div>
            <div class="p-spec">
                <?php if ($p['specification_text'] !== null): ?><span><?= esc($p['specification_text']) ?></span><?php endif ?>
                <?php if ($p['lsl'] !== null): ?><span>LSL <b><?= esc(qms_decimal($p['lsl'], (int) $p['decimal_places'])) ?></b></span><?php endif ?>
                <?php if ($p['usl'] !== null): ?><span>USL <b><?= esc(qms_decimal($p['usl'], (int) $p['decimal_places'])) ?></b></span><?php endif ?>
                <?php if ($p['unit_symbol'] !== null): ?><span><?= esc($p['unit_symbol']) ?></span><?php endif ?>
                <?php if ($p['method_label'] !== null): ?><span><?= esc($p['method_label']) ?></span><?php endif ?>
                <?php if ($p['gauge_type_name'] !== null): ?><span><?= esc($p['gauge_type_name']) ?><?= (int) $p['gauge_required'] === 1 ? ' (Gauge ID required)' : '' ?></span><?php endif ?>
            </div>
            <div class="qms-readings">
                <?php for ($n = 1; $n <= (int) $p['observation_count']; $n++): ?>
                    <div>
                        <div class="qms-reading-label">Observation <?= $n ?></div>
                        <?php if ($type->choices() !== []): ?>
                            <div class="qms-choice"><?php foreach ($type->choices() as $label): ?><button class="btn btn-outline-secondary" type="button" disabled><?= esc($label) ?></button><?php endforeach ?></div>
                        <?php else: ?>
                            <input class="form-control qms-reading" disabled placeholder="<?= esc($type->label(), 'attr') ?>">
                        <?php endif ?>
                    </div>
                <?php endfor ?>
            </div>
        </div>
    <?php endforeach ?>
<?php endforeach ?>
<?= $this->endSection() ?>

<?php
/**
 * Report catalogue.
 *
 * @var array<string, array<string, mixed>> $catalogue
 */
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Reports</h1><p class="qms-sub">Management reports from the inspection records. Every report can be printed and exported to CSV.</p></div>
</div>
<div class="qms-tiles">
    <?php foreach ($catalogue as $slug => $def): ?>
        <a class="qms-tile" href="<?= site_url('reports/' . $slug) ?>">
            <?= qms_icon($def['icon']) ?>
            <strong><?= esc($def['title']) ?></strong>
            <span><?= esc($def['description']) ?></span>
        </a>
    <?php endforeach ?>
</div>
<?= $this->endSection() ?>

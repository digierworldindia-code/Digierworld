<?php
/**
 * Report catalogue.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('report.view');
require_once QMS_ROOT . '/includes/reports.php';

$page_title = 'Reports';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Reports</h1><p class="qms-sub">Management reports from the inspection records. Every report can be printed and exported to CSV.</p></div>
</div>
<div class="qms-tiles">
    <?php foreach (REPORT_CATALOGUE as $name => $def): ?>
        <a class="qms-tile" href="<?= url('report.php?name=' . urlencode($name)) ?>">
            <?= qms_icon($def['icon']) ?>
            <strong><?= e($def['title']) ?></strong>
            <span><?= e($def['description']) ?></span>
        </a>
    <?php endforeach ?>
</div>
<?php require QMS_ROOT . '/includes/layout/footer.php';

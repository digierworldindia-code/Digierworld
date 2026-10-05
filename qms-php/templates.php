<?php
/**
 * Inspection template list.
 */
require __DIR__ . '/includes/init.php';
$user = require_permission('template.view');
require_once QMS_ROOT . '/includes/templates.php';

$filters     = ['type' => get('type'), 'status' => get('status'), 'q' => get('q')];
$templates   = template_list($filters);
$reportTypes = template_report_types();

$page_title = 'Inspection templates';
require QMS_ROOT . '/includes/layout/header.php';
?>
<div class="qms-page-head">
    <div><h1>Inspection templates</h1><p class="qms-sub">Sections, parameters and specifications per report type. Published versions are locked; changes go into a new version.</p></div>
    <?php if (can('template.manage')): ?><a class="btn btn-primary btn-lg" href="<?= url('template_new.php') ?>"><?= qms_icon('bi-plus-lg') ?> New template</a><?php endif ?>
</div>
<form class="qms-filter row g-2 align-items-end" method="get" action="<?= url('templates.php') ?>">
    <div class="col-md-4"><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Code or name"></div>
    <div class="col-md-3"><label class="form-label" for="type">Report type</label>
        <select class="form-select" id="type" name="type"><option value="">All</option>
            <?php foreach ($reportTypes as $rt): ?><option value="<?= (int) $rt['id'] ?>"<?= $filters['type'] === (string) $rt['id'] ? ' selected' : '' ?>><?= e($rt['name']) ?></option><?php endforeach ?>
        </select></div>
    <div class="col-md-3"><label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <?php foreach (['' => 'Draft + published', 'DRAFT' => 'Draft', 'PUBLISHED' => 'Published', 'RETIRED' => 'Retired'] as $k => $v): ?>
                <option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $v ?></option>
            <?php endforeach ?></select></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>Template</th><th>Report type</th><th>Status</th><th>Format</th><th class="text-end">Parameters</th><th>Applies to</th><th></th></tr></thead>
    <tbody>
    <?php if ($templates === []): ?><tr><td colspan="7" class="qms-empty"><?= qms_icon('bi-ui-checks-grid') ?>No templates yet.</td></tr><?php endif ?>
    <?php foreach ($templates as $t): ?>
        <tr>
            <td><strong><?= e($t['template_code']) ?></strong> <span class="text-muted">v<?= (int) $t['version'] ?></span><div class="small"><?= e($t['name']) ?></div></td>
            <td><?= e($t['type_name']) ?></td>
            <td><span class="qms-badge qms-badge--<?= TEMPLATE_STATUS_TONES[$t['status']] ?>"><?= qms_icon($t['status'] === 'PUBLISHED' ? 'bi-check-circle-fill' : ($t['status'] === 'DRAFT' ? 'bi-pencil-square' : 'bi-archive')) ?> <?= e($t['status']) ?></span></td>
            <td class="small"><?= e($t['format_doc_no']) ?> · Rev <?= e($t['format_rev_no']) ?></td>
            <td class="text-end num"><?= (int) $t['parameter_count'] ?></td>
            <td class="small"><?= $t['part_list'] !== null ? 'Parts: ' . e($t['part_list']) : 'All parts' ?><br><?= $t['machine_list'] !== null ? 'Machines: ' . e($t['machine_list']) : 'All machines' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('template.php?id=' . (int) $t['id']) ?>"><?= qms_icon('bi-eye') ?> Open</a></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?php require QMS_ROOT . '/includes/layout/footer.php';

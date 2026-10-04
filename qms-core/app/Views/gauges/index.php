<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="qms-page-head">
    <div><h1>Gauges</h1><p class="qms-sub">Instruments, calibration status and history. Expired gauges can block inspection submission (Settings → Gauges).</p></div>
    <?php if (can('gauge.manage')): ?><a class="btn btn-primary btn-lg" href="<?= site_url('gauges/new') ?>"><?= qms_icon('bi-plus-lg') ?> Register gauge</a><?php endif ?>
</div>
<form class="qms-filter row g-2 align-items-end" method="get">
    <div class="col-md-3"><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" placeholder="Gauge ID, name, serial" value="<?= esc($filters['q'] ?? '', 'attr') ?>"></div>
    <div class="col-md-3"><label class="form-label" for="type">Type</label>
        <select class="form-select" id="type" name="type"><option value="">All types</option>
        <?php foreach ($types as $id => $label): ?><option value="<?= $id ?>"<?= (string) ($filters['type'] ?? '') === (string) $id ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status"><option value="">In use (not retired)</option>
        <?php foreach ($statuses as $k => $v): ?><option value="<?= $k ?>"<?= ($filters['status'] ?? '') === $k ? ' selected' : '' ?>><?= esc($v) ?></option><?php endforeach ?></select></div>
    <div class="col-md-2"><label class="form-label" for="due">Calibration</label>
        <select class="form-select" id="due" name="due"><option value="">Any</option>
            <option value="soon"<?= ($filters['due'] ?? '') === 'soon' ? ' selected' : '' ?>>Due soon</option>
            <option value="expired"<?= ($filters['due'] ?? '') === 'expired' ? ' selected' : '' ?>>Expired</option></select></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><?= qms_icon('bi-funnel') ?> Filter</button></div>
</form>
<div class="card"><div class="qms-table-wrap">
<table class="table table-hover">
    <thead><tr><th>Gauge ID</th><th>Name</th><th>Type</th><th>Range / LC</th><th>Calibration</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($gauges === []): ?><tr><td colspan="7" class="qms-empty"><?= qms_icon('bi-rulers') ?>No gauges match.</td></tr><?php endif ?>
    <?php foreach ($gauges as $g): $a = $g['availability']; ?>
        <tr>
            <td><strong><?= esc($g['gauge_code']) ?></strong></td>
            <td><?= esc($g['gauge_name']) ?></td>
            <td><?= esc($g['type_name']) ?></td>
            <td class="small"><?= esc($g['measuring_range'] ?? '') ?><?= $g['least_count'] !== null ? '<br>LC ' . esc(rtrim(rtrim($g['least_count'], '0'), '.')) . ' ' . esc($g['unit_symbol'] ?? '') : '' ?></td>
            <td><span class="qms-badge qms-badge--<?= $a['tone'] ?>"><?= qms_icon($a['usable'] ? ($a['state'] === 'DUE_SOON' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle') : 'bi-x-octagon-fill') ?> <?= esc($a['label']) ?></span></td>
            <td><?= esc($statuses[$g['status']] ?? $g['status']) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= site_url('gauges/' . $g['id']) ?>"><?= qms_icon('bi-eye') ?> Open</a></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= view('partials/pager', ['paging' => $paging]) ?>
<?= $this->endSection() ?>

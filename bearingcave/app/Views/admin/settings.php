<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => $title, 'subtitle' => 'Business rules live here instead of in code. Mark a value "Approved" once the client confirms it.']) ?>
<form method="post" action="<?= site_url($action) ?>"><?= csrf_field() ?>
<?php foreach ($groups as $group => $rows): ?>
<div class="bc-card mb-4"><div class="bc-card-header"><h2><?= esc(ucwords(str_replace('_', ' ', $group))) ?></h2></div><div class="bc-card-body">
    <?php foreach ($rows as $s): $k = $s['setting_key']; $id = 's_' . md5($k); ?>
    <div class="row g-2 align-items-start py-2 border-bottom">
        <div class="col-md-4"><label class="form-label mb-0" for="<?= $id ?>"><?= esc($s['label']) ?></label><div class="small text-muted"><?= esc($s['description'] ?? '') ?></div><div class="small part-no text-muted"><?= esc($k) ?></div></div>
        <div class="col-md-5">
            <?php if ($s['value_type'] === 'bool'): ?>
                <select class="form-select form-select-sm" id="<?= $id ?>" name="s[<?= esc($k, 'attr') ?>]"><option value="1"<?= $s['setting_value'] === '1' ? ' selected' : '' ?>>Yes / enabled</option><option value="0"<?= $s['setting_value'] !== '1' ? ' selected' : '' ?>>No / disabled</option></select>
            <?php elseif (in_array($s['value_type'], ['json', 'text'], true)): ?>
                <textarea class="form-control form-control-sm<?= $s['value_type'] === 'json' ? ' part-no' : '' ?>" id="<?= $id ?>" name="s[<?= esc($k, 'attr') ?>]" rows="<?= $s['value_type'] === 'json' ? 2 : 3 ?>"><?= esc((string) $s['setting_value']) ?></textarea>
            <?php else: ?>
                <input class="form-control form-control-sm" id="<?= $id ?>" name="s[<?= esc($k, 'attr') ?>]" value="<?= esc((string) $s['setting_value'], 'attr') ?>"<?= in_array($s['value_type'], ['int', 'decimal'], true) ? ' inputmode="decimal"' : '' ?>>
            <?php endif ?>
        </div>
        <div class="col-md-3"><label class="visually-hidden" for="a<?= $id ?>">Approval for <?= esc($s['label']) ?></label><select class="form-select form-select-sm" id="a<?= $id ?>" name="approval[<?= esc($k, 'attr') ?>]"><option value="pending_approval"<?= $s['approval_status'] === 'pending_approval' ? ' selected' : '' ?>>⏳ Pending client approval</option><option value="approved"<?= $s['approval_status'] === 'approved' ? ' selected' : '' ?>>✓ Approved</option></select></div>
    </div>
    <?php endforeach ?>
</div></div>
<?php endforeach ?>
<div class="position-sticky bottom-0 bg-white border-top py-2"><button class="btn btn-primary" type="submit" data-confirm="Save settings? Changes apply immediately.">Save settings</button></div>
</form>
<?= $this->endSection() ?>

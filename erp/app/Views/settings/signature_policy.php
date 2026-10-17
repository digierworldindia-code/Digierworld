<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php if ($wizard): ?><?= view('settings/_wizard', ['current' => 'signature']) ?><?php endif ?>
<div class="alert alert-info small"><i class="bi bi-pen"></i> A signed document is a legal assertion. An uploaded signature/stamp image is <strong>not</strong> available to everyone who can bill: a person may apply it only when (1) this owner-approved policy allows their role or account for that document type <strong>and</strong> (2) they hold <code>billing.apply_signature</code>. Every use is logged. Unsigned documents print “Computer-generated document — signature not applied”.</div>
<form method="post" action="<?= site_url('settings/signature-policy') ?>">
<?= csrf_field() ?>
<div class="table-card mb-3"><div class="table-responsive">
<table class="table table-sm align-middle small mb-0">
    <thead><tr><th>Role / user</th><?php foreach ($docTypes as $dt): ?><th class="text-center"><?= esc($dt['label']) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <tr class="table-light"><th colspan="<?= count($docTypes) + 1 ?>">Roles</th></tr>
    <?php foreach ($roles as $r): $key = 'r' . $r['id']; ?>
        <tr><td><?= esc($r['name']) ?></td>
        <?php foreach ($docTypes as $dk => $dt): ?><td class="text-center"><input type="checkbox" class="form-check-input" name="allow[<?= $key ?>][<?= $dk ?>]" value="1" <?= isset($policies[$key][$dk]) ? 'checked' : '' ?> <?= $canApprove ? '' : 'disabled' ?> aria-label="<?= esc($r['name'] . ' ' . $dt['label'], 'attr') ?>"></td><?php endforeach ?></tr>
    <?php endforeach ?>
    <tr class="table-light"><th colspan="<?= count($docTypes) + 1 ?>">Individual users (optional, in addition to roles)</th></tr>
    <?php foreach ($users as $u): $key = 'u' . $u['id']; ?>
        <tr><td><?= esc($u['full_name']) ?></td>
        <?php foreach ($docTypes as $dk => $dt): ?><td class="text-center"><input type="checkbox" class="form-check-input" name="allow[<?= $key ?>][<?= $dk ?>]" value="1" <?= isset($policies[$key][$dk]) ? 'checked' : '' ?> <?= $canApprove ? '' : 'disabled' ?> aria-label="<?= esc($u['full_name'] . ' ' . $dt['label'], 'attr') ?>"></td><?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?php if ($canApprove): ?><button class="btn btn-dark" data-confirm="Approve this signature policy as Owner?">Approve & save policy</button><?php else: ?><p class="small text-muted">Read-only: only an Owner / Super Admin can approve signature policy changes.</p><?php endif ?>
</form>
<div class="row g-3 mt-1">
    <div class="col-lg-4"><div class="card"><div class="card-body small">
        <div class="card-section-title">People holding billing.apply_signature</div>
        <?php foreach ($signers as $s): ?><div><?= esc($s['full_name']) ?></div><?php endforeach ?>
        <?php if ($signers === []): ?><span class="text-muted">Nobody.</span><?php endif ?>
    </div></div></div>
    <div class="col-lg-8"><div class="table-card">
        <div class="p-3 border-bottom"><strong>Recent signature use</strong></div>
        <table class="table table-sm small mb-0"><thead><tr><th>When</th><th>Who</th><th>Document</th></tr></thead><tbody>
        <?php foreach ($usage as $u): ?><tr><td><?= dmy($u['used_at'], true) ?></td><td><?= esc($u['full_name']) ?></td><td><?= esc(doc_type_label($u['doc_type']) . ' ' . $u['document_number']) ?></td></tr><?php endforeach ?>
        <?php if ($usage === []): ?><tr><td colspan="3" class="text-muted">No signed documents yet.</td></tr><?php endif ?>
        </tbody></table>
    </div></div>
</div>
<?php if ($wizard): ?><div class="sticky-actions"><a href="<?= site_url('settings/setup') ?>" class="btn btn-dark">Back to setup overview</a></div><?php endif ?>
<?= $this->endSection() ?>

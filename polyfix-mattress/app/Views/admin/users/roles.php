<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
use App\Libraries\Rbac;

/** @var array<string, array<string,string>> $groups @var array<string,int> $counts */
?>
<div class="page-head"><div><h1>Roles &amp; permissions</h1>
<p>What each role can do. Routes check these permissions in a filter, so hiding a link never stands in for a check.</p></div></div>

<div class="row g-3 mb-3">
    <?php foreach (Rbac::ROLE_KEYS as $r): ?>
    <div class="col-6 col-lg-3"><div class="stat">
        <div class="stat__label"><?= esc(Rbac::roleName($r)) ?></div>
        <div class="stat__value"><?= (int) ($counts[$r] ?? 0) ?></div>
        <div class="stat__note"><?= esc(Rbac::ROLE_METADATA[$r]['description'] ?? '') ?></div>
    </div></div>
    <?php endforeach ?>
</div>

<div class="panel">
    <div class="panel-head"><h2>Permission matrix</h2><span class="small text-muted"><?= count(Rbac::PERMISSIONS) ?> permissions</span></div>
    <div class="table-responsive"><table class="table">
        <thead><tr><th style="min-width:20rem">Permission</th>
            <?php foreach (Rbac::ROLE_KEYS as $r): ?><th class="small text-center"><?= esc(Rbac::roleName($r)) ?></th><?php endforeach ?></tr></thead>
        <tbody>
        <?php foreach ($groups as $group => $permissions): ?>
            <tr><th colspan="<?= count(Rbac::ROLE_KEYS) + 1 ?>" class="bg-body-secondary"><?= esc(ucfirst($group)) ?></th></tr>
            <?php foreach ($permissions as $key => $description): ?>
            <tr>
                <td><span class="mono small"><?= esc($key) ?></span><?= in_array($key, Rbac::MFA_GATED, true) ? ' <span class="pill pill-caution">2FA</span>' : '' ?>
                    <div class="small text-muted"><?= esc($description) ?></div></td>
                <?php foreach (Rbac::ROLE_KEYS as $r): ?>
                    <td class="text-center"><?= in_array($key, Rbac::ROLE_PERMISSIONS[$r], true) ? '<i class="bi bi-check-lg" style="color:var(--accent)" aria-label="yes"></i>' : '<span class="text-muted" aria-label="no">·</span>' ?></td>
                <?php endforeach ?>
            </tr>
            <?php endforeach ?>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <div class="panel-body small text-muted">Permissions marked 2FA can only be used from a session that passed two-factor authentication.</div>
</div>
<?= $this->endSection() ?>

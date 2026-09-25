<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
use App\Libraries\Rbac;

/** @var list<array> $dealers */
?>
<div class="page-head"><div><div class="small"><a href="<?= site_url('admin/users') ?>">Users</a></div><h1>New user</h1>
<p>A one-time password is generated and shown once. You cannot choose it, and nobody can read it afterwards.</p></div></div>
<form class="panel" method="post" action="<?= site_url('admin/users') ?>">
    <div class="panel-body" style="max-width:44rem">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="full_name">Full name</label><input class="form-control<?= invalid('full_name') ?>" id="full_name" name="full_name" required maxlength="160" value="<?= esc(old('full_name', ''), 'attr') ?>"><?= field_error('full_name') ?></div>
            <div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" required maxlength="255" value="<?= esc(old('email', ''), 'attr') ?>"><?= field_error('email') ?></div>
            <div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" type="tel" maxlength="16" value="<?= esc(old('phone', ''), 'attr') ?>"><?= field_error('phone') ?></div>
            <div class="col-md-6"><label class="form-label" for="role">Role</label>
                <select class="form-select<?= invalid('role') ?>" id="role" name="role" required>
                    <?php foreach (Rbac::ROLE_KEYS as $r): ?><option value="<?= $r ?>"<?= old('role') === $r ? ' selected' : '' ?>><?= esc(Rbac::roleName($r)) ?> — <?= esc(Rbac::ROLE_METADATA[$r]['description'] ?? '') ?></option><?php endforeach ?>
                </select><?= field_error('role') ?>
                <div class="form-text">Roles requiring two-factor authentication: <?= esc(implode(', ', array_map(static fn ($r) => Rbac::roleName($r), config('Polyfix')->mfaRoles()))) ?>.</div></div>
            <div class="col-12"><label class="form-label" for="dealer_id">Dealership (for a Dealer login)</label>
                <select class="form-select" id="dealer_id" name="dealer_id"><option value="">—</option>
                    <?php foreach ($dealers as $d): ?><option value="<?= $d['id'] ?>"<?= old('dealer_id') === $d['id'] ? ' selected' : '' ?>><?= esc($d['business_name']) ?> — <?= esc($d['city']) ?> (<?= esc($d['code']) ?>)</option><?php endforeach ?>
                </select></div>
        </div>
        <button class="btn btn-primary mt-3" type="submit">Create account</button>
    </div>
</form>
<?= $this->endSection() ?>

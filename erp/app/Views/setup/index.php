<?php
/**
 * First-time administrator setup: checks, encryption key, tables, owner.
 *
 * @var list<array{0: string, 1: string, 2: string}> $checks
 * @var bool $hasKey
 * @var bool $canDb
 * @var bool $dbReady
 * @var bool $envWrites
 */
$badge = static fn (string $s): string => match ($s) {
    'ok'    => '<span class="badge badge-success">OK</span>',
    'warn'  => '<span class="badge badge-warning">Check</span>',
    default => '<span class="badge badge-danger">Fix</span>',
};
?>
<?= $this->extend('auth/layout') ?>

<?= $this->section('title') ?>Administrator setup<?= $this->endSection() ?>
<?= $this->section('card_class') ?> auth-card-wide<?= $this->endSection() ?>

<?= $this->section('main') ?>
<h1 class="text-center mb-3">Administrator setup</h1>

<?php if (session('error') !== null) : ?>
    <div class="alert alert-danger py-2 small" role="alert"><?= esc(session('error')) ?></div>
<?php endif ?>
<?php if (session('message') !== null) : ?>
    <div class="alert alert-success py-2 small" role="alert"><?= esc(session('message')) ?></div>
<?php endif ?>

<div class="table-responsive mb-3">
    <table class="table table-sm small mb-0 align-middle">
        <thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($checks as [$label, $status, $detail]) : ?>
            <tr><td class="text-nowrap"><?= esc($label) ?></td><td><?= $badge($status) ?></td><td><?= esc($detail) ?></td></tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<p class="small text-muted">Fix anything marked <span class="badge badge-danger">Fix</span> in the hosting panel or in <span class="mono">.env</span>, then reload this page.</p>

<h2 class="h6 mt-4">1. Encryption key</h2>
<?php if ($hasKey) : ?>
    <p class="small mb-0"><?= $badge('ok') ?> Set. Keep a copy of <span class="mono">.env</span> somewhere safe — without it saved bank numbers cannot be read.</p>
<?php elseif ($envWrites) : ?>
    <form action="<?= site_url('setup/key') ?>" method="post">
        <?= csrf_field() ?>
        <p class="small mb-2">A random key is added to <span class="mono">.env</span>. It is never shown and never replaced once set.</p>
        <button type="submit" class="btn btn-primary btn-sm">Generate the key</button>
    </form>
<?php else : ?>
    <p class="small mb-0"><?= $badge('fail') ?> <span class="mono">.env</span> cannot be written by the web server. Run <span class="mono">php spark key:generate</span>, or make <span class="mono">.env</span> writable (644) for a moment and reload.</p>
<?php endif ?>

<h2 class="h6 mt-4">2. Database tables</h2>
<?php if (! $canDb) : ?>
    <p class="small mb-0"><?= $badge('fail') ?> Fix the database connection in <span class="mono">.env</span> first (see the checks above).</p>
<?php elseif ($dbReady) : ?>
    <p class="small mb-0"><?= $badge('ok') ?> All tables and base data are in place.</p>
<?php else : ?>
    <form action="<?= site_url('setup/database') ?>" method="post">
        <?= csrf_field() ?>
        <p class="small mb-2">Creates the tables and base data (states, permissions, starter roles, chart of accounts, tax-rate suggestions). This can take up to a minute. If the database already holds data, a backup is written to <span class="mono">writable/backups</span> and checked first.</p>
        <button type="submit" class="btn btn-primary btn-sm">Create the database tables</button>
    </form>
<?php endif ?>

<h2 class="h6 mt-4">3. Administrator account</h2>
<?php if (! $hasKey || ! $dbReady) : ?>
    <p class="small text-muted mb-0">Finish steps 1 and 2 first.</p>
<?php else : ?>
    <form action="<?= site_url('setup/owner') ?>" method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label small required" for="full_name">Full name</label><input class="form-control<?= is_invalid('full_name') ?>" id="full_name" name="full_name" value="<?= esc((string) old('full_name')) ?>" maxlength="120" required><?= field_error('full_name') ?></div>
            <div class="col-md-6"><label class="form-label small required" for="email">Email (sign-in name)</label><input type="email" class="form-control<?= is_invalid('email') ?>" id="email" name="email" value="<?= esc((string) old('email')) ?>" autocomplete="username" required><?= field_error('email') ?></div>
            <div class="col-md-6"><label class="form-label small required" for="password">Password</label><input type="password" class="form-control<?= is_invalid('password') ?>" id="password" name="password" autocomplete="new-password" minlength="<?= (int) config('Auth')->minimumPasswordLength ?>" required><?= field_error('password') ?></div>
            <div class="col-md-6"><label class="form-label small required" for="password_confirm">Repeat password</label><input type="password" class="form-control<?= is_invalid('password_confirm') ?>" id="password_confirm" name="password_confirm" autocomplete="new-password" required><?= field_error('password_confirm') ?></div>
        </div>
        <div class="form-text">At least <?= (int) config('Auth')->minimumPasswordLength ?> characters with letters and numbers; must not contain your name or the part of your email before @. This account has every permission (Owner / Super Admin).</div>
        <button type="submit" class="btn btn-primary mt-3">Create the administrator</button>
    </form>
<?php endif ?>

<p class="small text-muted mt-4 mb-0">After signing in: <strong>Settings › Billing Setup Wizard</strong> (company, GSTIN, address), <strong>Settings › Branding Assets</strong> (logo, signature, stamp), <strong>Settings › Bank &amp; Payment Profiles</strong>, then <strong>Administration › Users</strong> for your staff. Add the two cron jobs from the installation guide (docs/11-ADMIN-INSTALLATION.md).</p>
<?= $this->endSection() ?>

<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Account settings', 'subtitle' => esc($user->email)]) ?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Change password</h2></div><div class="bc-card-body">
            <form method="post" action="<?= site_url('account/password') ?>" novalidate>
                <?= csrf_field() ?>
                <?php if (! session('magicLoginPasswordReset')): ?><?= field('current_password', 'Current password', null, ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="current-password"']) ?><?php endif ?>
                <?= field('password', 'New password', null, ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password" minlength="10"', 'help' => 'At least 10 characters. Avoid words related to your email or company.']) ?>
                <?= field('password_confirm', 'Confirm new password', null, ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"']) ?>
                <button class="btn btn-primary" type="submit">Update password</button>
            </form>
        </div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Recent sign-ins</h2></div>
            <div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>When</th><th>IP</th><th>Result</th></tr></thead><tbody>
            <?php foreach ($logins as $l): ?><tr><td><?= fdt($l['date']) ?></td><td><?= esc($l['ip_address']) ?></td><td><?= $l['success'] ? status_badge('succeeded', 'Success') : status_badge('failed') ?></td></tr><?php endforeach ?>
            </tbody></table></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="bc-card"><div class="bc-card-header"><h2>API access tokens</h2></div><div class="bc-card-body">
            <p class="small text-muted">Use tokens to integrate your ERP with the BearingCave REST API (Authorization: Bearer &lt;token&gt;). Tokens act with your permissions.</p>
            <?php if ($newToken): ?><div class="alert alert-warning small"><strong>Copy your new token now:</strong><br><code class="user-select-all"><?= esc($newToken) ?></code></div><?php endif ?>
            <form method="post" action="<?= site_url('account/tokens') ?>" class="d-flex gap-2 mb-3"><?= csrf_field() ?><label class="visually-hidden" for="tokname">Token name</label><input id="tokname" class="form-control form-control-sm" name="name" placeholder="e.g. ERP integration" required><button class="btn btn-sm btn-primary text-nowrap" type="submit">Create token</button></form>
            <?php if (! $tokens): ?><p class="small text-muted mb-0">No tokens.</p><?php endif ?>
            <ul class="list-group"><?php foreach ($tokens as $t): ?><li class="list-group-item d-flex justify-content-between align-items-center small"><span><?= esc($t->name) ?><br><span class="text-muted">Last used: <?= $t->last_used_at ? esc((string) $t->last_used_at) : 'never' ?></span></span><?= post_button('account/tokens/' . $t->id . '/revoke', 'Revoke', 'btn btn-sm btn-outline-danger', 'Revoke this token?') ?></li><?php endforeach ?></ul>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>

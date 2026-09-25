<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/** @var list<array{0:string,1:string,2:string}> $checks @var array $counts @var string $php @var string $ci @var array|null $chain @var array $storage */
$tone = ['ok' => 'pill-positive', 'warn' => 'pill-caution', 'fail' => 'pill-critical'];
$label = ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Problem'];
?>
<div class="page-head"><div><h1>System</h1><p>What an operator needs to see. No credentials or paths are shown here.</p></div>
    <?php if ($ctx->can('audit:read')): ?><a class="btn btn-light" href="<?= site_url('admin/audit/verify') ?>">Verify audit chain</a><?php endif ?></div>

<div class="panel">
    <div class="panel-head"><h2>Health</h2><span class="small text-muted">PHP <?= esc($php) ?> · CodeIgniter <?= esc($ci) ?></span></div>
    <table class="table"><tbody>
        <?php foreach ($checks as [$name, $state, $detail]): ?>
        <tr><td style="width:14rem"><?= esc($name) ?></td>
            <td style="width:7rem"><span class="pill <?= $tone[$state] ?>"><?= $label[$state] ?></span></td>
            <td class="small"><?= esc($detail) ?></td></tr>
        <?php endforeach ?>
    </tbody></table>
</div>

<div class="row g-3 mt-0">
    <div class="col-xl-6"><div class="panel h-100">
        <div class="panel-head"><h2>Records</h2></div>
        <table class="table"><tbody>
            <?php foreach ($counts as $table => $n): ?>
            <tr><td><?= esc(humanise($table)) ?></td><td class="num"><?= number_format($n) ?></td></tr>
            <?php endforeach ?>
        </tbody></table>
    </div></div>
    <div class="col-xl-6"><div class="panel h-100">
        <div class="panel-head"><h2>Storage and chain</h2></div>
        <div class="panel-body"><dl class="dl-grid">
            <dt>Claim files</dt><dd><?= number_format($storage['files']) ?> file(s), <?= number_format($storage['bytes'] / 1048576, 1) ?> MB</dd>
            <dt>Audit chain head</dt><dd class="mono" style="word-break:break-all"><?= esc(mb_substr((string) ($chain['last_hash'] ?? '—'), 0, 32)) ?>…</dd>
            <dt>Last audit id</dt><dd><?= esc((string) ($chain['last_audit_id'] ?? '—')) ?></dd>
            <dt>Backups</dt><dd class="small">Taken outside the application with <span class="mono">mysqldump</span>; see <span class="mono">docs/backup.md</span>.</dd>
        </dl></div>
    </div></div>
</div>
<?= $this->endSection() ?>

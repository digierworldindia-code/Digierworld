<?= $this->extend('layouts/app') ?>
<?php $fmt = static fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string) ($v ?? ''); ?>
<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-4"><div class="card"><div class="card-body small">
        <dl class="row mb-0">
            <dt class="col-5">When</dt><dd class="col-7"><?= dmy($row['occurred_at'], true) ?></dd>
            <dt class="col-5">Actor</dt><dd class="col-7"><?= esc($row['full_name'] ?? '—') ?> <span class="badge bg-light text-dark"><?= esc($row['actor_type']) ?></span></dd>
            <dt class="col-5">Action</dt><dd class="col-7"><code><?= esc($row['action']) ?></code></dd>
            <dt class="col-5">Record</dt><dd class="col-7"><?= esc((string) $row['entity_type']) ?> #<?= (int) $row['entity_id'] ?></dd>
            <dt class="col-5">Summary</dt><dd class="col-7"><?= esc($row['summary']) ?></dd>
            <dt class="col-5">IP / agent</dt><dd class="col-7 mono"><?= esc($row['ip_address']) ?><br><span class="text-muted"><?= esc($row['user_agent']) ?></span></dd>
            <dt class="col-5">Row hash</dt><dd class="col-7 mono text-break"><?= esc($row['row_hash']) ?></dd>
            <dt class="col-5">Prev hash</dt><dd class="col-7 mono text-break"><?= esc((string) $row['prev_hash']) ?></dd>
        </dl>
        <?php if ($meta): ?><div class="mt-2"><strong>Meta</strong><pre class="small bg-light p-2 rounded"><?= esc($fmt($meta)) ?></pre></div><?php endif ?>
    </div></div></div>
    <div class="col-lg-8"><div class="table-card">
        <table class="table table-sm small mb-0"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>
        <?php foreach ($keys as $k): $b = $before[$k] ?? null; $a = $after[$k] ?? null; $changed = $fmt($b) !== $fmt($a); ?>
            <tr class="<?= $changed ? 'table-warning' : '' ?>"><td><code><?= esc((string) $k) ?></code></td><td><pre class="mb-0 small text-wrap"><?= esc($fmt($b)) ?></pre></td><td><pre class="mb-0 small text-wrap"><?= esc($fmt($a)) ?></pre></td></tr>
        <?php endforeach ?>
        <?php if ($keys === []): ?><tr><td colspan="3" class="text-muted">No field-level data recorded for this action.</td></tr><?php endif ?>
        </tbody></table>
    </div></div>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var list<string> $actions @var list<string> $entities @var array $f */ ?>
<div class="page-head">
    <div><h1>Audit trail</h1><p>Every change, in order, hash-chained. Nothing here can be edited or deleted — not even by an administrator.</p></div>
    <a class="btn btn-light" href="<?= site_url('admin/audit/verify') ?>"><i class="bi bi-shield-check"></i> Verify the chain</a>
</div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="action">Action</label><select class="form-select" id="action" name="action" data-autosubmit><option value="">Any</option>
            <?php foreach ($actions as $a): ?><option value="<?= esc($a, 'attr') ?>"<?= $f['action'] === $a ? ' selected' : '' ?>><?= esc(humanise($a)) ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="entity">Entity</label><select class="form-select" id="entity" name="entity" data-autosubmit><option value="">Any</option>
            <?php foreach ($entities as $e): ?><option value="<?= esc($e, 'attr') ?>"<?= $f['entity'] === $e ? ' selected' : '' ?>><?= esc(humanise($e)) ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="id">Record id</label><input class="form-control" id="id" name="id" value="<?= esc($f['id'] ?? '', 'attr') ?>"></div>
        <div><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= esc($f['from'] ?? '', 'attr') ?>"></div>
        <div><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= esc($f['to'] ?? '', 'attr') ?>"></div>
        <?php if ($f['user']): ?><input type="hidden" name="user" value="<?= esc($f['user'], 'attr') ?>"><?php endif ?>
        <div><button class="btn btn-light" type="submit">Filter</button> <?php if (array_filter($f)): ?><a class="btn btn-link" href="<?= site_url('admin/audit') ?>">Clear</a><?php endif ?></div>
    </form>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $a): ?>
        <tr>
            <td class="small text-nowrap"><?= esc(local_time($a['occurred_at'], 'd M Y, H:i:s')) ?></td>
            <td class="small"><?= esc($a['user_email'] ?? 'system') ?><div class="text-muted"><?= esc($a['role_key'] ?? '') ?> <?= esc($a['ip'] ?? '') ?></div></td>
            <td class="small"><?= esc(humanise($a['action'])) ?></td>
            <td class="small"><?= esc($a['entity']) ?><div class="mono text-muted" style="font-size:.7rem"><?= esc(mb_strimwidth((string) $a['entity_id'], 0, 20, '…')) ?></div></td>
            <td class="small">
                <?php if ($a['reason']): ?><div><em><?= esc($a['reason']) ?></em></div><?php endif ?>
                <?php foreach (['previous_value' => 'before', 'new_value' => 'after'] as $column => $label): ?>
                    <?php if ($a[$column] !== null && $a[$column] !== 'null'): ?>
                    <details><summary class="text-muted"><?= $label ?></summary><pre class="small mb-0" style="white-space:pre-wrap;word-break:break-all"><?= esc(json_encode(json_decode((string) $a[$column], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $a[$column]) ?></pre></details>
                    <?php endif ?>
                <?php endforeach ?>
            </td>
        </tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="5" class="table-empty">No entries match.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>

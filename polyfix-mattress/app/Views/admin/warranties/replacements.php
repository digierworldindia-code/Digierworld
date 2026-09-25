<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list */ ?>
<div class="page-head"><div><h1>Replacements</h1><p>Units issued against approved claims. The replacement carries the remainder of the original term.</p></div></div>
<div class="panel">
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Issued</th><th>Claim</th><th>Original</th><th>Replacement</th><th>Dealer</th><th>Approved by</th><th>Remarks</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
        <tr><td class="small text-nowrap"><?= esc(local_date($r['issued_at'])) ?></td>
            <td><a href="<?= site_url('admin/claims/' . $r['claim_id']) ?>"><?= esc($r['claim_number']) ?></a></td>
            <td><a class="mono small" href="<?= site_url('admin/mattresses/' . $r['original_id']) ?>"><?= esc($r['original']) ?></a></td>
            <td><a class="mono small" href="<?= site_url('admin/mattresses/' . $r['replacement_id']) ?>"><?= esc($r['replacement']) ?></a></td>
            <td class="small"><?= esc($r['dealer']) ?></td><td class="small"><?= esc($r['approved_by'] ?? '—') ?></td>
            <td class="small"><?= esc($r['remarks'] ?? '—') ?></td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="7" class="table-empty">No replacements issued.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>

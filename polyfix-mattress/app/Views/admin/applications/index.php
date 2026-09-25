<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var array $f */ ?>
<div class="page-head"><div><h1>Dealership applications</h1><p>Sent from the website. Approving one creates the dealership.</p></div></div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><option value="">Any</option>
            <?php foreach (['NEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED'] as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
    </form>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Business</th><th>Owner</th><th>Where</th><th>Contact</th><th>Status</th><th>Received</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $a): ?>
        <tr><td><a class="fw-semibold" href="<?= site_url('admin/applications/' . $a['id']) ?>"><?= esc($a['business_name']) ?></a>
                <?= (int) $a['spam_score'] >= 50 ? ' <span class="pill pill-caution">Likely spam</span>' : '' ?>
                <?= $a['dealer_code'] ? ' <span class="pill pill-positive">' . esc($a['dealer_code']) . '</span>' : '' ?></td>
            <td class="small"><?= esc($a['owner_name']) ?></td><td class="small"><?= esc($a['city']) ?>, <?= esc($a['state']) ?></td>
            <td class="small"><?= esc($a['mobile']) ?><div class="text-muted"><?= esc($a['email']) ?></div></td>
            <td><?= pill($a['status']) ?></td><td class="small text-nowrap"><?= esc(local_time($a['created_at'], 'd M Y')) ?></td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="6" class="table-empty">No applications.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>

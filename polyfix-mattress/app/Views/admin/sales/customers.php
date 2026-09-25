<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var list<array> $dealers @var array $f */ ?>
<div class="page-head"><div><h1>Customers</h1><p>Recorded by dealers at the point of sale. Contact details are stored encrypted.</p></div></div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= esc($f['q'] ?? '', 'attr') ?>" placeholder="Name or mobile number"></div>
        <div><label class="form-label" for="dealer">Dealer</label><select class="form-select" id="dealer" name="dealer" data-autosubmit><option value="">Any</option>
            <?php foreach ($dealers as $d): ?><option value="<?= $d['id'] ?>"<?= $f['dealer'] === $d['id'] ? ' selected' : '' ?>><?= esc($d['business_name']) ?></option><?php endforeach ?></select></div>
        <div><button class="btn btn-light" type="submit">Search</button></div>
    </form>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Name</th><th>Mobile</th><th>Where</th><th>Dealer</th><th class="num">Purchases</th><th class="num">Claims</th><th>Added</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $c): ?>
        <tr><td><?= esc($c['full_name']) ?></td><td class="small mono"><?= esc($c['phone'] ?? '—') ?></td>
            <td class="small"><?= esc(implode(', ', array_filter([$c['city'], $c['state']]))) ?></td>
            <td class="small"><?= esc($c['dealer']) ?></td><td class="num"><?= (int) $c['purchases'] ?></td><td class="num"><?= (int) $c['claims'] ?></td>
            <td class="small text-nowrap"><?= esc(local_time($c['created_at'], 'd M Y')) ?></td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="7" class="table-empty">No customers match.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>

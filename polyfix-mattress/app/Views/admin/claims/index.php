<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $list @var array $f @var list<string> $statuses @var list<array> $dealers */ ?>
<div class="page-head"><div><h1>Warranty claims</h1><p>Risk indicators order the queue; they never decide a claim.</p></div></div>
<div class="panel">
    <form class="filters panel-body border-bottom" method="get">
        <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= esc($f['q'] ?? '', 'attr') ?>" placeholder="Claim or serial"></div>
        <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit>
            <option value="">Any</option><option value="open"<?= $f['status'] === 'open' ? ' selected' : '' ?>>Open (needs action)</option>
            <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= esc(humanise($s)) ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="risk">Risk</label><select class="form-select" id="risk" name="risk" data-autosubmit><option value="">Any</option>
            <?php foreach (['HIGH', 'MEDIUM', 'LOW'] as $r): ?><option<?= $f['risk'] === $r ? ' selected' : '' ?>><?= $r ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="dealer">Dealer</label><select class="form-select" id="dealer" name="dealer" data-autosubmit><option value="">Any</option>
            <?php foreach ($dealers as $d): ?><option value="<?= $d['id'] ?>"<?= $f['dealer'] === $d['id'] ? ' selected' : '' ?>><?= esc($d['business_name']) ?></option><?php endforeach ?></select></div>
        <div><button class="btn btn-light" type="submit">Filter</button></div>
    </form>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Claim</th><th>Serial</th><th>Issue</th><th>Dealer</th><th>Status</th><th>Risk</th><th>Submitted</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $c): ?>
        <tr><td><a class="fw-semibold" href="<?= site_url('admin/claims/' . $c['id']) ?>"><?= esc($c['claim_number']) ?></a></td>
            <td class="mono small"><?= esc($c['serial_number']) ?></td>
            <td class="small"><?= esc(\App\Services\ClaimService::ISSUE_CATEGORIES[$c['issue_category']] ?? humanise($c['issue_category'])) ?><div class="text-muted text-truncate" style="max-width:18rem"><?= esc($c['reported_issue']) ?></div></td>
            <td class="small"><?= esc($c['dealer']) ?></td><td><?= pill($c['status']) ?></td>
            <td><?= $c['risk_level'] && $ctx->can('risk:view') ? pill($c['risk_level'], $c['risk_level'] . ' · ' . (int) $c['risk_score']) : '—' ?></td>
            <td class="small text-nowrap"><?= esc(local_time($c['submitted_at'], 'd M Y')) ?></td></tr>
        <?php endforeach ?>
        <?php if ($list['rows'] === []): ?><tr><td colspan="7" class="table-empty">No claims match.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?= view('partials/pager', ['p' => $list]) ?>
</div>
<?= $this->endSection() ?>

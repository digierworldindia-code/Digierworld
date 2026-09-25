<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/** @var array $stats @var list<array> $queue @var list<array> $topDealers @var array $months */
$card = static fn (string $label, int $value, string $note = '', ?string $href = null): string =>
    ($href ? '<a class="stat" href="' . esc($href, 'attr') . '">' : '<div class="stat">')
    . '<div class="stat__label">' . esc($label) . '</div><div class="stat__value">' . number_format($value) . '</div>'
    . ($note !== '' ? '<div class="stat__note">' . esc($note) . '</div>' : '') . ($href ? '</a>' : '</div>');
$max = max(1, ...array_values(array_map(static fn ($m) => max($m["made"], $m["sold"]), $months)));
?>
<div class="page-head">
    <div><h1>Good <?= (int) local_time(utc_now(), 'G') < 12 ? 'morning' : ((int) local_time(utc_now(), 'G') < 17 ? 'afternoon' : 'evening') ?>, <?= esc(explode(' ', $ctx->user()['full_name'])[0]) ?></h1>
    <p><?= esc(local_time(utc_now(), 'l, d F Y')) ?></p></div>
</div>

<h2 class="h6 text-uppercase text-muted mb-2">Inventory</h2>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl"><?= $card('Units made', $stats['totalUnits'], 'All time', site_url('admin/mattresses')) ?></div>
    <div class="col-6 col-md-4 col-xl"><?= $card('In warehouse', $stats['inWarehouse'], 'Ready to dispatch', site_url('admin/mattresses?status=MANUFACTURED')) ?></div>
    <div class="col-6 col-md-4 col-xl"><?= $card('In transit', $stats['inTransit'], $stats['draftDispatches'] . ' draft dispatch(es)', site_url('admin/dispatches')) ?></div>
    <div class="col-6 col-md-6 col-xl"><?= $card('At dealers', $stats['atDealers'], 'Received, unsold', site_url('admin/mattresses?status=DEALER_RECEIVED')) ?></div>
    <div class="col-12 col-md-6 col-xl"><?= $card('Sold', $stats['sold'], 'Including claimed and replaced', site_url('admin/sales')) ?></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= $card('Open claims', $stats['openClaims'], $stats['highRisk'] . ' high risk', site_url('admin/claims')) ?></div>
    <div class="col-6 col-lg-3"><?= $card('Sales this month', $stats['salesMonth'], $stats['claims30'] . ' claims in 30 days', site_url('admin/sales')) ?></div>
    <div class="col-6 col-lg-3"><?= $card('Active dealers', $stats['activeDealers'], $stats['pendingApplications'] . ' new application(s)', site_url('admin/dealers')) ?></div>
    <div class="col-6 col-lg-3"><?= $card('New leads', $stats['newLeads'], 'From the website', site_url('admin/leads')) ?></div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="panel h-100">
            <div class="panel-head"><h2>Claims waiting for review</h2><a class="small" href="<?= site_url('admin/claims') ?>">All claims</a></div>
            <?php if ($queue === []): ?>
            <div class="table-empty">Nothing waiting. Every open claim has been reviewed.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Claim</th><th>Serial</th><th>Dealer</th><th>Status</th><th>Risk</th><th>Submitted</th></tr></thead>
                <tbody>
                <?php foreach ($queue as $c): ?>
                <tr>
                    <td><a class="fw-semibold" href="<?= site_url('admin/claims/' . $c['id']) ?>"><?= esc($c['claim_number']) ?></a><div class="small text-muted text-truncate" style="max-width:16rem"><?= esc($c['reported_issue']) ?></div></td>
                    <td class="mono"><?= esc($c['serial_number']) ?></td>
                    <td class="small"><?= esc($c['business_name']) ?></td>
                    <td><?= pill($c['status']) ?></td>
                    <td><?= $c['risk_level'] ? pill($c['risk_level'], $c['risk_level'] . ' · ' . $c['risk_score']) : '—' ?></td>
                    <td class="small text-nowrap"><?= esc(local_time($c['submitted_at'], 'd M, h:i a')) ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </div>
    </div>
    <div class="col-xl-4 d-flex flex-column gap-3">
        <div class="panel">
            <div class="panel-head"><h2>Made and sold, last six months</h2></div>
            <div class="panel-body">
                <div class="d-flex align-items-end gap-2" style="height:9rem" role="img" aria-label="Units made and sold per month">
                    <?php foreach ($months as $ym => $m): ?>
                    <div class="flex-fill d-flex flex-column align-items-center justify-content-end h-100">
                        <div class="d-flex align-items-end gap-1 w-100 justify-content-center flex-fill">
                            <span title="Made: <?= $m['made'] ?>" style="width:40%;height:<?= round($m['made'] / $max * 100) ?>%;background:#c9d8d0;border-radius:3px 3px 0 0;min-height:2px"></span>
                            <span title="Sold: <?= $m['sold'] ?>" style="width:40%;height:<?= round($m['sold'] / $max * 100) ?>%;background:var(--accent);border-radius:3px 3px 0 0;min-height:2px"></span>
                        </div>
                        <span class="small text-muted mt-1"><?= date('M', strtotime($ym . '-01')) ?></span>
                    </div>
                    <?php endforeach ?>
                </div>
                <div class="small text-muted mt-2"><span style="display:inline-block;width:.7rem;height:.7rem;background:#c9d8d0"></span> Made &nbsp; <span style="display:inline-block;width:.7rem;height:.7rem;background:var(--accent)"></span> Sold</div>
                <table class="visually-hidden"><caption>Units made and sold per month</caption><tr><th>Month</th><th>Made</th><th>Sold</th></tr>
                    <?php foreach ($months as $ym => $m): ?><tr><td><?= $ym ?></td><td><?= $m['made'] ?></td><td><?= $m['sold'] ?></td></tr><?php endforeach ?></table>
            </div>
        </div>
        <div class="panel flex-fill">
            <div class="panel-head"><h2>Top dealers, 30 days</h2></div>
            <?php if ($topDealers === []): ?><div class="table-empty">No sales in the last 30 days.</div><?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($topDealers as $d): ?>
                <li class="d-flex justify-content-between px-3 py-2 border-bottom"><span><?= esc($d['business_name']) ?> <span class="small text-muted"><?= esc($d['city']) ?></span></span><strong><?= (int) $d['n'] ?></strong></li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

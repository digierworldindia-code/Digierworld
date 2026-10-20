<?= $this->extend('layouts/app') ?>
<?php $rate = static fn (int $a, int $b) => $b > 0 ? round($a * 100 / $b, 1) . '%' : '—'; ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-6 col-md-3"><input type="date" class="form-control form-control-sm" name="from" value="<?= esc($from) ?>" aria-label="From"></div>
    <div class="col-6 col-md-3"><input type="date" class="form-control form-control-sm" name="to" value="<?= esc($to) ?>" aria-label="To"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Show</button></div>
</form>
<div class="table-card mb-3"><div class="table-responsive"><table class="table small align-middle">
    <thead><tr><th>Person</th><th class="num">Calls</th><th class="num">Connected</th><th class="num">Connect rate</th><th class="num">Follow-ups done</th><th class="num">Overdue now</th><th class="num">Meetings booked</th><th class="num">Visits done</th><th class="num">New leads</th><th class="num">Conversions</th><th class="num">Conversion rate</th></tr></thead><tbody>
    <?php foreach ($per as $p): ?>
    <tr><td><?= esc($p['name']) ?></td><td class="num"><?= $p['calls'] ?></td><td class="num"><?= $p['connected'] ?></td><td class="num"><?= $rate($p['connected'], $p['calls']) ?></td>
        <td class="num"><?= $p['followups_done'] ?></td><td class="num <?= $p['overdue'] ? 'text-danger fw-semibold' : '' ?>"><?= $p['overdue'] ?></td><td class="num"><?= $p['meetings'] ?></td>
        <td class="num"><?= $p['visits'] ?></td><td class="num"><?= $p['new_leads'] ?></td><td class="num fw-semibold"><?= $p['conversions'] ?></td><td class="num"><?= $rate($p['conversions'], $p['new_leads']) ?></td></tr>
    <?php endforeach ?>
</tbody></table></div></div>
<p class="small text-muted">Conversion rate = conversions credited ÷ new leads assigned in the period. Conversions are not payouts: incentives follow the approved policy on invoices/collections.</p>
<div class="row g-3">
<div class="col-lg-7"><div class="card h-100"><div class="card-body">
    <div class="card-section-title">Daily calls</div>
    <div class="u-h-260px"><canvas id="chCalls" aria-label="Calls per day"></canvas></div>
    <script type="application/json" id="perf-data"><?= json_encode(['labels' => array_map(static fn ($d) => date('d M', strtotime($d['d'])), $daily), 'calls' => array_map('intval', array_column($daily, 'n')), 'connected' => array_map('intval', array_column($daily, 'c'))]) ?></script>
</div></div></div>
<div class="col-lg-5"><div class="table-card h-100"><div class="card-header"><strong>Source performance</strong> <span class="small text-muted">(leads created in period)</span></div><table class="table small mb-0">
    <thead><tr><th>Source</th><th class="num">Leads</th><th class="num">Reached</th><th class="num">Converted</th><th class="num">Rate</th></tr></thead><tbody>
    <?php foreach ($sources as $s): ?><tr><td><?= esc($s['source']) ?></td><td class="num"><?= (int) $s['leads'] ?></td><td class="num"><?= (int) $s['reached'] ?></td><td class="num"><?= (int) $s['converted'] ?></td><td class="num"><?= $rate((int) $s['converted'], (int) $s['leads']) ?></td></tr><?php endforeach ?>
    <?php if ($sources === []): ?><tr><td colspan="5" class="text-muted">No leads in this period.</td></tr><?php endif ?>
</tbody></table></div></div>
</div>
<?php if (can('crm.settings.manage')): ?>
<div class="row g-3 mt-1">
<div class="col-lg-7"><div class="table-card"><div class="card-header"><strong>Daily targets</strong></div><table class="table small mb-0"><thead><tr><th>Person</th><th>From</th><th class="num">Calls/day</th><th class="num">Connected/day</th><th class="num">Follow-ups/day</th><th class="num">Meetings/week</th></tr></thead><tbody>
    <?php foreach ($targets as $t): ?><tr><td><?= esc((string) $t['full_name']) ?></td><td><?= dmy($t['effective_from']) ?></td><td class="num"><?= (int) $t['calls_per_day'] ?></td><td class="num"><?= (int) $t['connected_per_day'] ?></td><td class="num"><?= (int) $t['followups_per_day'] ?></td><td class="num"><?= (int) $t['meetings_per_week'] ?></td></tr><?php endforeach ?>
    <?php if ($targets === []): ?><tr><td colspan="6" class="text-muted">No targets set.</td></tr><?php endif ?>
</tbody></table></div></div>
<div class="col-lg-5"><form method="post" action="<?= site_url('crm/targets') ?>" class="card"><div class="card-body small"><?= csrf_field() ?>
    <div class="card-section-title">Set target</div>
    <select class="form-select mb-2" name="user_id" aria-label="Person" required><option value="">— person —</option><?php foreach ($allUsers as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['name']) ?></option><?php endforeach ?></select>
    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label" for="tg_from">Effective from</label><input type="date" class="form-control" id="tg_from" name="effective_from" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-6"><label class="form-label" for="tg_calls">Calls / day</label><input type="number" min="0" class="form-control" id="tg_calls" name="calls_per_day" value="60"></div>
        <div class="col-4"><label class="form-label" for="tg_conn">Connected</label><input type="number" min="0" class="form-control" id="tg_conn" name="connected_per_day" value="25"></div>
        <div class="col-4"><label class="form-label" for="tg_fu">Follow-ups</label><input type="number" min="0" class="form-control" id="tg_fu" name="followups_per_day" value="20"></div>
        <div class="col-4"><label class="form-label" for="tg_mt">Meetings/wk</label><input type="number" min="0" class="form-control" id="tg_mt" name="meetings_per_week" value="5"></div></div>
    <button class="btn btn-primary w-100" type="submit">Save target</button>
</div></form></div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= asset('vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= asset('js/crm-performance.js') ?>"></script>
<?= $this->endSection() ?>

<?php
$kinds = ['task' => ['list-check', 'primary'], 'follow-up' => ['telephone-forward', 'warning'], 'visit' => ['geo-alt', 'success'],
    'meeting' => ['calendar-event', 'info'], 'interview' => ['person-video3', 'secondary'], 'leave' => ['calendar-x', 'danger']];
$first  = array_key_first($days);
$lead   = (int) date('N', strtotime($first)) - 1; // Monday-first grid
$cells  = array_merge(array_fill(0, $lead, null), array_keys($days));
$cells  = array_merge($cells, array_fill(0, (7 - count($cells) % 7) % 7, null));
$today  = date('Y-m-d');
$total  = array_sum(array_map(static fn ($d) => count($d['events']), $days));
$chip   = static function (array $e) use ($kinds): string {
    [$icon, $tone] = $kinds[$e['kind']] ?? ['dot', 'secondary'];
    $label = '<i class="bi bi-' . $icon . ' me-1"></i>' . ($e['time'] !== '' ? '<span class="fw-semibold">' . esc($e['time']) . '</span> ' : '') . esc($e['title'])
        . (in_array($e['status'], ['completed', 'done', 'approved'], true) ? ' <i class="bi bi-check2" aria-label="done"></i>' : '')
        . ($e['status'] === 'pending' && $e['kind'] === 'leave' ? ' <span class="text-muted">(pending)</span>' : '');
    $cls = 'cal-chip d-block text-truncate small border-start border-3 border-' . $tone . ' ps-1 mb-1 text-body text-decoration-none';

    return $e['url'] !== null ? '<a class="' . $cls . '" href="' . esc($e['url'], 'attr') . '" title="' . esc($e['title'], 'attr') . '">' . $label . '</a>' : '<span class="' . $cls . '" title="' . esc($e['title'], 'attr') . '">' . $label . '</span>';
};
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<div class="btn-group btn-group-sm" role="group" aria-label="Change month">
    <a class="btn btn-outline-dark" href="<?= site_url('calendar?month=' . $prev) ?>"><i class="bi bi-chevron-left"></i><span class="visually-hidden">Previous month</span></a>
    <a class="btn btn-outline-dark" href="<?= site_url('calendar') ?>">This month</a>
    <a class="btn btn-outline-dark" href="<?= site_url('calendar?month=' . $next) ?>"><i class="bi bi-chevron-right"></i><span class="visually-hidden">Next month</span></a>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-9">
<div class="table-card p-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <h2 class="h5 mb-0"><?= esc(date('F Y', strtotime($first))) ?></h2>
        <div class="small text-muted d-flex flex-wrap gap-2">
            <?php foreach ($kinds as $k => [$icon, $tone]): ?><span><i class="bi bi-<?= $icon ?> text-<?= $tone ?>"></i> <?= ucfirst($k) ?></span><?php endforeach ?>
        </div>
    </div>
    <?php if ($total === 0): ?>
        <div class="alert alert-light border small mb-2">Nothing scheduled for you this month. Tasks, follow-ups, visits, meetings and interviews assigned to you, and your leave, appear here automatically.</div>
    <?php endif ?>

    <div class="d-none d-md-block">
        <div class="cal-grid">
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $w): ?><div class="cal-head small text-muted fw-semibold"><?= $w ?></div><?php endforeach ?>
            <?php foreach ($cells as $d): ?>
                <?php if ($d === null): ?><div class="cal-cell cal-empty"></div><?php continue; endif ?>
                <?php $info = $days[$d] ?>
                <div class="cal-cell<?= $d === $today ? ' cal-today' : '' ?><?= $info['holiday'] !== null || $info['weekOff'] ? ' cal-off' : '' ?>">
                    <div class="d-flex justify-content-between small"><span class="fw-semibold"><?= (int) substr($d, 8) ?></span>
                        <?php if ($info['holiday'] !== null): ?><span class="text-danger text-truncate ms-1" title="<?= esc($info['holiday'], 'attr') ?>"><?= esc($info['holiday']) ?></span><?php elseif ($info['weekOff']): ?><span class="text-muted">Off</span><?php endif ?>
                    </div>
                    <?php foreach (array_slice($info['events'], 0, 4) as $e): ?><?= $chip($e) ?><?php endforeach ?>
                    <?php if (count($info['events']) > 4): ?><div class="small text-muted">+<?= count($info['events']) - 4 ?> more (see list below)</div><?php endif ?>
                </div>
            <?php endforeach ?>
        </div>
    </div>

    <div class="<?= $total > 0 ? 'mt-md-3' : 'd-md-none' ?>">
        <?php if ($total > 0): ?><h3 class="h6 d-none d-md-block mt-2">All items</h3><?php endif ?>
        <div class="list-group list-group-flush">
        <?php foreach ($days as $d => $info): ?>
            <?php if ($info['events'] === [] && $info['holiday'] === null) { continue; } ?>
            <div class="list-group-item px-0<?= $d === $today ? ' bg-gold-soft' : '' ?>">
                <div class="small fw-semibold mb-1"><?= dmy($d) ?> · <?= date('l', strtotime($d)) ?><?= $info['holiday'] !== null ? ' · <span class="text-danger">' . esc($info['holiday']) . '</span>' : '' ?></div>
                <?php foreach ($info['events'] as $e): ?><?= $chip($e) ?><?php endforeach ?>
            </div>
        <?php endforeach ?>
        </div>
    </div>
</div>
</div>
<div class="col-xl-3">
<div class="table-card p-3">
    <h2 class="h6"><i class="bi bi-alarm"></i> My upcoming reminders <span class="text-muted small">(14 days)</span></h2>
    <?php if ($reminders === []): ?>
        <div class="small text-muted">No reminders queued. Reminders are created with follow-ups, tasks and visits and are delivered in-app (and by e-mail when SMTP is set up).</div>
    <?php else: ?>
        <ul class="list-unstyled small mb-0">
        <?php foreach ($reminders as $r): ?>
            <li class="mb-1"><span class="fw-semibold"><?= dmy($r['remind_at'], true) ?></span> — <?= esc(ucfirst(str_replace('_', ' ', $r['entity_type']))) ?> #<?= (int) $r['entity_id'] ?> <span class="text-muted">(<?= esc($r['channel']) ?>)</span></li>
        <?php endforeach ?>
        </ul>
    <?php endif ?>
</div>
</div>
</div>
<?= $this->endSection() ?>

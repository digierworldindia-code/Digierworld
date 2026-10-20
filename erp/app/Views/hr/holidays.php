<?= $this->extend('layouts/app') ?>
<?php $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']; ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-7">
    <div class="d-flex gap-2 align-items-center mb-2 small"><a href="<?= site_url('hr/holidays?year=' . ($year - 1)) ?>">‹ <?= $year - 1 ?></a><strong><?= $year ?></strong><a href="<?= site_url('hr/holidays?year=' . ($year + 1)) ?>"><?= $year + 1 ?> ›</a></div>
    <div class="table-card"><div class="table-responsive"><table class="table small align-middle mb-0">
        <thead><tr><th>Date</th><th>Day</th><th>Holiday</th><th></th><th></th></tr></thead><tbody>
        <?php foreach ($holidays as $h): ?>
        <tr><td><?= dmy($h['holiday_date']) ?></td><td><?= date('l', strtotime($h['holiday_date'])) ?></td><td><?= esc($h['name']) ?></td><td><?= $h['is_optional'] ? '<span class="badge badge-neutral">optional</span>' : '' ?></td>
            <td class="text-end"><form method="post" action="<?= site_url('hr/holidays/' . $h['id'] . '/delete') ?>" data-confirm="Remove this holiday?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">Remove</button></form></td></tr>
        <?php endforeach ?>
        <?php if ($holidays === []): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-calendar-heart"></i>No holidays entered for <?= $year ?>. Add your company's declared holidays — none are assumed.</div></td></tr><?php endif ?>
        </tbody></table></div></div>
</div>
<div class="col-xl-5">
    <form method="post" action="<?= site_url('hr/holidays') ?>" class="card mb-3"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Add holiday</div>
        <div class="row g-2"><div class="col-5"><input type="date" class="form-control" name="holiday_date" required aria-label="Date"></div><div class="col-7"><input class="form-control" name="name" placeholder="e.g. Diwali" required maxlength="120" aria-label="Holiday name"></div></div>
        <div class="form-check my-2"><input class="form-check-input" type="checkbox" name="is_optional" value="1" id="h_opt"><label class="form-check-label" for="h_opt">Optional / restricted holiday (counted as a working day)</label></div>
        <button class="btn btn-primary btn-sm">Save holiday</button>
    </div></form>
    <div class="card"><div class="card-body small">
        <div class="card-section-title">Weekly off policy</div>
        <p>Current: <strong><?= esc(implode(', ', $policy) ?: 'none (every day is a working day)') ?></strong></p>
        <?php foreach ($rules as $r): ?>
        <div class="d-flex justify-content-between border-bottom py-1"><span class="<?= $r['is_active'] ? '' : 'text-muted text-decoration-line-through' ?>"><?= $r['week_numbers'] ? esc($r['week_numbers']) . ' ' : 'Every ' ?><?= $days[(int) $r['weekday']] ?> · from <?= dmy($r['effective_from']) ?></span>
            <form method="post" action="<?= site_url('hr/holidays/rules/' . $r['id'] . '/toggle') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link p-0"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></button></form></div>
        <?php endforeach ?>
        <form method="post" action="<?= site_url('hr/holidays/rules') ?>" class="mt-2"><?= csrf_field() ?>
            <div class="row g-2 align-items-end">
                <div class="col-5"><label class="form-label" for="w_day">Weekday</label><select class="form-select form-select-sm" id="w_day" name="weekday"><?php foreach ($days as $i => $d): ?><option value="<?= $i ?>" <?= $i === 6 ? 'selected' : '' ?>><?= $d ?></option><?php endforeach ?></select></div>
                <div class="col-7"><span class="form-label d-block">Which weeks (none = every week)</span><?php foreach ([1, 2, 3, 4, 5] as $w): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="weeks[]" value="<?= $w ?>" id="w<?= $w ?>"><label class="form-check-label" for="w<?= $w ?>"><?= $w ?></label></div><?php endforeach ?></div>
                <div class="col-6"><label class="form-label" for="w_from">Effective from</label><input type="date" class="form-control form-control-sm" id="w_from" name="effective_from" value="<?= date('Y-m-d') ?>"></div>
                <div class="col-6"><button class="btn btn-primary btn-sm w-100">Add rule</button></div>
            </div>
            <div class="form-text">Example: Saturday + weeks 2 and 4 = 2nd and 4th Saturday off.</div>
        </form>
    </div></div>
</div>
</div>
<?= $this->endSection() ?>

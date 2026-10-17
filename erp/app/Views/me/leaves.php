<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="table-card mb-3"><div class="card-header bg-white"><strong>Balance</strong></div><table class="table small mb-0">
        <thead><tr><th>Type</th><th class="num">Entitled</th><th class="num">Used</th><th class="num">Pending</th><th class="num">Available</th></tr></thead><tbody>
        <?php foreach ($balances as $b): ?><tr><td><?= esc($b['name']) ?><?= $b['is_paid'] ? '' : ' <span class="text-muted">(unpaid)</span>' ?></td><td class="num"><?= \App\Libraries\Dec::add($b['entitled'], $b['carried'], $b['adjusted']) ?></td><td class="num"><?= $b['used'] ?></td><td class="num"><?= $b['pending'] ?></td><td class="num fw-semibold"><?= $b['limited'] ? $b['available'] : '—' ?></td></tr><?php endforeach ?>
        </tbody></table></div>
    <div class="table-card"><div class="card-header bg-white"><strong>My requests</strong></div><div class="table-responsive"><table class="table small align-middle mb-0"><tbody>
        <?php foreach ($requests as $r): ?>
        <tr><td><?= dmy($r['from_date']) ?><?= $r['to_date'] !== $r['from_date'] ? ' – ' . dmy($r['to_date']) : '' ?></td><td><?= esc($r['code']) ?> · <?= $r['days'] ?>d</td><td><?= esc((string) $r['reason']) ?><?= $r['decision_note'] ? '<div class="text-muted">' . esc($r['decision_note']) . '</div>' : '' ?></td><td><?= status_badge($r['status']) ?></td>
            <td class="text-end"><?php if ($r['status'] === 'pending'): ?><form method="post" action="<?= site_url('me/leaves/' . $r['id'] . '/cancel') ?>" data-confirm="Withdraw this request?"><?= csrf_field() ?><button class="btn btn-sm btn-link p-0">Withdraw</button></form><?php endif ?></td></tr>
        <?php endforeach ?>
        <?php if ($requests === []): ?><tr><td class="text-muted">No leave requests yet.</td></tr><?php endif ?>
    </tbody></table></div></div>
</div>
<div class="col-xl-4">
    <form method="post" action="<?= site_url('me/leaves') ?>" class="card mb-3"><div class="card-body small"><?= csrf_field() ?>
        <div class="card-section-title">Request leave</div>
        <label class="form-label required" for="lt">Type</label><select class="form-select mb-2<?= is_invalid('leave_type_id') ?>" id="lt" name="leave_type_id" required><?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= esc($t['name']) ?><?= $t['is_paid'] ? '' : ' (unpaid)' ?></option><?php endforeach ?></select>
        <div class="row g-2 mb-2"><div class="col-6"><label class="form-label required" for="lf">From</label><input type="date" class="form-control<?= is_invalid('from_date') ?>" id="lf" name="from_date" value="<?= esc((string) old('from_date')) ?>" required></div><div class="col-6"><label class="form-label" for="lto">To</label><input type="date" class="form-control<?= is_invalid('to_date') ?>" id="lto" name="to_date" value="<?= esc((string) old('to_date')) ?>"></div></div>
        <label class="form-label" for="lh">Half day</label><select class="form-select mb-2" id="lh" name="half_day"><option value="none">Full day(s)</option><option value="first_half">First half</option><option value="second_half">Second half</option></select>
        <label class="form-label" for="lr">Reason</label><textarea class="form-control mb-2" id="lr" name="reason" rows="2" maxlength="500"><?= esc((string) old('reason')) ?></textarea>
        <button class="btn btn-dark w-100" type="submit">Send request</button>
        <div class="form-text">Holidays and weekly offs in the range are not deducted.</div>
    </div></form>
    <?php if ($holidays): ?><div class="card"><div class="card-body small"><div class="card-section-title">Upcoming holidays</div><?php foreach ($holidays as $h): ?><div><?= dmy($h['holiday_date']) ?> — <?= esc($h['name']) ?></div><?php endforeach ?></div></div><?php endif ?>
</div>
</div>
<?= $this->endSection() ?>

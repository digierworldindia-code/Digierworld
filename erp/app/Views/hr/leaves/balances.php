<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-9">
<div class="table-card"><div class="table-responsive"><table class="table table-sm small mb-0">
    <thead><tr><th>Employee</th><?php foreach ($types as $t): ?><th class="num" title="<?= esc($t['name']) ?>"><?= esc($t['code']) ?> <span class="text-muted fw-normal">avail / used</span></th><?php endforeach ?></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= site_url('hr/employees/' . $r['emp']['id']) ?>"><?= esc($r['emp']['full_name']) ?></a></td>
        <?php foreach ($types as $t): $b = $r['bal'][$t['id']] ?? null; ?><td class="num"><?= $b ? ($b['limited'] ? '<strong>' . $b['available'] . '</strong>' : '—') . ' / ' . $b['used'] . ((float) $b['pending'] ? ' <span class="text-warning">(+' . $b['pending'] . ' pending)</span>' : '') : '—' ?></td><?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody></table></div></div>
</div>
<div class="col-xl-3">
<?php if (can('hr.leave.manage_types')): ?>
<form method="post" action="<?= site_url('hr/leaves/adjust') ?>" class="card"><div class="card-body small"><?= csrf_field() ?>
    <div class="card-section-title">Adjust a balance</div>
    <select class="form-select mb-2" name="employee_id" required aria-label="Employee"><option value="">Employee…</option><?php foreach ($rows as $r): ?><option value="<?= $r['emp']['id'] ?>"><?= esc($r['emp']['full_name']) ?></option><?php endforeach ?></select>
    <select class="form-select mb-2" name="leave_type_id" required aria-label="Leave type"><?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= esc($t['code']) ?></option><?php endforeach ?></select>
    <input class="form-control mb-2" name="days" placeholder="Days (e.g. 2 or -1)" inputmode="decimal" required aria-label="Days">
    <input class="form-control mb-2" name="reason" placeholder="Reason (opening balance, comp-off…)" required aria-label="Reason">
    <button class="btn btn-dark w-100">Adjust</button>
    <div class="form-text">Entitlements per type are set in Settings → Master lists → Leave types. Every adjustment is audited.</div>
</div></form>
<?php endif ?>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>
<?php $p = $edit ?? ['basis' => '', 'calc_type' => 'percent', 'scope_type' => 'role', 'cutoff_day' => 31, 'requires_approval' => 1, 'clawback_on_refund' => 1, 'carry_forward' => 1, 'is_active' => 1, 'effective_from' => date('Y-m-01'), 'min_threshold' => '0.00'];
$v = static fn ($k) => esc((string) (old($k) ?? ($p[$k] ?? ''))); ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-7">
    <div class="table-card"><div class="table-responsive"><table class="table align-middle small mb-0">
        <thead><tr><th>Policy</th><th>Applies to</th><th>Basis</th><th class="num">Rate</th><th>Rules</th><th></th></tr></thead><tbody>
        <?php foreach ($policies as $r): ?>
        <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td class="fw-semibold"><?= esc($r['name']) ?><div class="text-muted fw-normal"><?= dmy($r['effective_from']) ?><?= $r['effective_to'] ? ' – ' . dmy($r['effective_to']) : ' onwards' ?></div></td>
            <td><?= esc($r['scope_type'] === 'all' ? 'All eligible staff' : ($r['scope_type'] === 'role' ? 'Role: ' . ($roles[$r['scope_ref']] ?? $r['scope_ref']) : ($r['scope_type'] === 'user' ? 'User: ' . ($users[$r['scope_ref']] ?? $r['scope_ref']) : 'Service: ' . ($services[$r['scope_ref']] ?? $r['scope_ref'])))) ?></td>
            <td><?= esc($bases[$r['basis']]) ?></td><td class="num"><?= $r['calc_type'] === 'percent' ? (float) $r['value'] . '%' : inr($r['value']) ?></td>
            <td class="text-muted">cut-off day <?= (int) $r['cutoff_day'] ?><?= (float) $r['min_threshold'] ? ' · min ' . inr($r['min_threshold']) : '' ?><?= $r['requires_approval'] ? ' · approval' : '' ?><?= $r['clawback_on_refund'] ? ' · clawback' : '' ?><?= $r['carry_forward'] ? ' · carry fwd' : '' ?><?= $r['is_active'] ? '' : ' · inactive' ?></td>
            <td><a href="<?= site_url('hr/incentives/policies?edit=' . $r['id']) ?>">Edit</a></td></tr>
        <?php endforeach ?>
        <?php if ($policies === []): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-sliders"></i>No policies yet. Example: “Telecaller 10% of collections net of GST” for role Telecaller.</div></td></tr><?php endif ?>
    </tbody></table></div></div>
</div>
<div class="col-xl-5">
<form method="post" action="<?= site_url('hr/incentives/policies' . ($edit ? '/' . $edit['id'] : '')) ?>" class="card"><div class="card-body small"><?= csrf_field() ?>
    <div class="card-section-title"><?= $edit ? 'Edit policy' : 'New policy' ?></div>
    <label class="form-label required" for="ip_name">Name</label><input class="form-control mb-2<?= is_invalid('name') ?>" id="ip_name" name="name" value="<?= $v('name') ?>" required><?= field_error('name') ?>
    <div class="row g-2 mb-2"><div class="col-5"><label class="form-label" for="ip_scope">Applies to</label><select class="form-select" id="ip_scope" name="scope_type"><?php foreach (['all' => 'All eligible staff', 'role' => 'A role', 'user' => 'One person', 'service' => 'A service (invoices)'] as $k => $l): ?><option value="<?= $k ?>" <?= $p['scope_type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-7"><label class="form-label" for="ip_ref">Which one</label><select class="form-select<?= is_invalid('scope_ref') ?>" id="ip_ref" name="scope_ref"><option value="">—</option>
            <optgroup label="Roles"><?php foreach ($roles as $k => $l): ?><option value="<?= esc($k) ?>" <?= ($p['scope_ref'] ?? '') === $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></optgroup>
            <optgroup label="People"><?php foreach ($users as $k => $l): ?><option value="<?= $k ?>" <?= (string) ($p['scope_ref'] ?? '') === (string) $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></optgroup>
            <optgroup label="Services"><?php foreach ($services as $k => $l): ?><option value="<?= $k ?>" <?= (string) ($p['scope_ref'] ?? '') === (string) $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></optgroup></select><?= field_error('scope_ref') ?></div></div>
    <label class="form-label required" for="ip_basis">Calculated on (choose explicitly)</label><select class="form-select mb-2<?= is_invalid('basis') ?>" id="ip_basis" name="basis" required><option value="">—</option><?php foreach ($bases as $k => $l): ?><option value="<?= $k ?>" <?= $p['basis'] === $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select><?= field_error('basis') ?>
    <div class="row g-2 mb-2"><div class="col-5"><label class="form-label" for="ip_calc">Type</label><select class="form-select" id="ip_calc" name="calc_type"><option value="percent">Percentage</option><option value="fixed" <?= $p['calc_type'] === 'fixed' ? 'selected' : '' ?>>Fixed per item</option></select></div>
        <div class="col-3"><label class="form-label required" for="ip_val">Value</label><input class="form-control<?= is_invalid('value') ?>" id="ip_val" name="value" value="<?= $v('value') ?>" inputmode="decimal" required></div>
        <div class="col-4"><label class="form-label" for="ip_min">Min. basis / month</label><input class="form-control" id="ip_min" name="min_threshold" value="<?= $v('min_threshold') ?>" inputmode="decimal"></div></div>
    <div class="row g-2 mb-2"><div class="col-4"><label class="form-label" for="ip_cut">Cut-off day</label><input class="form-control" id="ip_cut" name="cutoff_day" value="<?= $v('cutoff_day') ?>" inputmode="numeric"></div>
        <div class="col-4"><label class="form-label" for="ip_from">From</label><input type="date" class="form-control" id="ip_from" name="effective_from" value="<?= $v('effective_from') ?>"></div>
        <div class="col-4"><label class="form-label" for="ip_to">To</label><input type="date" class="form-control<?= is_invalid('effective_to') ?>" id="ip_to" name="effective_to" value="<?= $v('effective_to') ?>"></div></div>
    <?php foreach (['requires_approval' => 'HR approval required before payroll', 'clawback_on_refund' => 'Claw back on cancellation / credit note / voided receipt', 'carry_forward' => 'Carry approved-but-unpaid entries to later payrolls', 'is_active' => 'Active'] as $k => $l): ?>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="<?= $k ?>" value="1" id="ip_<?= $k ?>" <?= ! empty($p[$k]) ? 'checked' : '' ?>><label class="form-check-label" for="ip_<?= $k ?>"><?= $l ?></label></div>
    <?php endforeach ?>
    <button class="btn btn-dark mt-2">Save policy</button> <?php if ($edit): ?><a class="btn btn-link" href="<?= site_url('hr/incentives/policies') ?>">Cancel</a><?php endif ?>
    <div class="form-text">Only employees marked “eligible for sales incentives” receive entries. Credit goes to the user attributed on the CRM conversion (split shares are honoured).</div>
</div></form>
</div>
</div>
<?= $this->endSection() ?>

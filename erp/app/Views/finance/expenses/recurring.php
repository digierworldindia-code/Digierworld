<?= $this->extend('layouts/app') ?>
<?php $r = $edit; $v = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($r[$k] ?? $d))); ?>
<?= $this->section('content') ?>
<p class="small text-muted">Recurring bills (rent, internet, software subscriptions) are not posted automatically. You get a reminder before the due date and record the actual bill — so the amount, GST and ITC always match the real invoice.</p>
<div class="row g-3">
<div class="col-lg-8">
    <div class="table-card"><div class="table-responsive"><table class="table small align-middle">
        <thead><tr><th>Name</th><th>Category</th><th>Vendor</th><th class="num">Approx. amount</th><th>Frequency</th><th>Next due</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $x): $dueSoon = (int) $x['is_active'] === 1 && $x['next_due_date'] <= date('Y-m-d', strtotime('+' . (int) $x['reminder_days_before'] . ' days')); ?>
        <tr class="<?= (int) $x['is_active'] === 1 ? '' : 'text-muted' ?>">
            <td><?= esc($x['name']) ?></td><td><?= esc($x['category_name']) ?></td><td><?= esc((string) $x['vendor_name']) ?></td><td class="num"><?= inr($x['amount']) ?></td>
            <td><?= esc(ucwords(str_replace('_', ' ', $x['frequency']))) ?></td>
            <td class="<?= $dueSoon ? 'text-danger fw-semibold' : '' ?>"><?= dmy($x['next_due_date']) ?></td>
            <td class="text-end text-nowrap"><?php if ((int) $x['is_active'] === 1 && can('finance.expense.create')): ?><a class="btn btn-sm btn-primary" href="<?= site_url('finance/expenses/new?recurring=' . $x['id']) ?>">Record bill</a><?php endif ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('finance/expenses/recurring?edit=' . $x['id']) ?>">Edit</a></td>
        </tr>
        <?php endforeach ?>
        <?php if ($rows === []): ?><tr><td colspan="7" class="text-muted">No recurring expenses yet.</td></tr><?php endif ?>
        </tbody></table></div></div>
</div>
<div class="col-lg-4">
    <form method="post" action="<?= site_url('finance/expenses/recurring' . ($r ? '/' . $r['id'] : '')) ?>" class="card"><div class="card-body">
        <?= csrf_field() ?>
        <div class="card-section-title"><?= $r ? 'Edit recurring expense' : 'Add recurring expense' ?></div>
        <label class="form-label required" for="rc_name">Name</label><input class="form-control mb-2" id="rc_name" name="name" value="<?= $v('name') ?>" required maxlength="150">
        <label class="form-label required" for="rc_cat">Category</label><select class="form-select mb-2" id="rc_cat" name="category_id" required><option value="">—</option><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= (string) ($r['category_id'] ?? old('category_id')) === (string) $c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option><?php endforeach ?></select>
        <label class="form-label" for="rc_vendor">Vendor</label><select class="form-select mb-2" id="rc_vendor" name="vendor_contact_id"><option value="">—</option><?php foreach ($vendors as $c): ?><option value="<?= $c['id'] ?>" <?= (string) ($r['vendor_contact_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option><?php endforeach ?></select>
        <div class="row g-2 mb-2"><div class="col-6"><label class="form-label required" for="rc_amt">Approx. amount</label><input class="form-control" id="rc_amt" name="amount" inputmode="decimal" value="<?= $v('amount') ?>" required></div>
            <div class="col-6"><label class="form-label" for="rc_freq">Frequency</label><select class="form-select" id="rc_freq" name="frequency"><?php foreach (['monthly', 'quarterly', 'half_yearly', 'yearly'] as $fq): ?><option value="<?= $fq ?>" <?= ($r['frequency'] ?? '') === $fq ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $fq)) ?></option><?php endforeach ?></select></div></div>
        <div class="row g-2 mb-2"><div class="col-6"><label class="form-label required" for="rc_due">Next due</label><input type="date" class="form-control" id="rc_due" name="next_due_date" value="<?= $v('next_due_date') ?>" required></div>
            <div class="col-6"><label class="form-label" for="rc_rem">Remind days before</label><input type="number" min="0" max="60" class="form-control" id="rc_rem" name="reminder_days_before" value="<?= $v('reminder_days_before', '5') ?>"></div></div>
        <label class="form-label" for="rc_notes">Notes</label><input class="form-control mb-2" id="rc_notes" name="notes" value="<?= $v('notes') ?>" maxlength="500">
        <?php if ($r): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="rc_active" <?= (int) $r['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="rc_active">Active</label></div><?php endif ?>
        <button class="btn btn-primary w-100" type="submit">Save</button>
    </div></form>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>
<?php
$v = static fn (string $k, $d = '') => esc((string) (old($k) ?? ($p[$k] ?? $d)));
$rows = old('lines') ?? ($lines ?: [['qty' => '1', 'gst_rate' => '18', 'tax_treatment' => 'regular']]);
?>
<?= $this->section('pageActions') ?>
<?php if ($p && (int) $p['is_active'] === 1): ?>
<form method="post" action="<?= site_url('billing/recurring/' . $p['id'] . '/generate') ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-lightning"></i> Generate draft for <?= dmy($p['next_run_date']) ?> period now</button></form>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url($p ? 'billing/recurring/' . $p['id'] : 'billing/recurring') ?>" data-recurring-form>
<?= csrf_field() ?>
<script type="application/json" id="recurring-data"><?= json_encode(array_column($services, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<div class="row g-3">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label required" for="name">Retainer name</label><input class="form-control<?= is_invalid('name') ?>" id="name" name="name" value="<?= $v('name') ?>" required maxlength="150" placeholder="e.g. SEO + Social retainer — Acme"><?= field_error('name') ?></div>
        <div class="col-md-6"><label class="form-label required" for="contact_id">Client</label><select class="form-select<?= is_invalid('contact_id') ?>" id="contact_id" name="contact_id" required><option value="">— choose —</option><?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>" <?= (string) (old('contact_id') ?? ($p['contact_id'] ?? '')) === (string) $c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option><?php endforeach ?></select><?= field_error('contact_id') ?></div>
        <div class="col-md-4"><label class="form-label" for="doc_type">Generates</label><select class="form-select" id="doc_type" name="doc_type"><?php foreach (['tax_invoice' => 'Tax invoice draft', 'cash_memo' => 'Cash memo draft', 'proforma' => 'Proforma invoice draft'] as $k => $l): ?><option value="<?= $k ?>" <?= ($p['doc_type'] ?? old('doc_type')) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
        <div class="col-md-4"><label class="form-label required" for="frequency">Frequency</label><select class="form-select" id="frequency" name="frequency"><?php foreach (['monthly', 'quarterly', 'half_yearly', 'yearly'] as $f): ?><option value="<?= $f ?>" <?= ($p['frequency'] ?? old('frequency')) === $f ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $f)) ?></option><?php endforeach ?></select></div>
        <div class="col-md-4"><label class="form-label required" for="start_date">Start date</label><input type="date" class="form-control<?= is_invalid('start_date') ?>" id="start_date" name="start_date" value="<?= $v('start_date', date('Y-m-01')) ?>" required><?= field_error('start_date') ?></div>
        <div class="col-md-4"><label class="form-label" for="next_run_date">Next draft for period starting</label><input type="date" class="form-control" id="next_run_date" name="next_run_date" value="<?= $v('next_run_date') ?>"><div class="form-text">Blank = start date.</div></div>
        <div class="col-md-4"><label class="form-label" for="end_date">End date</label><input type="date" class="form-control<?= is_invalid('end_date') ?>" id="end_date" name="end_date" value="<?= $v('end_date') ?>"><?= field_error('end_date') ?></div>
        <div class="col-md-4"><label class="form-label" for="installments_total">Number of bills (optional)</label><input type="number" min="1" class="form-control" id="installments_total" name="installments_total" value="<?= $v('installments_total') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="pos">Place of supply</label><select class="form-select" id="pos" name="place_of_supply_state_code"><option value="">From client address</option><?php foreach ($states as $s): ?><option value="<?= $s['code'] ?>" <?= ($p['place_of_supply_state_code'] ?? old('place_of_supply_state_code')) === $s['code'] ? 'selected' : '' ?>><?= esc($s['code'] . ' — ' . $s['name']) ?></option><?php endforeach ?></select></div>
        <div class="col-md-4"><label class="form-label" for="gst_type">GST type</label><select class="form-select" id="gst_type" name="gst_type"><option value="">Decide on each draft</option><?php foreach (['cgst_sgst' => 'CGST + SGST', 'cgst_utgst' => 'CGST + UTGST', 'igst' => 'IGST', 'none' => 'No GST'] as $k => $l): ?><option value="<?= $k ?>" <?= ($p['gst_type'] ?? old('gst_type')) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select><div class="form-text">Every draft is still checked against place of supply before finalizing.</div></div>
        <div class="col-md-4"><label class="form-label" for="renewal_reminder_days">Renewal reminder (days before end)</label><input type="number" min="0" max="90" class="form-control" id="renewal_reminder_days" name="renewal_reminder_days" value="<?= $v('renewal_reminder_days', '15') ?>"></div>
        <div class="col-md-8"><label class="form-label" for="notes">Notes printed on each draft</label><input class="form-control" id="notes" name="notes" value="<?= $v('notes') ?>"></div>
        <?php if ($p): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" <?= (int) $p['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div></div><?php endif ?>
    </div></div></div>
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Lines on each draft</div><?= field_error('lines') ?>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th class="u-mnw-220px">Service / description</th><th>SAC</th><th class="u-w-80px">Qty</th><th class="u-w-90px">Unit</th><th class="u-w-130px">Rate (₹)</th><th class="u-w-90px">GST %</th></tr></thead>
            <tbody data-rlines>
            <?php foreach ($rows as $i => $l): ?>
            <tr><td><select class="form-select form-select-sm mb-1" name="lines[<?= $i ?>][service_id]" data-svc aria-label="Service"><option value="">— catalogue —</option><?php foreach ($services as $s): ?><option value="<?= $s['id'] ?>" <?= (string) ($l['service_id'] ?? '') === (string) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?></select>
                <input class="form-control form-control-sm" name="lines[<?= $i ?>][item_name]" value="<?= esc((string) ($l['item_name'] ?? '')) ?>" placeholder="Item name" aria-label="Item name" data-f="item_name"></td>
                <td><input class="form-control form-control-sm" name="lines[<?= $i ?>][sac_code]" value="<?= esc((string) ($l['sac_code'] ?? '')) ?>" aria-label="SAC" data-f="sac_code"></td>
                <td><input class="form-control form-control-sm" name="lines[<?= $i ?>][qty]" value="<?= esc((string) ($l['qty'] ?? '1')) ?>" aria-label="Qty" data-f="qty"></td>
                <td><input class="form-control form-control-sm" name="lines[<?= $i ?>][unit]" value="<?= esc((string) ($l['unit'] ?? '')) ?>" aria-label="Unit" data-f="unit"></td>
                <td><input class="form-control form-control-sm text-end" name="lines[<?= $i ?>][unit_rate]" value="<?= esc((string) ($l['unit_rate'] ?? '')) ?>" inputmode="decimal" aria-label="Rate" data-f="unit_rate"></td>
                <td><input class="form-control form-control-sm" name="lines[<?= $i ?>][gst_rate]" value="<?= esc((string) ($l['gst_rate'] ?? '18')) ?>" aria-label="GST rate" data-f="gst_rate"><input type="hidden" name="lines[<?= $i ?>][tax_treatment]" value="<?= esc((string) ($l['tax_treatment'] ?? 'regular')) ?>"></td></tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-radd><i class="bi bi-plus"></i> Line</button>
    </div></div>
</div>
<div class="col-xl-4">
    <?php if ($p): ?>
    <div class="table-card"><div class="card-header"><strong>Generated documents</strong></div><table class="table small mb-0"><tbody>
        <?php foreach ($documents as $d): ?><tr><td><a class="mono" href="<?= site_url('billing/documents/' . $d['id']) ?>"><?= esc($d['document_number'] ?? $d['draft_ref']) ?></a><div class="text-muted"><?= dmy($d['service_period_from']) ?> – <?= dmy($d['service_period_to']) ?></div></td><td><?= status_badge($d['status']) ?></td><td class="num"><?= inr($d['grand_total']) ?></td></tr><?php endforeach ?>
        <?php if ($documents === []): ?><tr><td class="text-muted">None yet.</td></tr><?php endif ?>
    </tbody></table></div>
    <?php endif ?>
</div>
</div>
<div class="sticky-actions"><a href="<?= site_url('billing/recurring') ?>" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-primary" type="submit">Save retainer</button></div>
</form>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= asset('js/recurring-form.js') ?>"></script><?= $this->endSection() ?>

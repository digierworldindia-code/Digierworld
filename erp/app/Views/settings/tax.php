<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php if ($wizard): ?><?= view('settings/_wizard', ['current' => 'tax']) ?><?php endif ?>
<div class="alert alert-warning small"><i class="bi bi-person-check"></i> <strong>Accountant review required before go-live.</strong> Seeded rates and service SAC codes are suggestions only. Have your accountant confirm the GST rate, SAC, e-invoicing applicability, reverse-charge cases and return configuration, then mark each rate “Accountant reviewed”. The app never assumes every agency service uses one rate.</div>
<div class="row g-3">
<div class="col-xl-7">
    <div class="table-card mb-3">
        <div class="p-3 border-bottom"><strong>GST rates</strong> <span class="small text-muted">— total rate; the app splits it into CGST+SGST (½ each) or IGST (full) based on place of supply</span></div>
        <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
            <thead><tr><th>Name</th><th class="num">Rate %</th><th class="num">Cess %</th><th>Effective</th><th>Review</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rates as $r): ?>
            <tr>
                <td colspan="6">
                <form method="post" action="<?= site_url('settings/tax/rates/' . $r['id']) ?>" class="row g-1 align-items-center">
                    <?= csrf_field() ?>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="name" value="<?= esc($r['name']) ?>" aria-label="Name"></div>
                    <div class="col-md-1"><input class="form-control form-control-sm text-end" name="rate" value="<?= esc(rtrim(rtrim($r['rate'], '0'), '.')) ?>" aria-label="Rate"></div>
                    <div class="col-md-1"><input class="form-control form-control-sm text-end" name="cess_rate" value="<?= esc(rtrim(rtrim($r['cess_rate'], '0'), '.')) ?>" aria-label="Cess"></div>
                    <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="effective_from" value="<?= esc($r['effective_from']) ?>" aria-label="From"></div>
                    <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="effective_to" value="<?= esc((string) $r['effective_to']) ?>" aria-label="To"></div>
                    <div class="col-md-2"><select class="form-select form-select-sm" name="review_status" aria-label="Review"><option value="suggested" <?= $r['review_status'] === 'suggested' ? 'selected' : '' ?>>Suggested</option><option value="accountant_reviewed" <?= $r['review_status'] === 'accountant_reviewed' ? 'selected' : '' ?>>Accountant reviewed</option></select></div>
                    <div class="col-md-1 form-check text-center"><input type="checkbox" class="form-check-input" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?> title="Active" aria-label="Active"></div>
                    <div class="col-md-1"><button class="btn btn-sm btn-dark w-100">Save</button></div>
                    <?php if ($r['reviewer']): ?><div class="col-12 text-muted">Reviewed by <?= esc($r['reviewer']) ?> on <?= dmy($r['reviewed_at']) ?></div><?php endif ?>
                </form>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <form method="post" action="<?= site_url('settings/tax/rates') ?>" class="row g-1 p-3 border-top align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-3"><label class="form-label small" for="nr_name">New rate name</label><input class="form-control form-control-sm" id="nr_name" name="name" placeholder="GST 18%"></div>
            <div class="col-md-2"><label class="form-label small" for="nr_rate">Rate %</label><input class="form-control form-control-sm" id="nr_rate" name="rate" required></div>
            <div class="col-md-2"><label class="form-label small" for="nr_cess">Cess %</label><input class="form-control form-control-sm" id="nr_cess" name="cess_rate" value="0"></div>
            <div class="col-md-3"><label class="form-label small" for="nr_from">Effective from</label><input type="date" class="form-control form-control-sm" id="nr_from" name="effective_from" required></div>
            <input type="hidden" name="is_active" value="1">
            <div class="col-md-2"><button class="btn btn-sm btn-gold w-100">Add rate</button></div>
        </form>
    </div>
</div>
<div class="col-xl-5">
    <div class="card mb-3"><div class="card-body">
        <div class="card-section-title">Tax treatments (per line)</div>
        <p class="small text-muted">Treatments other than “Regular taxable” require a reason on the document and are reported separately. Disable any your business never uses.</p>
        <?php foreach ($treatments as $t): ?>
            <form method="post" action="<?= site_url('settings/tax/treatments/' . $t['code']) ?>" class="border-bottom py-2">
                <?= csrf_field() ?>
                <div class="d-flex justify-content-between align-items-center">
                    <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="tt_<?= esc($t['code']) ?>" <?= $t['is_enabled'] ? 'checked' : '' ?>><label class="form-check-label small fw-semibold" for="tt_<?= esc($t['code']) ?>"><?= esc($t['label']) ?></label></div>
                    <button class="btn btn-sm btn-outline-dark">Save</button>
                </div>
                <div class="small text-muted">GSTR-1: <?= esc((string) $t['gstr1_section']) ?> · <?= $t['charges_tax'] ? 'GST charged' : 'no GST charged' ?></div>
                <input class="form-control form-control-sm mt-1" name="notes" value="<?= esc((string) $t['notes']) ?>" aria-label="Notes">
            </form>
        <?php endforeach ?>
    </div></div>
    <div class="card"><div class="card-body small">
        <div class="card-section-title">Service catalogue SAC review</div>
        <?php foreach ($services as $s): ?><div><?= status_badge($s['review_status']) ?> <?= (int) $s['n'] ?> services</div><?php endforeach ?>
        <a href="<?= site_url('services') ?>" class="d-inline-block mt-2">Review services & SAC codes →</a>
    </div></div>
</div>
</div>
<?php if ($wizard): ?><div class="sticky-actions"><a href="<?= site_url('settings/sequences?wizard=1') ?>" class="btn btn-dark">Continue to document series →</a></div><?php endif ?>
<?= $this->endSection() ?>

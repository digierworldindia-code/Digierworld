<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $a */ ?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/applications') ?>">Applications</a></div>
    <h1><?= esc($a['business_name']) ?> <?= pill($a['status']) ?></h1>
    <p>Received <?= esc(local_time($a['created_at'])) ?><?= (int) $a['spam_score'] >= 50 ? ' · flagged as likely spam (score ' . (int) $a['spam_score'] . ')' : '' ?></p></div>
</div>
<div class="row g-3">
    <div class="col-xl-7">
        <div class="panel"><div class="panel-head"><h2>What they sent</h2></div><div class="panel-body"><dl class="dl-grid">
            <dt>Owner</dt><dd><?= esc($a['owner_name']) ?></dd>
            <dt>Mobile</dt><dd><a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $a['mobile']), 'attr') ?>"><?= esc($a['mobile']) ?></a></dd>
            <dt>Email</dt><dd><a href="mailto:<?= esc($a['email'], 'attr') ?>"><?= esc($a['email']) ?></a></dd>
            <dt>Address</dt><dd><?= esc($a['address']) ?></dd>
            <dt>City / state</dt><dd><?= esc($a['city']) ?>, <?= esc($a['state']) ?></dd>
            <dt>GSTIN</dt><dd><?= esc($a['gst_number'] ?? '—') ?></dd>
            <dt>Existing business</dt><dd><?= $a['has_existing_business'] ? 'Yes' : 'No' ?><?= $a['existing_business_details'] ? ' — ' . esc($a['existing_business_details']) : '' ?></dd>
            <dt>Message</dt><dd style="white-space:pre-line"><?= esc($a['message'] ?? '—') ?></dd>
        </dl></div></div>
        <?php if ($a['review_notes']): ?>
        <div class="panel"><div class="panel-head"><h2>Review</h2></div><div class="panel-body">
            <p class="small mb-0"><?= esc($a['reviewed_by_name'] ?? 'Reviewed') ?>, <?= esc(local_time($a['reviewed_at'])) ?>: <?= esc($a['review_notes']) ?></p>
            <?php if ($a['dealer_id']): ?><p class="small mt-2 mb-0">Dealership <a href="<?= site_url('admin/dealers/' . $a['dealer_id']) ?>"><?= esc($a['dealer_code']) ?></a> was created from this application.</p><?php endif ?>
        </div></div>
        <?php endif ?>
    </div>
    <div class="col-xl-5">
        <?php if ($ctx->can('dealer_application:review') && $a['status'] !== 'APPROVED'): ?>
        <form class="panel" method="post" action="<?= site_url('admin/applications/' . $a['id'] . '/review') ?>" data-confirm="Record this decision?">
            <div class="panel-head"><h2>Decide</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <div class="mb-3"><label class="form-label" for="decision">Decision</label>
                    <select class="form-select" id="decision" name="decision" required>
                        <option value="APPROVED">Approve and create the dealership</option>
                        <option value="INFO_REQUESTED">Ask for more information</option>
                        <option value="REJECTED">Reject</option>
                    </select></div>
                <p class="small text-muted">On approval these details become the dealership record. The PIN code is not asked for on the website, so set it here.</p>
                <div class="row g-2 mb-3">
                    <div class="col-5"><label class="form-label" for="city">City</label><input class="form-control" id="city" name="city" maxlength="80" value="<?= esc($a['city'], 'attr') ?>"></div>
                    <div class="col-4"><label class="form-label" for="state">State</label><input class="form-control" id="state" name="state" maxlength="80" value="<?= esc($a['state'], 'attr') ?>"></div>
                    <div class="col-3"><label class="form-label" for="pincode">PIN</label><input class="form-control" id="pincode" name="pincode" inputmode="numeric" maxlength="6"></div>
                </div>
                <div class="form-check"><input type="hidden" name="public_listed" value="0"><input class="form-check-input" type="checkbox" id="public_listed" name="public_listed" value="1"><label class="form-check-label" for="public_listed">List on the public dealer locator</label></div>
                <div class="form-check mb-3"><input type="hidden" name="is_showroom" value="0"><input class="form-check-input" type="checkbox" id="is_showroom" name="is_showroom" value="1"><label class="form-check-label" for="is_showroom">Has a showroom</label></div>
                <div class="mb-3"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"></textarea></div>
                <button class="btn btn-primary" type="submit">Record decision</button>
            </div>
        </form>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

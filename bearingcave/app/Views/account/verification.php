<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php
$c   = $company;
$sup = $c['company_type'] === 'supplier';
$status = $app['status'] ?? 'not_started';
$canSubmit = in_array($status, ['not_started', 'draft', 'changes_requested'], true) && service('companyContext')->can('company.verification');
$docTypes = ['registration_certificate' => 'Company registration certificate', 'gst_certificate' => 'GST certificate', 'pan_card' => 'PAN card', 'tax_registration' => 'Tax / VAT registration', 'bank_letter' => 'Bank letter / cancelled cheque', 'address_proof' => 'Address proof', 'director_id' => 'Director / owner ID', 'financial_statement' => 'Financial statement', 'quality_manual' => 'Quality manual', 'insurance' => 'Insurance certificate', 'other' => 'Other'];
?>
<?= view('components/page_header', ['title' => ($sup ? 'Supplier' : 'Buyer') . ' verification', 'subtitle' => $sup ? 'Complete each section, then submit. Verification approval and membership payment are separate steps.' : 'Verified buyers gain a badge and become eligible for restricted inventory and enhanced sourcing features.']) ?>

<div class="row g-4">
    <div class="col-xl-4 order-xl-2">
        <div class="bc-card mb-3"><div class="bc-card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0">Application status</h2><?= status_badge($status === 'not_started' ? 'draft' : $status, $status === 'not_started' ? 'Not started' : null) ?></div>
            <?php if ($app && $app['decision_notes'] && in_array($status, ['changes_requested', 'rejected'], true)): ?>
                <div class="alert alert-warning small"><strong>Reviewer feedback:</strong> <?= esc($app['decision_notes']) ?></div>
            <?php endif ?>
            <?php if ($stages): ?>
                <div class="stage-list">
                <?php foreach ($stages as $s): ?>
                    <div class="stage">
                        <span class="stage-dot <?= esc($s['status'], 'attr') ?>"><i class="bi <?= ['passed' => 'bi-check2', 'waived' => 'bi-check2', 'failed' => 'bi-x', 'in_review' => 'bi-hourglass-split', 'not_applicable' => 'bi-dash'][$s['status']] ?? 'bi-circle' ?>" aria-hidden="true"></i></span>
                        <div class="small"><div class="fw-semibold"><?= esc($stageNames[$s['stage_key']]) ?></div><div class="text-muted"><?= esc(ucfirst(str_replace('_', ' ', $s['status']))) ?><?= $s['comments'] && $s['status'] === 'failed' ? ' — ' . esc($s['comments']) : '' ?></div></div>
                    </div>
                <?php endforeach ?>
                </div>
            <?php else: ?>
                <p class="small text-muted">Required stages:</p>
                <ul class="small"><?php foreach ($required as $k): ?><li><?= esc($stageNames[$k]) ?></li><?php endforeach ?></ul>
            <?php endif ?>
            <?php if ($canSubmit): ?>
                <?php if ($missing): ?>
                    <div class="alert alert-light border small mt-3 mb-2"><strong>Before submitting, add:</strong><ul class="mb-0 ps-3"><?php foreach ($missing as $m): ?><li><?= esc($m) ?></li><?php endforeach ?></ul></div>
                <?php endif ?>
                <form method="post" action="<?= site_url($area . '/verification/submit') ?>" data-confirm="Submit your application for review? You can still upload documents requested by reviewers.">
                    <?= csrf_field() ?><button class="btn btn-primary w-100 mt-2" type="submit"<?= $missing ? ' disabled' : '' ?>>Submit for verification</button>
                </form>
            <?php elseif ($status === 'approved' && $sup): ?>
                <div class="alert alert-success small mt-3 mb-0">Approved. <?= entitled('verified_badge') ? 'Your Verified Supplier membership is active.' : 'Pay your membership invoice to activate the badge.' ?> <a href="<?= site_url('supplier/membership') ?>">Membership</a></div>
            <?php elseif ($status === 'approved'): ?>
                <div class="alert alert-success small mt-3 mb-0">Your buyer account is verified. <?= esc((string) $restrictedPolicy) ?></div>
            <?php endif ?>
        </div></div>
        <?php if ($events): ?>
        <div class="bc-card"><div class="bc-card-body"><h2 class="h6">History</h2>
            <ul class="timeline small"><?php foreach ($events as $e): ?><li><div class="fw-semibold"><?= esc(ucfirst(str_replace('_', ' ', $e['action']))) ?><?= $e['stage_key'] ? ' · ' . esc($stageNames[$e['stage_key']] ?? $e['stage_key']) : '' ?></div><div class="when"><?= fdt($e['created_at']) ?></div></li><?php endforeach ?></ul>
        </div></div>
        <?php endif ?>
    </div>

    <div class="col-xl-8 order-xl-1">
        <section id="kyc" class="bc-card mb-4">
            <div class="bc-card-header"><h2>KYC / KYB details</h2><?= $kyc ? status_badge($kyc['tax_verified'], 'Tax: ' . $kyc['tax_verified']) : '' ?></div>
            <div class="bc-card-body">
                <form method="post" action="<?= site_url($area . '/verification/kyc') ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-4"><?= field('gst_number', 'GST number', $kyc['gst_number'] ?? '', ['class' => '']) ?></div>
                        <div class="col-md-4"><?= field('pan_number', 'PAN', $kyc['pan_number'] ?? '', ['class' => '']) ?></div>
                        <div class="col-md-4"><?= field('cin_number', 'CIN', $kyc['cin_number'] ?? '', ['class' => '']) ?></div>
                        <div class="col-md-6"><?= field('vat_number', 'VAT number (outside India)', $kyc['vat_number'] ?? '', ['class' => '']) ?></div>
                        <div class="col-md-6"><?= field('tax_id', 'Other tax identification', $kyc['tax_id'] ?? '', ['class' => '']) ?></div>
                    </div>
                    <hr>
                    <p class="small-caps mb-2">Bank account <?= $sup ? '(required for suppliers)' : '(optional)' ?></p>
                    <div class="row g-3">
                        <div class="col-md-6"><?= field('bank_account_name', 'Account holder name', $kyc['bank_account_name'] ?? '', ['class' => '']) ?></div>
                        <div class="col-md-6"><?= field('bank_account_number', 'Account number', '', ['class' => '', 'placeholder' => ! empty($kyc['bank_account_last4']) ? 'Saved: ••••' . $kyc['bank_account_last4'] . ' (leave blank to keep)' : '', 'attrs' => 'autocomplete="off"']) ?></div>
                        <div class="col-md-5"><?= field('bank_name', 'Bank name', $kyc['bank_name'] ?? '', ['class' => '']) ?></div>
                        <div class="col-md-4"><?= field('bank_ifsc_swift', 'IFSC / SWIFT', $kyc['bank_ifsc_swift'] ?? '', ['class' => '']) ?></div>
                        <div class="col-md-3"><?= select_field('bank_country', 'Bank country', country_options(), $kyc['bank_country'] ?? $c['country_code'], ['class' => '']) ?></div>
                    </div>
                    <p class="small text-muted mt-2"><i class="bi bi-lock me-1" aria-hidden="true"></i>Account numbers are encrypted at rest and only visible to authorised BearingCave verification staff.</p>
                    <button class="btn btn-primary" type="submit">Save KYC details</button>
                </form>
            </div>
        </section>

        <section id="directors" class="bc-card mb-4">
            <div class="bc-card-header"><h2>Directors, owners &amp; beneficial owners</h2></div>
            <div class="bc-card-body">
                <?php if ($directors): ?>
                <div class="table-responsive mb-3"><table class="table table-bc table-stack">
                    <thead><tr><th>Name</th><th>Role</th><th>Ownership</th><th>UBO</th><th>Status</th><th></th></tr></thead>
                    <tbody><?php foreach ($directors as $d): ?><tr><td data-label="Name"><?= esc($d['full_name']) ?></td><td data-label="Role"><?= esc($d['designation'] ?? '—') ?></td><td data-label="Ownership"><?= $d['ownership_percent'] !== null ? esc($d['ownership_percent']) . '%' : '—' ?></td><td data-label="UBO"><?= $d['is_ubo'] ? 'Yes' : 'No' ?></td><td data-label="Status"><?= status_badge($d['verification_status']) ?></td><td><?= post_button($area . '/verification/directors/' . $d['id'] . '/delete', 'Remove', 'btn btn-sm btn-link text-danger p-0', 'Remove this person?') ?></td></tr><?php endforeach ?></tbody>
                </table></div>
                <?php endif ?>
                <form method="post" action="<?= site_url($area . '/verification/directors') ?>" class="row g-2 align-items-end" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-md-4"><?= field('full_name', 'Full name', null, ['required' => true, 'class' => '']) ?></div>
                    <div class="col-md-3"><?= field('designation', 'Designation', null, ['class' => '']) ?></div>
                    <div class="col-md-2"><?= field('ownership_percent', 'Ownership %', null, ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-md-3"><?= select_field('nationality', 'Nationality', country_options(), null, ['class' => '', 'placeholder' => 'Select']) ?></div>
                    <div class="col-md-4"><?= select_field('id_document_type', 'ID document', ['passport' => 'Passport', 'national_id' => 'National ID', 'pan' => 'PAN', 'driving_licence' => 'Driving licence', 'din' => 'DIN'], null, ['class' => '', 'placeholder' => 'Select']) ?></div>
                    <div class="col-md-4"><?= field('id_document_number', 'ID number', null, ['class' => '', 'attrs' => 'autocomplete="off"']) ?></div>
                    <div class="col-md-4"><?= check_field('is_ubo', 'Ultimate beneficial owner', false) ?><?= check_field('is_pep_declared', 'Politically exposed person', false) ?></div>
                    <div class="col-12"><button class="btn btn-outline-primary btn-sm" type="submit">Add person</button></div>
                </form>
            </div>
        </section>

        <?php if ($sup): ?>
        <section id="compliance" class="bc-card mb-4">
            <div class="bc-card-header"><h2>Compliance &amp; certifications</h2></div>
            <div class="bc-card-body">
                <?php if ($certs): ?>
                <div class="table-responsive mb-3"><table class="table table-bc table-stack">
                    <thead><tr><th>Certification</th><th>Number</th><th>Valid until</th><th>Status</th><th>File</th></tr></thead>
                    <tbody><?php foreach ($certs as $ct): ?><tr><td data-label="Certification"><?= esc($ct['cert_type']) ?><?= $ct['cert_name'] ? ' — ' . esc($ct['cert_name']) : '' ?></td><td data-label="Number"><?= esc($ct['cert_number'] ?? '—') ?></td><td data-label="Valid until"><?= fdate($ct['expires_on']) ?></td><td data-label="Status"><?= status_badge($ct['status']) ?></td><td data-label="File"><?php if ($ct['document_id'] && ($doc = db_connect()->table('documents')->select('uuid')->where('id', $ct['document_id'])->get()->getRowArray())): ?><a href="<?= site_url('documents/' . $doc['uuid']) ?>">View</a><?php else: ?>—<?php endif ?></td></tr><?php endforeach ?></tbody>
                </table></div>
                <?php endif ?>
                <form method="post" action="<?= site_url($area . '/verification/certifications') ?>" enctype="multipart/form-data" class="row g-2" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-md-4"><?= select_field('cert_type', 'Type', ['ISO9001' => 'ISO 9001', 'IATF16949' => 'IATF 16949', 'AS9100' => 'AS9100', 'ISO14001' => 'ISO 14001', 'ISO45001' => 'ISO 45001', 'RoHS' => 'RoHS', 'REACH' => 'REACH', 'ESG' => 'ESG report / rating', 'INSURANCE' => 'Insurance', 'LABOR' => 'Labour compliance', 'OTHER' => 'Other'], null, ['required' => true, 'class' => '']) ?></div>
                    <div class="col-md-4"><?= field('cert_number', 'Certificate number', null, ['class' => '']) ?></div>
                    <div class="col-md-4"><?= field('issuer', 'Issued by', null, ['class' => '']) ?></div>
                    <div class="col-md-3"><?= field('issued_on', 'Issued on', null, ['type' => 'date', 'class' => '']) ?></div>
                    <div class="col-md-3"><?= field('expires_on', 'Expires on', null, ['type' => 'date', 'class' => '']) ?></div>
                    <div class="col-md-6"><label class="form-label" for="certificate">Certificate file (PDF/JPG/PNG)</label><input class="form-control" type="file" id="certificate" name="certificate" accept=".pdf,.jpg,.jpeg,.png"></div>
                    <div class="col-12"><button class="btn btn-outline-primary btn-sm" type="submit">Add certification</button></div>
                </form>
            </div>
        </section>

        <section id="financial" class="bc-card mb-4">
            <div class="bc-card-header"><h2>Financial information</h2><?= $financial ? status_badge($financial['status']) : '' ?></div>
            <div class="bc-card-body">
                <form method="post" action="<?= site_url($area . '/verification/financial') ?>" class="row g-3" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-md-3"><?= field('fiscal_year', 'Financial year', $financial['fiscal_year'] ?? '', ['class' => '', 'placeholder' => '2025-26']) ?></div>
                    <div class="col-md-3"><?= select_field('currency', 'Currency', currency_options(), $financial['currency'] ?? 'INR', ['class' => '', 'required' => true]) ?></div>
                    <div class="col-md-3"><?= field('annual_turnover', 'Annual turnover', $financial['annual_turnover'] ?? '', ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-md-3"><?= field('net_profit', 'Net profit', $financial['net_profit'] ?? '', ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-md-3"><?= field('total_assets', 'Total assets', $financial['total_assets'] ?? '', ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-md-3"><?= field('total_liabilities', 'Total liabilities', $financial['total_liabilities'] ?? '', ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-md-3"><?= field('credit_rating', 'Credit rating', $financial['credit_rating'] ?? '', ['class' => '']) ?></div>
                    <div class="col-md-3"><?= field('credit_agency', 'Rating agency', $financial['credit_agency'] ?? '', ['class' => '']) ?></div>
                    <div class="col-md-6"><?= textarea_field('banking_reference', 'Banking references', $financial['banking_reference'] ?? '', ['rows' => 2, 'class' => '']) ?></div>
                    <div class="col-md-2"><?= field('legal_cases_count', 'Pending legal cases', $financial['legal_cases_count'] ?? '', ['type' => 'number', 'class' => '']) ?></div>
                    <div class="col-md-4"><?= textarea_field('legal_case_notes', 'Legal case notes', $financial['legal_case_notes'] ?? '', ['rows' => 2, 'class' => '']) ?></div>
                    <div class="col-12"><?= check_field('bankruptcy_history', 'The company has a history of insolvency / bankruptcy proceedings', (bool) ($financial['bankruptcy_history'] ?? 0)) ?></div>
                    <div class="col-12"><button class="btn btn-outline-primary btn-sm" type="submit">Save financial information</button> <span class="small text-muted ms-2">Upload audited statements under Documents.</span></div>
                </form>
            </div>
        </section>

        <section id="quality" class="bc-card mb-4">
            <div class="bc-card-header"><h2>Quality systems</h2><?= $quality ? status_badge($quality['status']) : '' ?></div>
            <div class="bc-card-body">
                <form method="post" action="<?= site_url($area . '/verification/quality') ?>" class="row g-3" novalidate>
                    <?= csrf_field() ?>
                    <?php $yn = ['yes' => 'Yes', 'partial' => 'Partially', 'no' => 'No', 'na' => 'Not applicable']; ?>
                    <?php foreach (['ppap' => 'PPAP', 'apqp' => 'APQP', 'fmea' => 'FMEA', 'capa' => 'CAPA'] as $k => $l): ?><div class="col-6 col-md-3"><?= select_field($k, $l, $yn, $quality[$k] ?? 'na', ['class' => '']) ?></div><?php endforeach ?>
                    <div class="col-md-4"><?= field('rejection_rate_pct', 'Customer rejection rate %', $quality['rejection_rate_pct'] ?? '', ['class' => '', 'attrs' => 'inputmode="decimal"']) ?></div>
                    <div class="col-md-8 pt-md-4"><?= check_field('has_inspection_system', 'We operate a documented incoming/outgoing inspection system', (bool) ($quality['has_inspection_system'] ?? 0)) ?></div>
                    <div class="col-md-6"><?= textarea_field('qc_process_notes', 'QC process summary', $quality['qc_process_notes'] ?? '', ['rows' => 3, 'class' => '']) ?></div>
                    <div class="col-md-6"><?= textarea_field('quality_certificates', 'Other quality certificates', $quality['quality_certificates'] ?? '', ['rows' => 3, 'class' => '']) ?></div>
                    <div class="col-12"><button class="btn btn-outline-primary btn-sm" type="submit">Save quality information</button></div>
                </form>
            </div>
        </section>
        <?php endif ?>

        <section id="documents" class="bc-card mb-4">
            <div class="bc-card-header"><h2>Company documents</h2></div>
            <div class="bc-card-body">
                <?php if ($documents): ?>
                <div class="table-responsive mb-3"><table class="table table-bc table-stack">
                    <thead><tr><th>Document</th><th>Type</th><th>Version</th><th>Expires</th><th>Review</th><th></th></tr></thead>
                    <tbody><?php foreach ($documents as $d): ?><tr class="<?= $d['is_current'] ? '' : 'text-muted' ?>"><td data-label="Document"><a href="<?= site_url('documents/' . $d['uuid']) ?>"><?= esc($d['title']) ?></a><?= $d['is_current'] ? '' : ' <span class="small">(superseded)</span>' ?></td><td data-label="Type" class="small"><?= esc($docTypes[$d['doc_type']] ?? str_replace('_', ' ', $d['doc_type'])) ?></td><td data-label="Version">v<?= (int) $d['version'] ?></td><td data-label="Expires"><?= fdate($d['expires_on']) ?></td><td data-label="Review"><?= status_badge($d['review_status']) ?><?= $d['review_comment'] ? '<div class="small text-muted">' . esc($d['review_comment']) . '</div>' : '' ?></td><td></td></tr><?php endforeach ?></tbody>
                </table></div>
                <?php endif ?>
                <form method="post" action="<?= site_url($area . '/verification/documents') ?>" enctype="multipart/form-data" class="row g-2" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-md-4"><?= select_field('doc_type', 'Document type', $docTypes, null, ['required' => true, 'class' => '']) ?></div>
                    <div class="col-md-4"><?= field('title', 'Title', null, ['required' => true, 'class' => '']) ?></div>
                    <div class="col-md-4"><?= field('expires_on', 'Expiry date (if any)', null, ['type' => 'date', 'class' => '']) ?></div>
                    <div class="col-md-6"><label class="form-label" for="docfile">File (PDF, JPG, PNG · max <?= (int) policy('documents.max_upload_mb', 10) ?> MB)<span class="required-mark" aria-hidden="true">*</span></label><input class="form-control" type="file" id="docfile" name="file" accept=".pdf,.jpg,.jpeg,.png" required></div>
                    <div class="col-md-6"><?= select_field('replace_id', 'Replaces (new version of)', array_column(array_filter($documents, static fn ($d) => $d['is_current']), 'title', 'id'), null, ['class' => '', 'placeholder' => '— New document —']) ?></div>
                    <div class="col-12"><button class="btn btn-outline-primary btn-sm" type="submit">Upload document</button></div>
                </form>
            </div>
        </section>
    </div>
</div>
<?= $this->endSection() ?>

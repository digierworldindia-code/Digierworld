<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php
$open = in_array($app['status'], ['submitted', 'in_review'], true);
$base = 'admin/verifications/' . $app['id'];
$sup  = $c['company_type'] === 'supplier';
$tabs = ['stages' => 'Stages', 'kyc' => 'KYC / KYB', 'compliance' => 'Compliance', 'financial' => 'Financial', 'risk' => 'Risk', 'quality' => 'Quality', 'audit' => 'Site audit', 'sanctions' => 'Sanctions', 'documents' => 'Documents', 'history' => 'History'];
if (! $sup) { unset($tabs['compliance'], $tabs['financial'], $tabs['quality'], $tabs['audit']); }
$kycOpts = ['pending' => 'Pending', 'verified' => 'Verified', 'failed' => 'Failed'];
?>
<?= view('components/page_header', ['title' => $c['legal_name'], 'subtitle' => ucfirst($app['application_type']) . ' verification · application #' . $app['id'] . ' · cycle ' . $app['cycle'], 'breadcrumbs' => ['Verification queue' => 'admin/verifications', '#' . $app['id'] => null],
    'actions' => '<a class="btn btn-sm btn-light" href="' . site_url('admin/companies/' . $c['id']) . '">Company record</a>']) ?>
<?php if ($c['is_sample']): ?><div class="alert alert-warning small"><span class="badge-sample">SAMPLE DATA</span> Demo company — any approvals here are not real verifications.</div><?php endif ?>
<div class="row g-4">
    <div class="col-xl-9">
        <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
            <?php foreach ($tabs as $k => $l): ?><li class="nav-item" role="presentation"><button class="nav-link text-nowrap<?= $k === 'stages' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#t-<?= $k ?>" type="button" role="tab" aria-controls="t-<?= $k ?>" aria-selected="<?= $k === 'stages' ? 'true' : 'false' ?>"><?= $l ?></button></li><?php endforeach ?>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="t-stages" role="tabpanel">
                <div class="bc-card"><div class="table-responsive"><table class="table table-bc table-stack mb-0">
                    <thead><tr><th>Stage</th><th>Status</th><th>Assigned</th><th>Reviewed</th><th>Update</th></tr></thead>
                    <tbody><?php foreach ($stages as $s): ?><tr>
                        <td data-label="Stage" class="fw-semibold"><?= esc($stageNames[$s['stage_key']]) ?></td>
                        <td data-label="Status"><?= status_badge($s['status']) ?><?= $s['comments'] ? '<div class="small text-muted">' . esc($s['comments']) . '</div>' : '' ?></td>
                        <td data-label="Assigned"><?php if ($open && $s['status'] !== 'not_applicable'): ?><form method="post" action="<?= site_url($base . '/assign') ?>" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="stage_key" value="<?= $s['stage_key'] ?>"><select class="form-select form-select-sm" name="user_id" aria-label="Assign reviewer" style="min-width:150px"><option value="">—</option><?php foreach ($officers as $id => $n): ?><option value="<?= $id ?>"<?= (int) $s['assigned_to'] === (int) $id ? ' selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select><button class="btn btn-sm btn-light" type="submit">Set</button></form><?php else: ?><span class="small text-muted"><?= $s['assigned_to'] ? esc($officers[$s['assigned_to']] ?? '#' . $s['assigned_to']) : '—' ?></span><?php endif ?></td>
                        <td data-label="Reviewed" class="small"><?= fdt($s['reviewed_at']) ?></td>
                        <td data-label="Update"><?php if ($open && $s['stage_key'] !== 'management_approval' && $s['status'] !== 'not_applicable'): ?>
                            <form method="post" action="<?= site_url($base . '/stage') ?>" class="d-flex flex-wrap gap-1" style="min-width:280px"><?= csrf_field() ?><input type="hidden" name="stage_key" value="<?= $s['stage_key'] ?>">
                                <select class="form-select form-select-sm" name="status" aria-label="Decision" style="max-width:120px"><option value="in_review">In review</option><option value="passed">Passed</option><option value="failed">Failed</option><option value="waived">Waived</option></select>
                                <input class="form-control form-control-sm" name="comments" placeholder="Comment (required if failed)" aria-label="Comment" style="max-width:180px"><button class="btn btn-sm btn-primary" type="submit">Save</button></form>
                        <?php endif ?></td>
                    </tr><?php endforeach ?></tbody>
                </table></div></div>
                <p class="small text-muted mt-2">Record evidence in the relevant tab first (KYC, compliance, risk…), then mark the stage passed. Stages marked “not applicable” are disabled by the verification-stage policy.</p>
            </div>

            <div class="tab-pane fade" id="t-kyc" role="tabpanel">
                <div class="bc-card mb-3"><div class="bc-card-body">
                <?php if (! $kyc): ?><p class="text-muted mb-0">The applicant has not entered KYC details.</p><?php else: ?>
                    <dl class="dl-grid small">
                        <dt>GST</dt><dd class="part-no"><?= esc($kyc['gst_number'] ?? '—') ?></dd><dt>PAN</dt><dd class="part-no"><?= esc($kyc['pan_number'] ?? '—') ?></dd><dt>CIN</dt><dd class="part-no"><?= esc($kyc['cin_number'] ?? '—') ?></dd>
                        <dt>VAT</dt><dd class="part-no"><?= esc($kyc['vat_number'] ?? '—') ?></dd><dt>Tax ID</dt><dd class="part-no"><?= esc($kyc['tax_id'] ?? '—') ?></dd>
                        <dt>Bank</dt><dd><?= esc($kyc['bank_name'] ?? '—') ?> · <?= esc($kyc['bank_ifsc_swift'] ?? '') ?> · <?= esc(country_name($kyc['bank_country'])) ?><br><?= esc($kyc['bank_account_name'] ?? '') ?> · <?= $sensitive ? '<span class="part-no">' . esc($kyc['bank_account_plain'] ?? '—') . '</span> <span class="badge-soft warning">sensitive — access logged</span>' : '••••' . esc($kyc['bank_account_last4'] ?? '') ?></dd>
                    </dl>
                    <p class="small text-muted">External registry, bank-account and identity checks must be performed with authorised providers or official sources; record the outcome below.</p>
                    <form method="post" action="<?= site_url($base . '/kyc') ?>"><?= csrf_field() ?>
                        <div class="row g-2">
                            <?php foreach (['tax_verified' => 'Tax registrations', 'address_verified' => 'Address', 'owner_verified' => 'Owners / directors', 'ubo_verified' => 'Beneficial owners', 'bank_validated' => 'Bank account'] as $f => $l): $cur = $kyc[$f] === 'validated' ? 'verified' : $kyc[$f]; ?>
                            <div class="col-md-4"><?= select_field($f, $l, $kycOpts, $cur, ['class' => '']) ?></div>
                            <?php endforeach ?>
                            <div class="col-12"><?= textarea_field('reviewer_notes', 'Reviewer notes / evidence', $kyc['reviewer_notes'], ['rows' => 2, 'class' => '']) ?></div>
                        </div>
                        <?php if ($directors): ?><p class="small-caps mt-3 mb-1">Directors / owners</p>
                        <div class="table-responsive"><table class="table table-sm small"><thead><tr><th>Name</th><th>Role</th><th>Nationality</th><th>ID</th><th>Own %</th><th>UBO / PEP</th><th>Status</th></tr></thead><tbody>
                        <?php foreach ($directors as $d): ?><tr><td><?= esc($d['full_name']) ?></td><td><?= esc($d['designation'] ?? '') ?></td><td><?= esc($d['nationality'] ?? '') ?></td><td><?= esc($d['id_document_type'] ?? '') ?> <?= $sensitive ? '<span class="part-no">' . esc($d['id_plain'] ?? '') . '</span>' : '<span class="text-muted">hidden</span>' ?></td><td><?= esc($d['ownership_percent'] ?? '') ?></td><td><?= $d['is_ubo'] ? 'UBO' : '' ?> <?= $d['is_pep_declared'] ? '<span class="text-danger">PEP</span>' : '' ?></td>
                            <td><select class="form-select form-select-sm" name="directors[<?= $d['id'] ?>]" aria-label="Status for <?= esc($d['full_name'], 'attr') ?>"><?php foreach (['pending', 'verified', 'rejected'] as $o): ?><option value="<?= $o ?>"<?= $d['verification_status'] === $o ? ' selected' : '' ?>><?= ucfirst($o) ?></option><?php endforeach ?></select></td></tr><?php endforeach ?>
                        </tbody></table></div><?php endif ?>
                        <button class="btn btn-sm btn-primary" type="submit">Save KYC review</button>
                    </form>
                <?php endif ?>
                </div></div>
            </div>

            <?php if ($sup): ?>
            <div class="tab-pane fade" id="t-compliance" role="tabpanel"><div class="bc-card">
                <?php if (! $certs): ?><?= empty_state('bi-award', 'No certifications submitted') ?><?php else: ?>
                <div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Type</th><th>Number / issuer</th><th>Valid</th><th>File</th><th>Status</th><th>Decision</th></tr></thead><tbody>
                <?php foreach ($certs as $ct): ?><tr><td data-label="Type"><?= esc($ct['cert_type']) ?></td><td data-label="Number" class="small"><?= esc($ct['cert_number'] ?? '—') ?><br><?= esc($ct['issuer'] ?? '') ?></td><td data-label="Valid" class="small"><?= fdate($ct['issued_on']) ?> – <?= $ct['expires_on'] && $ct['expires_on'] < date('Y-m-d') ? '<span class="text-danger">' . fdate($ct['expires_on']) . '</span>' : fdate($ct['expires_on']) ?></td>
                    <td data-label="File"><?php if ($ct['document_id'] && ($dd = db_connect()->table('documents')->select('uuid')->where('id', $ct['document_id'])->get()->getRowArray())): ?><a href="<?= site_url('documents/' . $dd['uuid']) ?>" target="_blank">Open</a><?php else: ?>—<?php endif ?></td><td data-label="Status"><?= status_badge($ct['status']) ?></td>
                    <td data-label="Decision"><form method="post" action="<?= site_url($base . '/certifications/' . $ct['id']) ?>" class="d-flex gap-1" style="min-width:250px"><?= csrf_field() ?><select class="form-select form-select-sm" name="status" aria-label="Status"><?php foreach (['pending', 'verified', 'rejected', 'expired'] as $o): ?><option value="<?= $o ?>"<?= $ct['status'] === $o ? ' selected' : '' ?>><?= ucfirst($o) ?></option><?php endforeach ?></select><input class="form-control form-control-sm" name="reviewer_notes" value="<?= esc($ct['reviewer_notes'] ?? '', 'attr') ?>" placeholder="Note" aria-label="Note"><button class="btn btn-sm btn-light" type="submit">Save</button></form></td></tr><?php endforeach ?>
                </tbody></table></div><?php endif ?>
            </div></div>

            <div class="tab-pane fade" id="t-financial" role="tabpanel"><div class="bc-card"><div class="bc-card-body">
                <?php if (! $financial): ?><p class="text-muted mb-0">No financial information submitted.</p><?php else: ?>
                <dl class="dl-grid small"><dt>Financial year</dt><dd><?= esc($financial['fiscal_year'] ?? '—') ?></dd><dt>Turnover</dt><dd><?= money($financial['annual_turnover'], $financial['currency'], 0) ?></dd><dt>Net profit</dt><dd><?= money($financial['net_profit'], $financial['currency'], 0) ?></dd>
                    <dt>Assets / liabilities</dt><dd><?= money($financial['total_assets'], $financial['currency'], 0) ?> / <?= money($financial['total_liabilities'], $financial['currency'], 0) ?></dd><dt>Credit rating</dt><dd><?= esc(trim(($financial['credit_rating'] ?? '') . ' ' . ($financial['credit_agency'] ?? ''))) ?: '—' ?></dd>
                    <dt>Banking references</dt><dd><?= esc($financial['banking_reference'] ?? '—') ?></dd><dt>Legal cases</dt><dd><?= esc((string) ($financial['legal_cases_count'] ?? '—')) ?> <?= esc($financial['legal_case_notes'] ?? '') ?></dd><dt>Insolvency history</dt><dd><?= $financial['bankruptcy_history'] ? '<span class="text-danger fw-semibold">Declared</span>' : 'None declared' ?></dd></dl>
                <form method="post" action="<?= site_url($base . '/financial') ?>" class="row g-2"><?= csrf_field() ?><div class="col-md-3"><?= select_field('status', 'Decision', ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'], $financial['status'], ['class' => '']) ?></div><div class="col-md-3"><?= field('credit_rating', 'Credit rating (verified)', $financial['credit_rating'], ['class' => '']) ?></div><div class="col-md-6"><?= field('reviewer_notes', 'Notes / evidence', $financial['reviewer_notes'], ['class' => '']) ?></div><div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save financial review</button></div></form>
                <?php endif ?>
            </div></div></div>
            <?php endif ?>

            <div class="tab-pane fade" id="t-risk" role="tabpanel"><div class="bc-card mb-3"><div class="bc-card-body">
                <form method="post" action="<?= site_url($base . '/risk') ?>" novalidate><?= csrf_field() ?>
                    <p class="small text-muted">Score each factor from 1 (low risk) to 5 (high risk) based on recorded evidence. The weighted level uses the thresholds in Platform settings.</p>
                    <div class="row g-2"><?php foreach ($riskFactors as $k => $l): ?><div class="col-6 col-md-2"><?= select_field($k, $l, ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'], $risks[0][$k] ?? '', ['class' => '', 'placeholder' => '—']) ?></div><?php endforeach ?>
                    <div class="col-12"><?= textarea_field('evidence_notes', 'Evidence (required)', null, ['rows' => 2, 'class' => '', 'required' => true]) ?></div></div>
                    <button class="btn btn-sm btn-primary" type="submit">Record assessment</button>
                </form>
            </div></div>
            <?php if ($risks): ?><div class="bc-card"><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Date</th><?php foreach ($riskFactors as $l): ?><th><?= esc(str_replace(' risk', '', $l)) ?></th><?php endforeach ?><th>Score</th><th>Level</th><th>Evidence</th></tr></thead><tbody>
            <?php foreach ($risks as $r): ?><tr><td><?= fdate($r['assessed_at']) ?></td><?php foreach (array_keys($riskFactors) as $k): ?><td><?= esc((string) ($r[$k] ?? '—')) ?></td><?php endforeach ?><td><?= esc($r['weighted_score'] ?? '') ?></td><td><?= status_badge($r['risk_level']) ?></td><td><?= esc($r['evidence_notes'] ?? '') ?></td></tr><?php endforeach ?>
            </tbody></table></div></div><?php endif ?></div>

            <?php if ($sup): ?>
            <div class="tab-pane fade" id="t-quality" role="tabpanel"><div class="bc-card"><div class="bc-card-body">
                <?php if ($quality): ?><dl class="dl-grid small"><dt>Inspection system</dt><dd><?= $quality['has_inspection_system'] ? 'Yes' : 'No' ?></dd><dt>PPAP / APQP / FMEA / CAPA</dt><dd><?= esc(strtoupper(implode(' / ', [$quality['ppap'], $quality['apqp'], $quality['fmea'], $quality['capa']]))) ?></dd><dt>Rejection rate</dt><dd><?= $quality['rejection_rate_pct'] !== null ? esc($quality['rejection_rate_pct']) . '%' : '—' ?></dd><dt>QC process</dt><dd><?= esc($quality['qc_process_notes'] ?? '—') ?></dd><dt>Certificates</dt><dd><?= esc($quality['quality_certificates'] ?? '—') ?></dd></dl><?php else: ?><p class="text-muted small">No quality information submitted by the applicant.</p><?php endif ?>
                <form method="post" action="<?= site_url($base . '/quality') ?>" class="row g-2"><?= csrf_field() ?><div class="col-md-3"><?= select_field('status', 'Decision', ['pending' => 'Pending', 'passed' => 'Passed', 'failed' => 'Failed'], $quality['status'] ?? 'pending', ['class' => '']) ?></div><div class="col-md-2"><?= field('score', 'Score (0–100)', $quality['score'] ?? '', ['class' => '']) ?></div><div class="col-md-7"><?= field('reviewer_notes', 'Notes', $quality['reviewer_notes'] ?? '', ['class' => '']) ?></div><div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save quality assessment</button></div></form>
            </div></div></div>

            <div class="tab-pane fade" id="t-audit" role="tabpanel"><div class="bc-card mb-3"><div class="bc-card-body">
                <form method="post" action="<?= site_url($base . '/audit') ?>" enctype="multipart/form-data" class="row g-2" novalidate><?= csrf_field() ?>
                    <div class="col-md-3"><?= field('scheduled_on', 'Scheduled', null, ['type' => 'date', 'class' => '']) ?></div><div class="col-md-3"><?= field('conducted_on', 'Conducted', null, ['type' => 'date', 'class' => '']) ?></div>
                    <div class="col-md-3"><?= select_field('auditor_user_id', 'Internal auditor', $officers, null, ['class' => '', 'placeholder' => '—']) ?></div><div class="col-md-3"><?= field('external_auditor', 'External auditor', null, ['class' => '']) ?></div>
                    <div class="col-12"><?= field('site_address', 'Site address', trim(($c['address_line1'] ?? '') . ', ' . ($c['city'] ?? ''), ', '), ['class' => '']) ?></div>
                    <?php foreach (['manufacturing_capability' => 'Manufacturing', 'production_capacity' => 'Capacity', 'machinery_condition' => 'Machinery', 'warehouse_management' => 'Warehouse', 'calibration' => 'Calibration', 'workforce_capability' => 'Workforce', 'quality_processes' => 'Quality processes'] as $k => $l): ?>
                    <div class="col-6 col-md-3 col-xl"><?= select_field($k, $l . ' (1–5)', ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'], null, ['class' => '', 'placeholder' => '—']) ?></div><?php endforeach ?>
                    <div class="col-md-8"><?= textarea_field('findings', 'Findings', null, ['rows' => 2, 'class' => '']) ?></div><div class="col-md-4"><?= select_field('result', 'Result', ['scheduled' => 'Scheduled', 'pass' => 'Pass', 'conditional' => 'Conditional', 'fail' => 'Fail', 'cancelled' => 'Cancelled'], 'scheduled', ['class' => '']) ?><label class="form-label" for="arep">Report (PDF/image)</label><input id="arep" class="form-control form-control-sm" type="file" name="report" accept=".pdf,.jpg,.jpeg,.png"></div>
                    <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Record site audit</button></div>
                </form>
            </div></div>
            <?php if ($audits): ?><div class="bc-card"><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Scheduled</th><th>Conducted</th><th>Scores</th><th>Result</th><th>Findings</th></tr></thead><tbody><?php foreach ($audits as $a): ?><tr><td><?= fdate($a['scheduled_on']) ?></td><td><?= fdate($a['conducted_on']) ?></td><td><?= esc(implode(' / ', array_map(static fn ($k) => $a[$k] ?? '–', ['manufacturing_capability', 'production_capacity', 'machinery_condition', 'warehouse_management', 'calibration', 'workforce_capability', 'quality_processes']))) ?></td><td><?= status_badge($a['result']) ?></td><td><?= esc($a['findings'] ?? '') ?></td></tr><?php endforeach ?></tbody></table></div></div><?php endif ?></div>
            <?php endif ?>

            <div class="tab-pane fade" id="t-sanctions" role="tabpanel"><div class="bc-card mb-3"><div class="bc-card-body">
                <?php if ($sanctionsProvider === 'none'): ?><div class="alert alert-warning small">No automated sanctions/PEP/AML provider is configured. Perform the checks against official sources (e.g. UN Security Council consolidated list, OFAC SDN list) and record the result. Do not record a result for a check you did not perform.</div><?php endif ?>
                <form method="post" action="<?= site_url($base . '/sanctions') ?>" class="row g-2" novalidate><?= csrf_field() ?>
                    <div class="col-md-3"><?= select_field('subject_type', 'Subject', ['company' => 'Company', 'director' => 'Director / owner'], 'company', ['class' => '']) ?></div>
                    <div class="col-md-5"><?= select_field('subject_name', 'Name screened', array_merge([$c['legal_name'] => $c['legal_name']], array_combine(array_column($directors, 'full_name'), array_column($directors, 'full_name'))), null, ['class' => '']) ?></div>
                    <div class="col-md-4"><?= select_field('result', 'Result', ['clear' => 'Clear', 'potential_match' => 'Potential match (needs review)', 'confirmed_match' => 'Confirmed match', 'error' => 'Could not complete'], null, ['class' => '', 'placeholder' => 'Select']) ?></div>
                    <div class="col-md-6"><?= field('lists_checked', 'Lists checked', 'UN consolidated, OFAC SDN, PEP', ['class' => '', 'required' => true]) ?></div><div class="col-md-6"><?= field('provider_reference', 'Reference / search ID', null, ['class' => '']) ?></div>
                    <div class="col-12"><?= field('notes', 'Notes', null, ['class' => '']) ?></div><div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Record screening</button></div>
                </form>
            </div></div>
            <?php if ($screenings): ?><div class="bc-card"><div class="table-responsive"><table class="table table-bc mb-0 small"><thead><tr><th>Date</th><th>Subject</th><th>Provider</th><th>Lists</th><th>Result</th><th>Notes</th></tr></thead><tbody><?php foreach ($screenings as $s): ?><tr><td><?= fdt($s['screened_at']) ?></td><td><?= esc($s['subject_name']) ?></td><td><?= esc($s['provider']) ?></td><td><?= esc($s['lists_checked'] ?? '') ?></td><td><?= status_badge($s['result']) ?></td><td><?= esc($s['notes'] ?? '') ?></td></tr><?php endforeach ?></tbody></table></div></div><?php endif ?></div>

            <div class="tab-pane fade" id="t-documents" role="tabpanel"><div class="bc-card">
                <?php if (! $documents): ?><?= empty_state('bi-folder2-open', 'No documents uploaded') ?><?php else: ?>
                <div class="table-responsive"><table class="table table-bc table-stack mb-0"><thead><tr><th>Document</th><th>Type</th><th>Expires</th><th>Status</th><th>Decision</th></tr></thead><tbody>
                <?php foreach ($documents as $d): ?><tr class="<?= $d['is_current'] ? '' : 'text-muted' ?>"><td data-label="Document"><a href="<?= site_url('documents/' . $d['uuid']) ?>" target="_blank"><?= esc($d['title']) ?></a> <span class="small">v<?= (int) $d['version'] ?><?= $d['is_current'] ? '' : ' (old)' ?></span></td><td data-label="Type" class="small"><?= esc(str_replace('_', ' ', $d['doc_type'])) ?></td><td data-label="Expires"><?= fdate($d['expires_on']) ?></td><td data-label="Status"><?= status_badge($d['review_status']) ?></td>
                    <td data-label="Decision"><?php if ($d['is_current']): ?><form method="post" action="<?= site_url($base . '/documents/' . $d['id']) ?>" class="d-flex gap-1" style="min-width:260px"><?= csrf_field() ?><select class="form-select form-select-sm" name="review_status" aria-label="Decision"><?php foreach (['pending', 'approved', 'rejected'] as $o): ?><option value="<?= $o ?>"<?= $d['review_status'] === $o ? ' selected' : '' ?>><?= ucfirst($o) ?></option><?php endforeach ?></select><input class="form-control form-control-sm" name="review_comment" value="<?= esc($d['review_comment'] ?? '', 'attr') ?>" placeholder="Comment" aria-label="Comment"><button class="btn btn-sm btn-light" type="submit">Save</button></form><?php endif ?></td></tr><?php endforeach ?>
                </tbody></table></div><?php endif ?>
            </div></div>

            <div class="tab-pane fade" id="t-history" role="tabpanel"><div class="bc-card"><div class="bc-card-body"><ul class="timeline small mb-0"><?php foreach ($events as $e): ?><li><div class="fw-semibold"><?= esc(ucfirst(str_replace('_', ' ', $e['action']))) ?><?= $e['stage_key'] ? ' · ' . esc($stageNames[$e['stage_key']] ?? $e['stage_key']) : '' ?></div><?php if ($e['notes']): ?><div><?= esc($e['notes']) ?></div><?php endif ?><div class="when"><?= fdt($e['created_at']) ?> · <?= esc($e['email'] ?? 'system') ?></div></li><?php endforeach ?></ul></div></div></div>
        </div>
    </div>
    <div class="col-xl-3">
        <div class="bc-card mb-3"><div class="bc-card-body">
            <h2 class="h6">Decision</h2>
            <p class="small mb-2">Status: <?= status_badge($app['status']) ?></p>
            <?php if ($app['decision_notes']): ?><p class="small text-muted"><?= esc($app['decision_notes']) ?></p><?php endif ?>
            <?php if ($open && $canApprove): ?>
                <?php if ($blocking): ?><div class="alert alert-light border small">Before approval: <?= esc(implode(', ', array_map(static fn ($k) => $stageNames[$k], $blocking))) ?>.</div><?php endif ?>
                <form method="post" action="<?= site_url($base . '/approve') ?>" class="mb-3" data-confirm="Approve this company? <?= $sup ? 'A membership invoice will be issued for commercial activation.' : '' ?>"><?= csrf_field() ?><label class="form-label" for="an">Approval note</label><textarea id="an" class="form-control form-control-sm mb-2" name="notes" rows="2"></textarea><button class="btn btn-primary btn-sm w-100" type="submit"<?= $blocking ? ' disabled' : '' ?>>Management approval</button></form>
                <form method="post" action="<?= site_url($base . '/reject') ?>"><?= csrf_field() ?><label class="form-label" for="rn">Reason (sent to applicant)</label><textarea id="rn" class="form-control form-control-sm mb-2" name="notes" rows="2" required></textarea>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="final" value="1" id="fin"><label class="form-check-label small" for="fin">Final rejection (no resubmission)</label></div>
                    <button class="btn btn-outline-danger btn-sm w-100" type="submit">Request changes / reject</button></form>
            <?php elseif ($open): ?><p class="small text-muted mb-0">Management approval requires the verification.approve permission.</p><?php endif ?>
        </div></div>
        <div class="bc-card"><div class="bc-card-body small"><dl class="dl-grid mb-0"><dt>Submitted</dt><dd><?= fdt($app['submitted_at']) ?></dd><dt>Decided</dt><dd><?= fdt($app['decided_at']) ?></dd><dt>Company</dt><dd><?= status_badge($c['verification_status']) ?></dd></dl></div></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
use App\Services\ClaimService;

/** @var array $c @var list<array> $signals @var list<array> $events @var list<array>|null $media @var array|null $replacement @var array $dealerStats @var list<string> $next */
$closed = in_array($c['status'], ClaimService::CLOSED_STATES, true);
?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/claims') ?>">Claims</a></div>
    <h1><?= esc($c['claim_number']) ?> <?= pill($c['status']) ?></h1>
    <p><?= esc(ClaimService::ISSUE_CATEGORIES[$c['issue_category']] ?? $c['issue_category']) ?> · submitted <?= esc(local_time($c['submitted_at'])) ?> by <?= esc($c['submitted_by'] ?? $c['dealer']) ?></p></div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="panel">
            <div class="panel-head"><h2>Reported issue</h2></div>
            <div class="panel-body">
                <p class="fw-semibold mb-1"><?= esc($c['reported_issue']) ?></p>
                <p class="mb-0" style="white-space:pre-line"><?= esc($c['description']) ?></p>
                <?php if ($c['decision_reason']): ?>
                <hr><p class="small mb-0"><strong>Decision<?= $c['reviewed_by'] ? ' by ' . esc($c['reviewed_by']) : '' ?>, <?= esc(local_time($c['decision_at'])) ?>:</strong> <?= esc($c['decision_reason']) ?><?= $c['resolution'] ? ' · Resolution: ' . esc(ClaimService::RESOLUTIONS[$c['resolution']] ?? $c['resolution']) : '' ?></p>
                <?php endif ?>
            </div>
        </div>

        <?php if ($media !== null): ?>
        <div class="panel">
            <div class="panel-head"><h2>Photographs and documents</h2><span class="small text-muted"><?= count($media) ?> file(s)</span></div>
            <div class="panel-body">
                <?php if ($media === []): ?><p class="text-muted small mb-0">No files attached.</p><?php else: ?>
                <div class="media-grid">
                <?php foreach ($media as $f): ?>
                    <?php $url = site_url('media/' . $f['id']); ?>
                    <?php if (str_starts_with($f['mime_type'], 'image/')): ?>
                        <a href="<?= $url ?>" target="_blank" rel="noopener"><img src="<?= $url ?>" alt="<?= esc($f['caption'] ?? humanise($f['kind']), 'attr') ?>" loading="lazy"></a>
                    <?php elseif (str_starts_with($f['mime_type'], 'video/')): ?>
                        <div class="tile"><video src="<?= $url ?>" controls preload="metadata"></video></div>
                    <?php else: ?>
                        <a class="d-grid place-items-center text-center p-2" href="<?= $url ?>" target="_blank" rel="noopener"><span><i class="bi bi-file-earmark-pdf fs-2"></i><br><span class="small"><?= esc(humanise($f['kind'])) ?></span></span></a>
                    <?php endif ?>
                <?php endforeach ?>
                </div>
                <?php endif ?>
                <?php if (! $closed && $ctx->can('claim:review')): ?>
                <form class="mt-3 d-flex flex-wrap gap-2 align-items-center" method="post" enctype="multipart/form-data" action="<?= site_url('admin/claims/' . $c['id'] . '/media') ?>">
                    <?= csrf_field() ?>
                    <input class="form-control form-control-sm" style="max-width:22rem" type="file" name="files[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,video/quicktime" aria-label="Add files">
                    <button class="btn btn-light btn-sm" type="submit">Upload</button>
                    <span class="small text-muted">JPEG, PNG, WebP, PDF up to 8 MB; MP4/MOV up to 25 MB.</span>
                </form>
                <?php endif ?>
            </div>
        </div>
        <?php endif ?>

        <div class="panel">
            <div class="panel-head"><h2>History</h2></div>
            <div class="panel-body">
                <ol class="timeline">
                <?php foreach ($events as $e): ?>
                    <li>
                        <div class="fw-semibold"><?= esc(humanise($e['event_type'])) ?><?= $e['to_status'] && $e['to_status'] !== $e['from_status'] ? ' → ' . pill($e['to_status']) : '' ?><?= $e['is_internal'] ? ' <span class="pill pill-caution">Internal</span>' : '' ?></div>
                        <div class="when"><?= esc(local_time($e['created_at'])) ?> · <?= esc($e['actor'] ?? 'System') ?></div>
                        <?php if ($e['note']): ?><div class="small mt-1<?= $e['is_internal'] ? ' internal' : '' ?>" style="white-space:pre-line"><?= esc($e['note']) ?></div><?php endif ?>
                    </li>
                <?php endforeach ?>
                </ol>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <?php if ($ctx->can('risk:view')): ?>
        <div class="panel">
            <div class="panel-head"><h2>Risk indicators</h2>
                <?= $c['risk_level'] ? pill($c['risk_level'], $c['risk_level'] . ' · ' . (int) $c['risk_score'] . '/100') : '<span class="pill">Not computed</span>' ?></div>
            <div class="panel-body">
                <?php if ($signals === []): ?><p class="small text-muted mb-2">No indicators were triggered.</p><?php endif ?>
                <ul class="list-unstyled mb-2">
                <?php foreach ($signals as $s): ?>
                    <li class="mb-2"><span class="pill pill-caution">+<?= (int) $s['weight'] ?></span> <strong class="small"><?= esc(humanise($s['code'])) ?></strong><div class="small text-muted"><?= esc($s['summary']) ?></div></li>
                <?php endforeach ?>
                </ul>
                <p class="small text-muted mb-2">Indicators suggest where to look closely. They are not evidence of fraud and do not decide the claim.</p>
                <form method="post" action="<?= site_url('admin/claims/' . $c['id'] . '/recompute-risk') ?>"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit"><i class="bi bi-arrow-clockwise"></i> Recompute</button>
                <?php if ($c['risk_computed_at']): ?><span class="small text-muted ms-2">Last computed <?= esc(local_time($c['risk_computed_at'])) ?></span><?php endif ?></form>
            </div>
        </div>
        <?php endif ?>

        <div class="panel">
            <div class="panel-head"><h2>Mattress and warranty</h2></div>
            <div class="panel-body"><dl class="dl-grid">
                <dt>Serial</dt><dd><a class="mono" href="<?= site_url('admin/mattresses/' . $c['mattress_id']) ?>"><?= esc($c['serial_number']) ?></a></dd>
                <dt>Product</dt><dd><?= esc($c['product']) ?>, <?= esc($c['size_label']) ?></dd>
                <dt>Sold</dt><dd><?= esc(local_date($c['sold_at'])) ?></dd>
                <dt>Warranty</dt><dd><?= $c['warranty_id'] ? pill($c['warranty_status']) . ' ' . esc(local_date($c['start_date'])) . ' – ' . esc(local_date($c['end_date'])) : '—' ?></dd>
                <dt>Customer</dt><dd><?= esc($c['customer'] ?? '—') ?><?= $c['customer_city'] ? ', ' . esc($c['customer_city']) : '' ?></dd>
                <dt>Dealer</dt><dd><a href="<?= site_url('admin/dealers/' . $c['dealer_id']) ?>"><?= esc($c['dealer']) ?></a>, <?= esc($c['dealer_city']) ?> · <?= esc($c['dealer_phone']) ?></dd>
                <dt>Dealer record</dt><dd><?= (int) $dealerStats['claims'] ?> claim(s) on <?= (int) $dealerStats['sales'] ?> sale(s)</dd>
                <?php if ($replacement): ?><dt>Replacement</dt><dd><a class="mono" href="<?= site_url('admin/mattresses/' . $replacement['new_id']) ?>"><?= esc($replacement['new_serial']) ?></a> issued <?= esc(local_date($replacement['issued_at'])) ?></dd><?php endif ?>
            </dl></div>
        </div>

        <?php if (! $closed && $c['status'] !== 'APPROVED' && $ctx->can('claim:decide')): ?>
        <form class="panel" method="post" action="<?= site_url('admin/claims/' . $c['id'] . '/decision') ?>" data-confirm="Record this decision? The dealer is notified with your reason.">
            <div class="panel-head"><h2>Decision</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <div class="d-flex gap-3 mb-3">
                    <div class="form-check"><input class="form-check-input" type="radio" name="decision" id="d-approve" value="APPROVED" required><label class="form-check-label" for="d-approve">Approve</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="decision" id="d-reject" value="REJECTED"><label class="form-check-label" for="d-reject">Reject</label></div>
                </div>
                <div class="mb-3"><label class="form-label" for="resolution">Resolution (if approved)</label>
                    <select class="form-select" id="resolution" name="resolution"><option value="">—</option>
                    <?php foreach (ClaimService::RESOLUTIONS as $k => $label): ?><option value="<?= $k ?>"><?= esc($label) ?></option><?php endforeach ?></select></div>
                <div class="mb-3"><label class="form-label" for="reason">Reason (the dealer sees this)</label><textarea class="form-control" id="reason" name="reason" rows="3" required minlength="5" maxlength="2000"></textarea></div>
                <button class="btn btn-primary" type="submit">Record decision</button>
            </div>
        </form>
        <?php endif ?>

        <?php if ($c['status'] === 'APPROVED' && ($c['resolution'] ?? 'REPLACEMENT') === 'REPLACEMENT' && $replacement === null && $ctx->can('claim:replace')): ?>
        <form class="panel" method="post" action="<?= site_url('admin/claims/' . $c['id'] . '/replacement') ?>" data-confirm="Issue this unit as the replacement? The original warranty moves to it.">
            <div class="panel-head"><h2>Issue replacement</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <p class="small text-muted">Enter the serial of a warehouse unit of the same product. It takes over the remainder of the original warranty term.</p>
                <div class="mb-2"><label class="form-label" for="serial">Replacement serial</label><input class="form-control mono text-uppercase" id="serial" name="serial" required maxlength="20"></div>
                <div class="mb-3"><label class="form-label" for="remarks">Remarks</label><input class="form-control" id="remarks" name="remarks" maxlength="500"></div>
                <button class="btn btn-primary" type="submit">Issue replacement</button>
            </div>
        </form>
        <?php endif ?>

        <?php if (in_array('CLOSED', $next, true) && $ctx->can('claim:decide')): ?>
        <form class="panel" method="post" action="<?= site_url('admin/claims/' . $c['id'] . '/close') ?>" data-confirm="Close this claim?">
            <div class="panel-head"><h2>Close claim</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <div class="d-flex gap-2"><input class="form-control" name="note" required minlength="3" maxlength="1000" placeholder="Closing note"><button class="btn btn-light" type="submit">Close</button></div>
            </div>
        </form>
        <?php endif ?>

        <?php if (! $closed && $ctx->can('claim:review')): ?>
        <div class="panel">
            <div class="panel-head"><h2>Review</h2></div>
            <div class="panel-body">
                <form method="post" action="<?= site_url('admin/claims/' . $c['id'] . '/note') ?>"><?= csrf_field() ?>
                    <label class="form-label" for="note">Add a note</label>
                    <textarea class="form-control" id="note" name="note" rows="2" required maxlength="2000"></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="form-check"><input type="hidden" name="internal" value="0"><input class="form-check-input" type="checkbox" id="internal" name="internal" value="1" checked><label class="form-check-label small" for="internal">Internal (the dealer cannot see it)</label></div>
                        <button class="btn btn-light btn-sm" type="submit">Add note</button>
                    </div>
                </form>
                <?php if (in_array('INFO_REQUESTED', $next, true)): ?>
                <hr>
                <form method="post" action="<?= site_url('admin/claims/' . $c['id'] . '/request-information') ?>"><?= csrf_field() ?>
                    <label class="form-label" for="message">Ask the dealer for more information</label>
                    <textarea class="form-control" id="message" name="message" rows="2" required minlength="5" maxlength="2000"></textarea>
                    <button class="btn btn-light btn-sm mt-2" type="submit">Send request</button>
                </form>
                <?php endif ?>
                <?php if ($c['status'] !== 'APPROVED'): ?>
                <hr>
                <form method="post" action="<?= site_url('admin/claims/' . $c['id'] . '/inspection') ?>"><?= csrf_field() ?>
                    <label class="form-label" for="date">Arrange an inspection</label>
                    <div class="d-flex gap-2"><input class="form-control" type="date" id="date" name="date" required style="max-width:11rem"><input class="form-control" name="note" maxlength="500" placeholder="Note (optional)" aria-label="Inspection note"><button class="btn btn-light" type="submit">Save</button></div>
                </form>
                <?php endif ?>
            </div>
        </div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

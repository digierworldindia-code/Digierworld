<?= $this->extend('layouts/app') ?>
<?php
$dnd    = (int) $l['dnd'] === 1;
$closed = in_array($l['stage_code'], ['won', 'lost', 'not_interested', 'dnc'], true);
$icons  = ['created' => 'plus-circle', 'call' => 'telephone', 'stage_change' => 'signpost', 'assignment' => 'person-check', 'followup_set' => 'alarm', 'followup_done' => 'check2-circle',
    'visit' => 'geo-alt', 'meeting' => 'calendar-event', 'task' => 'list-check', 'note' => 'sticky', 'conversion' => 'trophy', 'import' => 'upload', 'edit' => 'pencil', 'dnd' => 'slash-circle'];
$phone  = preg_replace('/[^0-9+]/', '', (string) $l['mobile']);
$wa     = preg_replace('/\D/', '', (string) $l['mobile']);
$wa     = strlen($wa) === 10 ? '91' . $wa : $wa;
?>
<?= $this->section('pageActions') ?>
<?php if (can('crm.lead.edit')): ?><a class="btn btn-outline-dark btn-sm" href="<?= site_url('crm/leads/' . $l['id'] . '/edit') ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif ?>
<?php if (can('crm.lead.convert') && $l['converted_at'] === null): ?><button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#convertModal"><i class="bi bi-trophy"></i> Convert to client</button><?php endif ?>
<div class="dropdown d-inline-block">
    <button class="btn btn-outline-dark btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">More</button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#stageModal">Change stage</button></li>
        <?php if (can('crm.lead.assign')): ?><li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#assignModal">Reassign</button></li><?php endif ?>
        <?php if (can(['crm.visit.view', 'crm.visit.assign'])): ?><li><a class="dropdown-item" href="<?= site_url('crm/visits/new?lead=' . $l['id']) ?>">Schedule visit</a></li><?php endif ?>
        <?php if (can('crm.meeting.manage')): ?><li><a class="dropdown-item" href="<?= site_url('crm/meetings?lead=' . $l['id'] . '#new') ?>">Schedule meeting</a></li><?php endif ?>
        <?php if (can('crm.task.create')): ?><li><a class="dropdown-item" href="<?= site_url('crm/tasks?lead=' . $l['id'] . '#new') ?>">Add task</a></li><?php endif ?>
        <?php if (! $dnd): ?><li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#dndModal">Mark Do-Not-Call</button></li><?php endif ?>
        <?php if (can('crm.lead.delete')): ?><li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#archiveModal">Archive</button></li><?php endif ?>
    </ul>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3 small">
    <span class="badge fs-6" style="background:<?= esc(preg_match('/^#[0-9a-f]{6}$/i', (string) $l['stage_color']) ? $l['stage_color'] : '#6c757d') ?>"><?= esc((string) $l['stage_name']) ?></span>
    <span class="badge text-bg-light border"><?= ucfirst($l['priority']) ?> priority</span>
    <?php if ($dnd): ?><span class="badge text-bg-dark"><i class="bi bi-slash-circle"></i> Do-Not-Call since <?= dmy($l['dnd_marked_at']) ?></span><?php endif ?>
    <?php if ($l['consent_status'] === 'consented'): ?><span class="badge text-bg-success">Consented</span><?php endif ?>
    <span class="text-muted mono"><?= esc($l['lead_code']) ?></span> · <span>Assigned to <strong><?= esc((string) $l['assignee']) ?></strong></span>
    · <span><?= (int) $l['call_attempts'] ?> calls, <?= (int) $l['connected_calls'] ?> connected</span>
    <?php if ($l['lost_reason']): ?>· <span class="text-danger">Lost: <?= esc($l['lost_reason']) ?></span><?php endif ?>
    <?php if ($l['client_name']): ?>· <a href="<?= site_url('contacts/' . $l['contact_id']) ?>">Client: <?= esc($l['client_name']) ?></a><?php endif ?>
</div>
<div class="row g-3">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div><div class="fs-5 fw-semibold"><?= esc($l['company_name']) ?></div><div class="text-muted"><?= esc((string) $l['contact_person']) ?></div></div>
            <div class="d-flex gap-2 align-items-start">
                <?php if (! $dnd): ?><a class="btn btn-dark" href="tel:<?= esc($phone) ?>"><i class="bi bi-telephone"></i> <?= esc($l['mobile']) ?></a>
                <a class="btn btn-outline-success" href="https://wa.me/<?= esc($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>
                <?php else: ?><span class="btn btn-outline-secondary disabled">Calls blocked (DND)</span><?php endif ?>
            </div>
        </div>
    </div></div>

    <?php if (can('crm.call.create')): ?>
    <form method="post" action="<?= site_url('crm/leads/' . $l['id'] . '/call') ?>" class="card mb-3" data-call-form><div class="card-body">
        <?= csrf_field() ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="card-section-title mb-0">Log a call</div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Direction">
                <input type="radio" class="btn-check" name="direction" value="outbound" id="dir_out" <?= $dnd ? 'disabled' : 'checked' ?>><label class="btn btn-outline-dark" for="dir_out">Outgoing</label>
                <input type="radio" class="btn-check" name="direction" value="inbound" id="dir_in" <?= $dnd ? 'checked' : '' ?>><label class="btn btn-outline-dark" for="dir_in">Incoming</label>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-1 mb-2" role="radiogroup" aria-label="Outcome">
            <?php foreach ($outcomes as $k => $lbl): ?>
            <input type="radio" class="btn-check" name="outcome" value="<?= $k ?>" id="oc_<?= $k ?>" required><label class="btn btn-sm btn-outline-<?= in_array($k, ['interested', 'connected'], true) ? 'success' : (in_array($k, ['not_interested', 'dnd_requested', 'wrong_number'], true) ? 'danger' : 'secondary') ?>" for="oc_<?= $k ?>"><?= esc($lbl) ?></label>
            <?php endforeach ?>
        </div>
        <?= field_error('outcome') ?>
        <div class="row g-2">
            <div class="col-md-8"><textarea class="form-control" name="notes" rows="2" placeholder="What was discussed? (saved as a new timeline entry)" aria-label="Notes"><?= esc((string) old('notes')) ?></textarea></div>
            <div class="col-md-4"><input class="form-control mb-1" name="person_spoken" placeholder="Spoke with" aria-label="Person spoken"><input class="form-control" name="duration_minutes" inputmode="decimal" placeholder="Minutes (if known)" aria-label="Duration in minutes"></div>
            <div class="col-md-4"><label class="form-label small" for="next_action">Next action</label><input class="form-control form-control-sm" id="next_action" name="next_action" placeholder="e.g. Send proposal and call back" value="<?= esc((string) old('next_action')) ?>"></div>
            <div class="col-md-4"><label class="form-label small" for="next_action_at">Next follow-up</label><input type="datetime-local" class="form-control form-control-sm<?= is_invalid('next_action_at') ?>" id="next_action_at" name="next_action_at" data-next><?= field_error('next_action_at') ?></div>
            <div class="col-md-4"><label class="form-label small" for="followup_owner_id">Follow-up owner</label><select class="form-select form-select-sm" id="followup_owner_id" name="followup_owner_id"><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $u['id'] === (int) ($l['assigned_to'] ?: current_user_id()) ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach ?></select>
</div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-1 small"><span class="text-muted me-1">Follow up:</span>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-quick="2h">in 2 hours</button><button type="button" class="btn btn-sm btn-outline-secondary py-0" data-quick="tomorrow">tomorrow 11 am</button><button type="button" class="btn btn-sm btn-outline-secondary py-0" data-quick="3d">in 3 days</button><button type="button" class="btn btn-sm btn-outline-secondary py-0" data-quick="week">next Monday</button>
                <div class="form-check ms-auto mb-0"><input class="form-check-input" type="checkbox" name="remind" value="1" id="remind" checked><label class="form-check-label" for="remind">Remind me 10 min before</label></div></div>
            <div class="col-md-6"><label class="form-label small" for="stage_id">Stage (optional — otherwise set from the outcome)</label><select class="form-select form-select-sm" id="stage_id" name="stage_id"><option value="">Automatic</option><?php foreach ($stages as $s): if ((int) $s['is_won'] === 1) { continue; } ?><option value="<?= $s['id'] ?>"><?= esc($s['name']) ?></option><?php endforeach ?></select></div>
            <div class="col-md-6"><label class="form-label small" for="called_at">Call time</label><input type="datetime-local" class="form-control form-control-sm" id="called_at" name="called_at" max="<?= date('Y-m-d\TH:i') ?>"><div class="form-text">Blank = now.</div></div>
        </div>
        <div class="d-flex gap-2 justify-content-end mt-2">
            <button class="btn btn-outline-dark" type="submit" name="after" value="stay">Save call</button>
            <button class="btn btn-dark" type="submit" name="after" value="next">Save &amp; next due lead →</button>
        </div>
    </div></form>
    <?php endif ?>

    <div class="card"><div class="card-body">
        <div class="card-section-title">Timeline</div>
        <ul class="timeline small">
        <?php foreach ($timeline as $t): ?>
            <li class="<?= $t['activity_type'] === 'call' ? 'tl-call' : '' ?>">
                <div><i class="bi bi-<?= $icons[$t['activity_type']] ?? 'dot' ?>"></i> <strong><?= esc($t['title']) ?></strong></div>
                <?php if ($t['details']): ?><div><?= nl2br(esc($t['details'])) ?></div><?php endif ?>
                <div class="text-muted"><?= dmy($t['occurred_at'], true) ?> · <?= esc((string) $t['full_name']) ?></div>
            </li>
        <?php endforeach ?>
        <?php if ($timeline === []): ?><li class="text-muted">No activity yet.</li><?php endif ?>
        </ul>
    </div></div>
</div>
<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body small">
        <div class="card-section-title">Open follow-ups</div>
        <?php foreach ($followups as $f): $over = $f['due_at'] < date('Y-m-d H:i:s'); ?>
        <div class="border rounded p-2 mb-2 <?= $over ? 'border-danger' : '' ?>">
            <div class="fw-semibold"><?= esc($f['title']) ?></div>
            <div class="<?= $over ? 'text-danger' : 'text-muted' ?>"><?= dmy($f['due_at'], true) ?><?= $over ? ' · overdue' : '' ?> · <?= esc((string) $f['owner']) ?></div>
            <form method="post" action="<?= site_url('crm/followups/' . $f['id'] . '/complete') ?>" class="d-flex gap-1 mt-1"><?= csrf_field() ?>
                <input class="form-control form-control-sm" name="outcome" placeholder="Outcome" aria-label="Outcome">
                <button class="btn btn-sm btn-outline-success" type="submit" title="Mark done"><i class="bi bi-check2"></i></button></form>
            <form method="post" action="<?= site_url('crm/followups/' . $f['id'] . '/complete') ?>" class="d-flex gap-1 mt-1"><?= csrf_field() ?>
                <input type="datetime-local" class="form-control form-control-sm" name="reschedule_to" required aria-label="Reschedule to">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Move</button></form>
        </div>
        <?php endforeach ?>
        <?php if ($followups === []): ?><div class="text-muted mb-2"><?= $closed ? 'Lead is closed.' : 'None — set the next follow-up when you log a call.' ?></div><?php endif ?>
        <?php if (! $dnd && ! $closed && can('crm.followup.manage')): ?>
        <form method="post" action="<?= site_url('crm/leads/' . $l['id'] . '/followups') ?>" class="border-top pt-2"><?= csrf_field() ?>
            <input class="form-control form-control-sm mb-1" name="title" placeholder="Follow-up (e.g. Call for decision)" aria-label="Follow-up title">
            <div class="d-flex gap-1"><input type="datetime-local" class="form-control form-control-sm" name="due_at" required aria-label="Due at"><button class="btn btn-sm btn-dark" type="submit">Add</button></div>
        </form>
        <?php endif ?>
    </div></div>

    <div class="card mb-3"><div class="card-body small">
        <div class="card-section-title">Details</div>
        <dl class="row mb-0">
            <dt class="col-5">Alt. mobile</dt><dd class="col-7"><?= esc((string) $l['alt_mobile']) ?: '—' ?></dd>
            <dt class="col-5">Email</dt><dd class="col-7"><?= esc((string) $l['email']) ?: '—' ?></dd>
            <dt class="col-5">Address</dt><dd class="col-7"><?= esc(trim($l['address'] . ' ' . $l['area'] . ' ' . $l['city'] . ' ' . $l['pincode'])) ?: '—' ?></dd>
            <dt class="col-5">Industry</dt><dd class="col-7"><?= esc((string) $l['industry']) ?: '—' ?></dd>
            <dt class="col-5">Services</dt><dd class="col-7"><?= esc(implode(', ', json_decode((string) $l['services_interested'], true) ?: [])) ?: '—' ?></dd>
            <dt class="col-5">Source</dt><dd class="col-7"><?= esc((string) $l['source_name']) ?: '—' ?><?= $l['campaign'] ? ' · ' . esc($l['campaign']) : '' ?></dd>
            <dt class="col-5">Expected deal</dt><dd class="col-7"><?= $l['expected_value'] ? inr($l['expected_value']) : '—' ?></dd>
            <dt class="col-5">Data kept till</dt><dd class="col-7"><?= dmy($l['retention_until']) ?></dd>
            <?php if ($l['remarks']): ?><dt class="col-12">Remarks</dt><dd class="col-12"><?= nl2br(esc($l['remarks'])) ?></dd><?php endif ?>
        </dl>
    </div></div>

    <?php if ($conversion): ?>
    <div class="card mb-3 border-success"><div class="card-body small">
        <div class="card-section-title">Converted</div>
        <div><?= dmy($conversion['converted_at'], true) ?></div>
        <?php if ($conversion['document_id']): ?><div><a href="<?= site_url('billing/documents/' . $conversion['document_id']) ?>"><?= esc(doc_type_label($conversion['doc_type'])) ?> <?= esc($conversion['document_number'] ?? $conversion['draft_ref']) ?></a></div><?php endif ?>
        <div class="text-muted">Incentives are proposed from invoices/collections under the configured policy and approved by HR — not paid automatically on conversion.</div>
    </div></div>
    <?php endif ?>

    <?php if ($visits !== [] || $tasks !== [] || $meetings !== []): ?>
    <div class="card"><div class="card-body small">
        <?php if ($visits !== []): ?><div class="card-section-title">Visits</div><?php foreach ($visits as $v): ?><div class="mb-1"><a href="<?= site_url('crm/visits/' . $v['id']) ?>"><?= dmy($v['scheduled_at'], true) ?></a> · <?= esc((string) $v['visitor']) ?> <?= status_badge($v['status']) ?></div><?php endforeach ?><?php endif ?>
        <?php if ($meetings !== []): ?><div class="card-section-title mt-2">Meetings</div><?php foreach ($meetings as $m): ?><div class="mb-1"><?= dmy($m['meeting_at'], true) ?> · <?= esc($m['title']) ?> <?= status_badge($m['status']) ?></div><?php endforeach ?><?php endif ?>
        <?php if ($tasks !== []): ?><div class="card-section-title mt-2">Tasks</div><?php foreach ($tasks as $t): ?><div class="mb-1"><?= esc($t['title']) ?> · <?= esc((string) $t['assignee']) ?> <?= status_badge($t['status']) ?></div><?php endforeach ?><?php endif ?>
    </div></div>
    <?php endif ?>
</div>
</div>

<div class="modal fade" id="stageModal" tabindex="-1" aria-labelledby="stTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('crm/leads/' . $l['id'] . '/stage') ?>"><?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="stTitle">Change stage</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <select class="form-select mb-2" name="stage_id" aria-label="Stage" data-stage-select><?php foreach ($stages as $s): if ((int) $s['is_won'] === 1) { continue; } ?><option value="<?= $s['id'] ?>" data-lost="<?= (int) $s['is_lost'] ?>" <?= $s['code'] === $l['stage_code'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?></select>
        <div class="d-none" data-lost-wrap><label class="form-label required" for="lost_reason_id">Lost reason</label><select class="form-select mb-2" id="lost_reason_id" name="lost_reason_id"><option value="">—</option><?php foreach ($lostReasons as $r): ?><option value="<?= $r['id'] ?>"><?= esc($r['name']) ?></option><?php endforeach ?></select></div>
        <input class="form-control" name="note" placeholder="Note (optional)" aria-label="Note">
        <div class="form-text">To mark Won, use “Convert to client”.</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark">Save</button></div>
</form></div></div></div>

<?php if (can('crm.lead.assign')): ?>
<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="asTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('crm/leads/' . $l['id'] . '/assign') ?>"><?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="asTitle">Reassign lead</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><select class="form-select mb-2" name="assigned_to" aria-label="Assign to"><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $u['id'] === (int) $l['assigned_to'] ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach ?></select>
        <input class="form-control" name="reason" placeholder="Reason (optional)" aria-label="Reason"><div class="form-text">Open follow-ups move to the new owner.</div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark">Reassign</button></div>
</form></div></div></div>
<?php endif ?>

<div class="modal fade" id="dndModal" tabindex="-1" aria-labelledby="dnTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('crm/leads/' . $l['id'] . '/dnd') ?>"><?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="dnTitle">Mark Do-Not-Call</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body small"><p>Use this when the person asks not to be called or the number is on the DND registry for promotional calls. Outbound call logging and follow-ups will be blocked and the number is masked in shared sheets.</p><input class="form-control" name="reason" placeholder="Reason" aria-label="Reason"></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Mark DND</button></div>
</form></div></div></div>

<?php if (can('crm.lead.delete')): ?>
<div class="modal fade" id="archiveModal" tabindex="-1" aria-labelledby="arTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="<?= site_url('crm/leads/' . $l['id'] . '/archive') ?>"><?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="arTitle">Archive lead</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><input class="form-control" name="reason" placeholder="Reason" aria-label="Reason" required></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Archive</button></div>
</form></div></div></div>
<?php endif ?>

<?php if (can('crm.lead.convert') && $l['converted_at'] === null): ?>
<div class="modal fade" id="convertModal" tabindex="-1" aria-labelledby="cvTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
<form method="post" action="<?= site_url('crm/leads/' . $l['id'] . '/convert') ?>"><?= csrf_field() ?>
    <div class="modal-header"><h2 class="modal-title fs-5" id="cvTitle">Convert <?= esc($l['company_name']) ?> to a client</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body small"><div class="row g-2">
        <div class="col-md-6"><label class="form-label" for="cv_contact">Client</label>
            <input type="hidden" name="contact_id" value="" data-cv-contact>
            <div class="dropdown"><input class="form-control" id="cv_contact" placeholder="Search existing client… (blank = create new)" autocomplete="off" data-cv-search data-lookup="<?= site_url('contacts/lookup?kind=any') ?>"><div class="dropdown-menu w-100" data-cv-results></div></div>
            <div class="form-text">Leave blank to create a new client from this lead.</div></div>
        <div class="col-md-3"><label class="form-label" for="cv_gstin">GSTIN (new client)</label><input class="form-control mono" id="cv_gstin" name="gstin" maxlength="15"></div>
        <div class="col-md-3"><label class="form-label" for="cv_value">Contract value (₹)</label><input class="form-control" id="cv_value" name="contract_value" inputmode="decimal" value="<?= esc((string) $l['expected_value']) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="cv_attr">Credit conversion to</label><select class="form-select" id="cv_attr" name="attributed_user_id"><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $u['id'] === (int) $l['assigned_to'] ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach ?></select></div>
        <div class="col-md-6"><label class="form-label" for="cv_doc">Also create a draft</label><select class="form-select" id="cv_doc" name="create_document"><option value="none">No document now</option><?php foreach (array_keys($docTypes) as $d): ?><option value="<?= $d ?>"><?= esc(doc_type_label($d)) ?> draft</option><?php endforeach ?></select></div>
        <div class="col-12"><label class="form-label" for="cv_svc">Services on the draft</label><select class="form-select" id="cv_svc" name="service_ids[]" multiple size="4"><?php foreach ($services as $s): ?><option value="<?= $s['id'] ?>"><?= esc($s['name']) ?></option><?php endforeach ?></select></div>
    </div><p class="text-muted mt-2 mb-0">A draft has no number and posts nothing until it is reviewed and finalized. Incentives are not paid on conversion; they follow the approved policy.</p></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-gold">Convert</button></div>
</form></div></div></div>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/crm-lead.js') ?>"></script><?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-xl-8">
<div class="table-card"><div class="table-responsive"><table class="table small align-middle">
    <thead><tr><th>When</th><th>Meeting</th><th>Lead</th><th>Mode</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $m): ?>
    <tr><td class="text-nowrap"><?= dmy($m['meeting_at'], true) ?><div class="text-muted"><?= (int) $m['duration_minutes'] ?> min</div></td><td><?= esc($m['title']) ?><div class="text-muted"><?= esc((string) $m['location_or_link']) ?></div><?= $m['outcome'] ? '<div>' . esc($m['outcome']) . '</div>' : '' ?></td>
        <td><?= $m['lead_id'] ? '<a href="' . site_url('crm/leads/' . $m['lead_id']) . '">' . esc((string) $m['company_name']) . '</a>' : '—' ?></td>
        <td><?= esc(str_replace('_', ' ', $m['mode'])) ?></td><td><?= status_badge($m['status']) ?></td>
        <td class="text-end"><?php if ($m['status'] === 'scheduled'): ?><form method="post" action="<?= site_url('crm/meetings/' . $m['id'] . '/complete') ?>" class="d-flex gap-1"><?= csrf_field() ?>
            <input class="form-control form-control-sm" name="outcome" placeholder="Outcome" aria-label="Outcome"><button class="btn btn-sm btn-outline-success" name="status" value="completed">Done</button><button class="btn btn-sm btn-outline-secondary" name="status" value="cancelled">Cancel</button></form><?php endif ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-calendar-event"></i>No meetings yet.</div></td></tr><?php endif ?>
</tbody></table></div></div>
</div>
<div class="col-xl-4" id="new">
<form method="post" action="<?= site_url('crm/meetings') ?>" class="card"><div class="card-body small">
    <?= csrf_field() ?>
    <div class="card-section-title">Schedule meeting<?= $lead ? ' with ' . esc($lead['company_name']) : '' ?></div>
    <input type="hidden" name="lead_id" value="<?= $lead ? (int) $lead['id'] : '' ?>">
    <label class="form-label required" for="mt_title">Title</label><input class="form-control mb-2" id="mt_title" name="title" required maxlength="200" value="<?= $lead ? esc('Discussion with ' . $lead['company_name']) : '' ?>">
    <label class="form-label required" for="mt_at">When</label><input type="datetime-local" class="form-control mb-2" id="mt_at" name="meeting_at" required>
    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label" for="mt_dur">Minutes</label><input type="number" min="5" max="600" class="form-control" id="mt_dur" name="duration_minutes" value="30"></div>
        <div class="col-6"><label class="form-label" for="mt_mode">Mode</label><select class="form-select" id="mt_mode" name="mode"><option value="in_person">In person</option><option value="phone">Phone</option><option value="video">Video</option></select></div></div>
    <label class="form-label" for="mt_loc">Location / link</label><input class="form-control mb-2" id="mt_loc" name="location_or_link" maxlength="300">
    <label class="form-label" for="mt_att">Attendees</label><select class="form-select mb-2" id="mt_att" name="attendee_ids[]" multiple size="4"><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['name']) ?></option><?php endforeach ?></select>
    <button class="btn btn-primary w-100" type="submit">Schedule</button>
</div></form>
</div>
</div>
<?= $this->endSection() ?>

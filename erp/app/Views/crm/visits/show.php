<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="row g-3">
<div class="col-lg-7">
    <div class="card mb-3"><div class="card-body small">
        <div class="d-flex justify-content-between"><div class="fs-5 fw-semibold"><?= esc($v['company_name'] ?? $v['client_name'] ?? $v['location']) ?></div><?= status_badge($v['status']) ?></div>
        <dl class="row mt-2 mb-0">
            <dt class="col-4">When</dt><dd class="col-8"><?= dmy($v['scheduled_at'], true) ?></dd>
            <dt class="col-4">Visitor</dt><dd class="col-8"><?= esc((string) $v['visitor']) ?></dd>
            <dt class="col-4">Address</dt><dd class="col-8"><?= esc((string) $v['address']) ?: '—' ?> <?php if ($v['address']): ?><a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode((string) $v['address']) ?>" target="_blank" rel="noopener">Map</a><?php endif ?></dd>
            <dt class="col-4">Purpose</dt><dd class="col-8"><?= esc((string) $v['purpose']) ?: '—' ?></dd>
            <?php if ($v['lead_id']): ?><dt class="col-4">Lead</dt><dd class="col-8"><a href="<?= site_url('crm/leads/' . $v['lead_id']) ?>"><?= esc($v['lead_code']) ?></a></dd><?php endif ?>
            <?php if ($v['outcome']): ?><dt class="col-4">Outcome</dt><dd class="col-8"><?= nl2br(esc($v['outcome'])) ?></dd><?php endif ?>
            <?php if ($v['client_feedback']): ?><dt class="col-4">Client feedback</dt><dd class="col-8"><?= nl2br(esc($v['client_feedback'])) ?></dd><?php endif ?>
            <?php if ($v['next_action']): ?><dt class="col-4">Next action</dt><dd class="col-8"><?= esc($v['next_action']) ?> <?= $v['next_action_at'] ? '(' . dmy($v['next_action_at'], true) . ')' : '' ?></dd><?php endif ?>
        </dl>
    </div></div>
    <?php if ($canUpdate && in_array($v['status'], ['scheduled', 'rescheduled'], true)): ?>
    <form method="post" action="<?= site_url('crm/visits/' . $v['id'] . '/complete') ?>" class="card"><div class="card-body small">
        <?= csrf_field() ?>
        <div class="card-section-title">Visit completed? Record what happened</div>
        <label class="form-label required" for="vo">Outcome</label><textarea class="form-control mb-2<?= is_invalid('outcome') ?>" id="vo" name="outcome" rows="2" placeholder="What was discussed / decided"></textarea>
        <label class="form-label" for="vf">Client feedback</label><textarea class="form-control mb-2" id="vf" name="client_feedback" rows="2"></textarea>
        <div class="row g-2 mb-2"><div class="col-md-7"><label class="form-label" for="vn">Next action</label><input class="form-control" id="vn" name="next_action" placeholder="e.g. Send revised quote"></div>
            <div class="col-md-5"><label class="form-label" for="vna">Follow up on</label><input type="datetime-local" class="form-control" id="vna" name="next_action_at"></div></div>
        <div class="d-flex gap-2"><button class="btn btn-dark" name="status" value="completed">Save outcome</button><button class="btn btn-outline-secondary" name="status" value="cancelled" formnovalidate>Visit cancelled</button></div>
    </div></form>
    <?php endif ?>
</div>
<div class="col-lg-5">
    <div class="card"><div class="card-body small">
        <div class="card-section-title">Visit timeline</div>
        <ul class="timeline"><?php foreach ($timeline as $t): ?><li><div><?= esc($t['text']) ?></div><div class="text-muted"><?= dmy($t['at'], true) ?><?= $t['who'] ? ' · ' . esc($t['who']) : '' ?></div></li><?php endforeach ?></ul>
    </div></div>
</div>
</div>
<?= $this->endSection() ?>

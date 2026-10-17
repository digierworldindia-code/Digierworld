<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url('crm/visits') ?>" class="card" style="max-width:820px" data-visit-form><div class="card-body">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="vs_lead">Lead</label>
            <input type="hidden" name="lead_id" value="<?= $lead ? (int) $lead['id'] : '' ?>" data-lead-id>
            <div class="dropdown"><input class="form-control" id="vs_lead" autocomplete="off" placeholder="Search lead…" value="<?= $lead ? esc($lead['company_name']) : '' ?>" data-lead-search data-lookup="<?= site_url('crm/leads/lookup') ?>"><div class="dropdown-menu w-100" data-lead-results></div></div></div>
        <div class="col-md-6"><label class="form-label required" for="vs_who">Who is visiting</label><select class="form-select" id="vs_who" name="visitor_id"><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['name']) ?></option><?php endforeach ?></select></div>
        <div class="col-md-4"><label class="form-label required" for="vs_at">Date &amp; time</label><input type="datetime-local" class="form-control<?= is_invalid('scheduled_at') ?>" id="vs_at" name="scheduled_at" required><?= field_error('scheduled_at') ?></div>
        <div class="col-md-8"><label class="form-label" for="vs_loc">Client / location</label><input class="form-control" id="vs_loc" name="location" maxlength="200" value="<?= $lead ? esc($lead['company_name']) : '' ?>" data-loc></div>
        <div class="col-12"><label class="form-label" for="vs_addr">Address</label><input class="form-control" id="vs_addr" name="address" maxlength="300" value="<?= $lead ? esc(trim($lead['address'] . ' ' . $lead['city'])) : '' ?>" data-addr></div>
        <div class="col-12"><label class="form-label" for="vs_pur">Purpose</label><input class="form-control" id="vs_pur" name="purpose" maxlength="300" placeholder="e.g. Present SEO + GBP proposal; collect logo and photos"></div>
    </div>
</div><div class="card-footer bg-white text-end"><a class="btn btn-outline-secondary" href="<?= site_url('crm/visits') ?>">Cancel</a> <button class="btn btn-dark" type="submit">Schedule visit</button></div></form>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= base_url('assets/js/crm-visit.js') ?>"></script><?= $this->endSection() ?>

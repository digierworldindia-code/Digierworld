<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Inspection ' . $r['request_number'], 'breadcrumbs' => ['Inspection' => $area . '/inspections', $r['request_number'] => null]]) ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="bc-card mb-4"><div class="bc-card-body">
            <dl class="dl-grid small mb-0">
                <dt>Status</dt><dd><?= status_badge($r['status'], $statuses[$r['status']]) ?></dd>
                <dt>Type</dt><dd><?= $r['inspection_type'] === 'in_house' ? 'In-house' : 'Third-party' ?></dd>
                <dt>Services</dt><dd><?= esc(implode(', ', array_map(static fn ($s) => $scopes[$s] ?? $s, (array) json_decode((string) $r['scope'], true)))) ?></dd>
                <dt>Location</dt><dd><?= esc(trim(($r['location_city'] ? $r['location_city'] . ', ' : '') . country_name($r['location_country']))) ?></dd>
                <?php if ($r['order_id']): ?><dt>Order</dt><dd><a href="<?= site_url($area . '/orders/' . $r['order_id']) ?>">View order</a></dd><?php endif ?>
                <dt>Invoice value</dt><dd><?= money($r['invoice_value'], $r['currency']) ?></dd>
                <dt>Estimate</dt><dd><?= $r['estimated_fee'] !== null ? money($r['estimated_fee'], $r['currency']) : '—' ?> <span class="text-muted">— <?= esc($r['fee_basis'] ?? '') ?></span></dd>
                <dt>Quoted fee</dt><dd><?= $r['quoted_fee'] !== null ? '<strong>' . money($r['quoted_fee'], $r['currency']) . '</strong>' . ($r['quote_valid_until'] ? ' · valid until ' . fdate($r['quote_valid_until']) : '') : 'Awaiting quotation' ?></dd>
                <?php if ($r['scheduled_on']): ?><dt>Scheduled</dt><dd><?= fdate($r['scheduled_on']) ?><?= $r['third_party_agency'] ? ' · ' . esc($r['third_party_agency']) : '' ?></dd><?php endif ?>
                <?php if ($r['notes']): ?><dt>Notes</dt><dd><?= esc($r['notes']) ?></dd><?php endif ?>
            </dl>
        </div></div>
        <?php if ($report): ?>
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Inspection report</h2><?= status_badge($report['result'] === 'pass' ? 'pass' : ($report['result'] === 'fail' ? 'fail' : 'conditional'), 'Result: ' . strtoupper($report['result'])) ?></div><div class="bc-card-body">
            <dl class="dl-grid small"><dt>Inspected on</dt><dd><?= fdate($report['inspected_on']) ?></dd><dt>Quantity</dt><dd><?= $report['quantity_verified'] !== null ? number_format((int) $report['quantity_verified']) . ($report['quantity_expected'] !== null ? ' of ' . number_format((int) $report['quantity_expected']) . ' expected' : '') : '—' ?></dd><dt>Packaging</dt><dd><?= $report['packaging_ok'] ? 'Acceptable' : 'Issues noted' ?></dd><?php if ($report['authenticity_notes']): ?><dt>Authenticity</dt><dd><?= esc($report['authenticity_notes']) ?></dd><?php endif ?></dl>
            <p style="white-space:pre-line"><?= esc($report['summary']) ?></p>
            <?php if ($report['findings']): ?><p class="small text-muted" style="white-space:pre-line"><?= esc($report['findings']) ?></p><?php endif ?>
        </div></div>
        <?php endif ?>
    </div>
    <div class="col-lg-4">
        <?php if ($isRequester && $r['status'] === 'quoted'): ?>
        <div class="bc-card mb-3"><div class="bc-card-body">
            <h2 class="h6">Respond to quotation</h2>
            <p class="small text-muted">Accepting issues an invoice for <?= money($r['quoted_fee'], $r['currency']) ?> (plus applicable tax).</p>
            <div class="d-flex gap-2"><?= post_button($area . '/inspections/' . $r['id'] . '/respond', 'Accept', 'btn btn-primary btn-sm', null, ['decision' => 'accept']) ?><?= post_button($area . '/inspections/' . $r['id'] . '/respond', 'Decline', 'btn btn-light btn-sm', 'Decline this quotation?', ['decision' => 'decline']) ?></div>
        </div></div>
        <?php endif ?>
        <div class="bc-card"><div class="bc-card-header"><h2>Documents</h2></div><div class="bc-card-body">
            <?php if (! $documents): ?><p class="small text-muted mb-0">The report and evidence will appear here once uploaded by the inspector.</p><?php endif ?>
            <?php foreach ($documents as $d): ?><div class="small mb-1"><a href="<?= site_url('documents/' . $d['uuid']) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> <?= esc($d['title']) ?></a></div><?php endforeach ?>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>

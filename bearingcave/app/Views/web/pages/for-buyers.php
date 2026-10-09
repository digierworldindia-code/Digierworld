<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?= view('web/pages/_hero', ['eyebrow' => 'For buyers', 'heading' => 'Genuine branded parts, competitive surplus prices', 'lead' => 'Source OEM and aftermarket components from manufacturers and verified suppliers clearing surplus, obsolete and slow-moving stock.']) ?>
<div class="container py-5">
    <div class="row g-4 mb-5">
        <?php foreach ([
            ['bi-upc-scan', 'Precise part search', 'Search manufacturer, OEM, alternate and cross-reference numbers, plus bearing dimensions and vehicle compatibility. We never present a cross reference as a guaranteed interchange.'],
            ['bi-file-earmark-text', 'RFQs that reach the right suppliers', 'Describe what you need once. BearingCave matches your RFQ with verified suppliers who list the part, brand or category.'],
            ['bi-layout-three-columns', 'Compare offers', 'Compare price, stock, delivery terms, inspection options and verification status side by side.'],
            ['bi-clipboard-check', 'Inspection & logistics', 'Add physical, quantity, packaging and authenticity inspection, and choose managed or consolidated shipping.'],
        ] as [$i, $h, $t]): ?>
        <div class="col-md-6"><div class="d-flex gap-3"><span class="feature-icon flex-shrink-0"><i class="bi <?= $i ?>" aria-hidden="true"></i></span><div><h2 class="h6"><?= esc($h) ?></h2><p class="small text-muted mb-0"><?= esc($t) ?></p></div></div></div>
        <?php endforeach ?>
    </div>
    <div class="bc-card"><div class="table-responsive"><table class="table table-bc mb-0">
        <thead><tr><th>Feature</th><th class="text-center">Buyer (free)</th><th class="text-center">Verified buyer (free)</th></tr></thead>
        <tbody>
        <?php foreach ([['Search & browse marketplace', 1, 1], ['Standard RFQs & quotations', 1, 1], ['Orders, inspection & logistics requests', 1, 1], ['Dispute support', 1, 1], ['Verified buyer badge', 0, 1], ['Eligibility for approved restricted inventory', 0, 1], ['Advanced supplier comparison (performance & risk)', 0, 1], ['Consolidation services', 0, 1], ['Higher open-RFQ limit', 0, 1]] as [$f, $a, $b]): ?>
            <tr><td><?= esc($f) ?></td><td class="text-center"><?= $a ? '<i class="bi bi-check2 text-success" aria-label="Included"></i>' : '<i class="bi bi-dash text-muted" aria-label="Not included"></i>' ?></td><td class="text-center"><?= $b ? '<i class="bi bi-check2 text-success" aria-label="Included"></i>' : '<i class="bi bi-dash text-muted" aria-label="Not included"></i>' ?></td></tr>
        <?php endforeach ?>
        </tbody>
    </table></div></div>
    <p class="small text-muted mt-2">Access to restricted inventory is granted individually after verification — not automatically on registration.</p>
    <a class="btn btn-primary mt-3" href="<?= site_url('register/buyer') ?>">Register as a buyer</a>
</div>
<?= $this->endSection() ?>

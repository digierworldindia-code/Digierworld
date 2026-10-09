<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<?= view('web/pages/_hero', ['eyebrow' => 'Trust & verification', 'heading' => 'How BearingCave verifies suppliers', 'lead' => 'The Verified Supplier badge is awarded only after a multi-stage review and management approval, and stays active only with a current membership.']) ?>
<div class="container py-5">
    <div class="row g-3">
        <?php foreach ([
            ['Registration & profile', 'Legal name, address, registration numbers, directors/owners, categories and company documents.'],
            ['KYC / KYB', 'GST, PAN, CIN, VAT or tax ID, owner and beneficial-owner checks, bank and address validation.'],
            ['Compliance', 'ISO 9001, IATF 16949, AS9100, RoHS, REACH, ESG, insurance and applicable labour compliance.'],
            ['Financial verification', 'Turnover, profitability, balance sheet, credit rating, banking references, legal cases and insolvency history.'],
            ['Risk assessment', 'Financial, operational, compliance, geographic, supply and ESG risk scored on recorded evidence (LOW / MEDIUM / HIGH).'],
            ['Quality assessment', 'Inspection systems, PPAP, APQP, FMEA, CAPA, QC processes and rejection history.'],
            ['Site audit', 'Manufacturing capability, capacity, machinery, warehouse management, calibration and workforce.'],
            ['Sanctions screening', 'UN, OFAC, PEP and AML screening through authorised providers or documented manual checks.'],
            ['Documents & expiry', 'Secure, versioned document storage with expiry reminders and review comments.'],
            ['Approval workflow', 'Assigned reviewers for each stage, followed by management approval or a documented rejection.'],
            ['Performance monitoring', 'On-time delivery, quality rejections, cost competitiveness, response time and service level from real orders.'],
            ['Approved Vendor List', 'Category, brand and product approvals that can be suspended or reactivated.'],
        ] as $i => [$h, $t]): ?>
        <div class="col-md-6 col-lg-4"><div class="bc-card h-100 p-3"><div class="d-flex gap-2 align-items-center mb-1"><span class="step-num" style="width:28px;height:28px;font-size:.8rem"><?= $i + 1 ?></span><h2 class="h6 mb-0"><?= esc($h) ?></h2></div><p class="small text-muted mb-0"><?= esc($t) ?></p></div></div>
        <?php endforeach ?>
    </div>
    <p class="small text-muted mt-4">Verification reflects checks performed at the time of review using the information and providers available. It is not a guarantee of future performance; buyers should use inspection and agreed commercial terms for each transaction.</p>
</div>
<?= $this->endSection() ?>

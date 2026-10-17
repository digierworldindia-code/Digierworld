<?= $this->extend('layouts/app') ?>
<?php
$stepCard = static function (int $n, string $key, string $title, string $url, bool $done, string $body) {
    return '<div class="col-md-6 col-xxl-4"><div class="card h-100 ' . ($done ? 'border-success' : '') . '"><div class="card-body">'
        . '<div class="d-flex justify-content-between align-items-start mb-2"><div class="fw-semibold"><span class="badge ' . ($done ? 'bg-success' : 'bg-secondary') . ' me-2">' . ($done ? '✓' : $n) . '</span>' . esc($title) . '</div>'
        . '<a href="' . site_url($url . '?wizard=1') . '" class="btn btn-sm ' . ($done ? 'btn-outline-dark' : 'btn-gold') . '">' . ($done ? 'Review' : 'Set up') . '</a></div>'
        . '<div class="small">' . $body . '</div></div></div></div>';
};
$addr = trim(implode(', ', array_filter([$c['reg_address_line1'] ?? '', $c['reg_address_line2'] ?? '', $c['reg_city'] ?? '', $c['reg_pincode'] ?? ''])));
?>
<?= $this->section('content') ?>
<p class="text-muted">Set these up once. Every new invoice, cash memo and receipt then picks them up automatically — and each finalized document stores a snapshot, so later edits never change documents already issued.</p>
<?= view('settings/_wizard', ['current' => '']) ?>
<div class="row g-3">
<?= $stepCard(1, 'company', 'Legal business profile', 'settings/company', ! empty($steps['company']),
    $c['legal_name'] ?? null
        ? '<strong>' . esc($c['legal_name']) . '</strong>' . ($c['trade_name'] ? ' (' . esc($c['trade_name']) . ')' : '') . '<br>GSTIN <span class="mono">' . esc($c['gstin'] ?? '—') . '</span> · PAN <span class="mono">' . esc($c['pan'] ?? '—') . '</span><br>' . esc($state['name'] ?? '') . ' (' . esc($c['state_code'] ?? '') . ')<br><span class="text-muted">' . esc($addr) . '</span>'
        : '<span class="text-danger">Not entered.</span> Tax documents cannot be finalized until the legal name and state are saved.') ?>
<?= $stepCard(2, 'branding', 'Brand assets', 'settings/branding', ! empty($steps['branding']),
    ($logo ? '<div class="asset-preview mb-2" style="min-height:60px"><img src="' . site_url('settings/branding/' . $logo['id'] . '/preview') . '" alt="Logo" style="max-height:50px"></div>' : '<span class="text-danger">No logo uploaded.</span><br>')
    . 'Signature: ' . ($signature ? 'v' . (int) $signature['version'] : '—') . ' · Stamp: ' . ($stamp ? 'v' . (int) $stamp['version'] : '—')) ?>
<?php
$bankBody = '';
foreach ($banks as $b) {
    $bankBody .= '<div>' . ($b['is_default_on_invoice'] ? '★ ' : '') . esc($b['display_name']) . ' · ' . mask_tail($b['account_last4']) . ($b['show_on_invoice'] ? '' : ' <span class="text-muted">(not printed)</span>') . '</div>';
}
?>
<?= $stepCard(3, 'banks', 'Bank & payment profiles', 'settings/banks', ! empty($steps['banks']), $bankBody ?: '<span class="text-muted">No bank account yet (optional — needed only if printed on invoices).</span>') ?>
<?= $stepCard(4, 'tax', 'Tax & finance defaults', 'settings/tax', ! empty($steps['tax']),
    'FY ' . esc($fy['code']) . ' · terms ' . (int) ($c['default_payment_terms_days'] ?? 15) . ' days · ' . (($c['rounding_policy'] ?? '') === 'none' ? 'no rounding' : 'round to ₹1') . '<br>' . ($reviewed ? '<span class="text-success">' . $reviewed . ' rate(s) accountant-reviewed</span>' : '<span class="text-warning">Rates not yet reviewed by accountant</span>')) ?>
<?php $seqBody = ''; foreach ($seqs as $s) { $seqBody .= '<div>' . esc(doc_type_label($s['doc_type'])) . ': next <span class="mono">' . esc($s['next']) . '</span></div>'; } ?>
<?= $stepCard(5, 'sequences', 'Document series', 'settings/sequences', ! empty($steps['sequences']), $seqBody) ?>
<?= $stepCard(6, 'templates', 'Invoice template', 'settings/templates', ! empty($steps['templates']), $template ? 'Default: <strong>' . esc($template['name']) . '</strong> (v' . (int) $template['version'] . ', ' . esc($template['paper']) . ')' : 'Not chosen') ?>
<?= $stepCard(7, 'signature', 'Signature policy', 'settings/signature-policy', ! empty($steps['signature']), $policies ? $policies . ' owner-approved rule(s)' : 'No one may apply the signature yet (safe default).') ?>
</div>
<?= $this->endSection() ?>

<?php
/**
 * Billing document PDF (A4/A5). Renders ONLY from the snapshot array so a
 * finalized document always reprints exactly as issued.
 *
 * @var array<string, mixed> $s       snapshot (supplier, customer, bank, branding, template, lines, tax_summary, totals, header)
 * @var array<string, string> $images data URIs keyed by branding slot
 * @var string $status   draft|finalized|cancelled
 * @var string $copyLabel e.g. ORIGINAL FOR RECIPIENT
 * @var array<string, mixed>|null $original original invoice for notes
 * @var array<string, string> $treatmentLabels
 */
use App\Libraries\Dec;

$h        = $s['header'];
$t        = $s['totals'];
$sup      = $s['supplier'];
$cus      = $s['customer'];
$tpl      = $s['template'];
$set      = $tpl['settings'];
$layout   = $tpl['layout'];
$gstType  = $h['gst_type'] ?? 'none';
$docType  = $h['doc_type'];
$titles   = ['tax_invoice' => 'TAX INVOICE', 'bill_of_supply' => 'BILL OF SUPPLY', 'cash_memo' => 'CASH MEMO', 'estimate' => 'ESTIMATE', 'quotation' => 'QUOTATION',
    'proforma' => 'PROFORMA INVOICE', 'credit_note' => 'CREDIT NOTE', 'debit_note' => 'DEBIT NOTE'];
$title    = $titles[$docType] ?? strtoupper($docType);
if ($docType === 'cash_memo' && in_array($gstType, ['cgst_sgst', 'cgst_utgst', 'igst'], true)) {
    $title = 'CASH MEMO — TAX INVOICE';
}
$accent   = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($set['accent'] ?? '')) ? $set['accent'] : '#222222';
$fs       = (float) ($set['font_size'] ?? 9.5);
$number   = $h['document_number'] ?: ($h['draft_ref'] ?? 'DRAFT');
$taxCols  = match ($gstType) {
    'cgst_sgst'  => [['CGST', 'cgst'], ['SGST', 'sgst']],
    'cgst_utgst' => [['CGST', 'cgst'], ['UTGST', 'utgst']],
    'igst'       => [['IGST', 'igst']],
    default      => [],
};
$hasCess = Dec::isPositive($t['cess_total'] ?? '0');
$hasDisc = Dec::isPositive($t['discount_total'] ?? '0');
$money   = static fn ($v) => inr((string) $v, false);
$rate    = static fn ($v) => Dec::rateLabel((string) $v) . '%';
$typeLine = ['cgst_sgst' => 'CGST + SGST (intra-state supply)', 'cgst_utgst' => 'CGST + UTGST (intra-Union Territory supply)', 'igst' => 'IGST (inter-state supply)', 'none' => 'No GST charged'][$gstType] ?? '—';
$reverse = ! empty($h['reverse_charge']) || in_array('reverse_charge', array_column($s['lines'], 'tax_treatment'), true);
$posLabel = ($h['place_of_supply_name'] ?? '') . (isset($h['place_of_supply_state_code']) && $h['place_of_supply_state_code'] ? ' (' . $h['place_of_supply_state_code'] . ')' : '');
?>
<!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: <?= (float) ($set['margin_mm'] ?? 12) ?>mm <?= (float) ($set['margin_mm'] ?? 12) ?>mm 16mm <?= (float) ($set['margin_mm'] ?? 12) ?>mm; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: <?= $fs ?>pt; color: #111; line-height: 1.32; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .muted { color: #555; }
    .small { font-size: <?= $fs - 1.5 ?>pt; }
    .right { text-align: right; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    .title { font-size: <?= $fs + 6 ?>pt; font-weight: bold; letter-spacing: 1px; color: <?= $layout === 'premium' ? '#111' : $accent ?>; }
    .copy { font-size: <?= $fs - 2 ?>pt; letter-spacing: 1px; color: #555; }
    .rule { border-top: <?= $layout === 'premium' ? '2px solid ' . $accent : ($layout === 'minimal' ? '1px solid #bbb' : '2px solid #111') ?>; margin: 6px 0; }
    .box { border: <?= $layout === 'minimal' ? '1px solid #ccc' : '1px solid #333' ?>; padding: 6px 8px; }
    .label { font-size: <?= $fs - 2 ?>pt; text-transform: uppercase; letter-spacing: .5px; color: #555; }
    table.items th { background: <?= $layout === 'minimal' ? '#f2f2f2' : ($layout === 'premium' ? '#111' : '#e9e9e9') ?>; color: <?= $layout === 'premium' ? '#fff' : '#111' ?>; font-size: <?= $fs - 1.5 ?>pt; padding: 4px 3px; border: 1px solid <?= $layout === 'minimal' ? '#ccc' : '#333' ?>; }
    table.items td { padding: 4px 3px; border: 1px solid <?= $layout === 'minimal' ? '#ddd' : '#555' ?>; font-size: <?= $fs - 0.5 ?>pt; }
    table.items tr { page-break-inside: avoid; }
    table.sum td { padding: 2px 4px; }
    table.sum tr.grand td { font-weight: bold; font-size: <?= $fs + 2 ?>pt; border-top: 2px solid #111; padding-top: 4px; }
    table.taxsum th, table.taxsum td { border: 1px solid #999; padding: 2px 4px; font-size: <?= $fs - 1.5 ?>pt; }
    table.taxsum th { background: #f2f2f2; }
    .accent-bar { height: 4px; background: <?= $accent ?>; margin-bottom: 6px; }
    .watermark { position: fixed; top: 38%; left: 0; width: 100%; text-align: center; font-size: 46pt; color: rgba(200, 0, 0, .13); transform: rotate(-28deg); font-weight: bold; }
    .sig img { max-height: 70px; }
    .nowrap { white-space: nowrap; }
</style></head>
<body>
<?php if ($status === 'draft'): ?><div class="watermark">DRAFT — NOT A VALID <?= in_array($docType, ['estimate', 'quotation', 'proforma'], true) ? 'DOCUMENT' : 'TAX DOCUMENT' ?></div><?php endif ?>
<?php if ($status === 'cancelled'): ?><div class="watermark">CANCELLED</div><?php endif ?>
<?php if ($layout === 'premium'): ?><div class="accent-bar"></div><?php endif ?>
<?php
$logoPos  = in_array($set['logo_position'] ?? 'top_left', ['top_left', 'top_center', 'top_right'], true) ? ($set['logo_position'] ?? 'top_left') : 'top_left';
$logoHtml = ! empty($images['logo'])
    ? '<img src="' . $images['logo'] . '" style="width:' . (float) ($s['branding']['logo']['width_mm'] ?? 45) . 'mm; max-height:26mm; margin-bottom:4px" alt="logo">'
    : '';
?>
<?php if ($logoHtml !== '' && $logoPos !== 'top_left'): ?><div class="<?= $logoPos === 'top_center' ? 'center' : 'right' ?>"><?= $logoHtml ?></div><?php endif ?>

<table>
    <tr>
        <td style="width:58%">
            <?php if ($logoHtml !== '' && $logoPos === 'top_left'): ?><?= $logoHtml ?><br><?php endif ?>
            <div class="bold" style="font-size:<?= $fs + 3 ?>pt"><?= esc($sup['legal_name'] ?: 'COMPANY LEGAL NAME NOT SET') ?></div>
            <?php if (! empty($sup['trade_name']) && $sup['trade_name'] !== $sup['legal_name']): ?><div>Trade name: <?= esc($sup['trade_name']) ?></div><?php endif ?>
            <?php foreach ($sup['address'] as $line): ?><div><?= esc($line) ?></div><?php endforeach ?>
            <div><?= esc($sup['state_name'] ?? '') ?><?= ! empty($sup['state_code']) ? ' — State code ' . esc($sup['state_code']) : '' ?></div>
            <?php if (! empty($sup['gstin'])): ?><div><span class="bold">GSTIN:</span> <?= esc($sup['gstin']) ?><?= ! empty($sup['pan']) ? ' &nbsp; <span class="bold">PAN:</span> ' . esc($sup['pan']) : '' ?></div><?php endif ?>
            <div class="small muted"><?= esc(implode(' · ', array_filter([$sup['phone'] ?? null, $sup['email'] ?? null, $sup['website'] ?? null]))) ?></div>
        </td>
        <td style="width:42%" class="right">
            <div class="title"><?= esc($title) ?></div>
            <?php if ($copyLabel !== ''): ?><div class="copy"><?= esc($copyLabel) ?></div><?php endif ?>
            <table style="margin-top:6px">
                <tr><td class="right label">Number</td><td class="right bold nowrap" style="width:48%"><?= esc($number) ?></td></tr>
                <tr><td class="right label">Date</td><td class="right"><?= dmy($h['issue_date']) ?></td></tr>
                <?php if (! empty($h['due_date']) && ! in_array($docType, ['credit_note'], true)): ?><tr><td class="right label"><?= in_array($docType, ['estimate', 'quotation', 'proforma'], true) ? 'Valid till' : 'Due date' ?></td><td class="right"><?= dmy($h['due_date']) ?></td></tr><?php endif ?>
                <?php if (! empty($h['supply_date'])): ?><tr><td class="right label">Date of supply</td><td class="right"><?= dmy($h['supply_date']) ?></td></tr><?php endif ?>
                <?php if (! empty($h['service_period_from'])): ?><tr><td class="right label">Service period</td><td class="right"><?= dmy($h['service_period_from']) ?> – <?= dmy($h['service_period_to']) ?></td></tr><?php endif ?>
                <tr><td class="right label">Place of supply</td><td class="right"><?= esc($posLabel ?: '—') ?></td></tr>
                <?php if (in_array($docType, ['tax_invoice', 'cash_memo', 'credit_note', 'debit_note'], true)): ?><tr><td class="right label">Reverse charge</td><td class="right"><?= $reverse ? 'Yes' : 'No' ?></td></tr><?php endif ?>
                <?php if (! empty($h['po_number'])): ?><tr><td class="right label">PO number</td><td class="right"><?= esc($h['po_number']) ?></td></tr><?php endif ?>
                <?php if (! empty($h['reference_no'])): ?><tr><td class="right label">Reference</td><td class="right"><?= esc($h['reference_no']) ?></td></tr><?php endif ?>
                <?php if (! empty($h['einvoice_irn'])): ?><tr><td class="right label">IRN</td><td class="right small"><?= esc($h['einvoice_irn']) ?></td></tr><?php endif ?>
            </table>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table>
    <tr>
        <td style="width:58%" class="box">
            <div class="label"><?= in_array($docType, ['credit_note', 'debit_note'], true) ? 'Issued to' : 'Bill to' ?></div>
            <div class="bold"><?= esc($cus['name']) ?></div>
            <div><?= nl2br(esc((string) $cus['address'])) ?></div>
            <?php if (! empty($cus['gstin'])): ?><div><span class="bold">GSTIN:</span> <?= esc($cus['gstin']) ?></div><?php else: ?><div class="small muted">Unregistered recipient</div><?php endif ?>
            <div><?= esc($cus['state_name'] ?? '') ?><?= ! empty($cus['state_code']) ? ' — State code ' . esc($cus['state_code']) : '' ?></div>
            <?php if (! empty($cus['phone']) || ! empty($cus['email'])): ?><div class="small muted"><?= esc(implode(' · ', array_filter([$cus['phone'] ?? null, $cus['email'] ?? null]))) ?></div><?php endif ?>
        </td>
        <td style="width:2%"></td>
        <td style="width:40%" class="box">
            <div class="label">GST type</div>
            <div class="bold"><?= esc($typeLine) ?></div>
            <?php if ($original !== null): ?>
                <div class="label" style="margin-top:5px">Against original document</div>
                <div><?= esc(($titles[$original['doc_type']] ?? '') . ' ' . $original['document_number']) ?> dated <?= dmy($original['issue_date']) ?></div>
                <?php if (! empty($noteReason)): ?><div class="small">Reason: <?= esc($noteReason) ?></div><?php endif ?>
            <?php endif ?>
            <?php if (($h['export_type'] ?? 'none') === 'lut'): ?><div class="small" style="margin-top:5px">Supply meant for export / SEZ under LUT<?= ! empty($sup['lut_number']) ? ' No. ' . esc($sup['lut_number']) : '' ?> without payment of IGST.</div><?php endif ?>
            <?php if (($h['export_type'] ?? 'none') === 'with_payment'): ?><div class="small" style="margin-top:5px">Supply meant for export / SEZ with payment of IGST.</div><?php endif ?>
        </td>
    </tr>
</table>

<table class="items" style="margin-top:8px">
    <thead>
    <tr>
        <th style="width:3%">#</th>
        <th style="width:<?= max(20, 100 - 3 - 7 - 7 - 10 - ($hasDisc ? 8 : 0) - 11 - count($taxCols) * 13 - ($hasCess ? 7 : 0) - 11) ?>%">Description of service</th>
        <th style="width:7%">SAC</th>
        <th style="width:7%">Qty</th>
        <th style="width:10%">Rate</th>
        <?php if ($hasDisc): ?><th style="width:8%">Disc.</th><?php endif ?>
        <th style="width:11%">Taxable value</th>
        <?php foreach ($taxCols as [$label]): ?><th style="width:4%"><?= $label ?> %</th><th style="width:9%"><?= $label ?> ₹</th><?php endforeach ?>
        <?php if ($hasCess): ?><th style="width:7%">Cess ₹</th><?php endif ?>
        <th style="width:11%">Amount</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($s['lines'] as $l): ?>
        <tr>
            <td class="center"><?= (int) $l['line_no'] ?></td>
            <td><span class="bold"><?= esc($l['item_name']) ?></span><?php if (! empty($l['description'])): ?><br><span class="small"><?= nl2br(esc($l['description'])) ?></span><?php endif ?>
                <?php if (($l['tax_treatment'] ?? 'regular') !== 'regular'): ?><br><span class="small muted"><?= esc($treatmentLabels[$l['tax_treatment']] ?? $l['tax_treatment']) ?></span><?php endif ?></td>
            <td class="center"><?= esc((string) ($l['sac_code'] ?? '')) ?></td>
            <td class="right"><?= esc(rtrim(rtrim((string) $l['qty'], '0'), '.')) ?> <?= esc((string) ($l['unit'] ?? '')) ?></td>
            <td class="right"><?= $money($l['unit_rate']) ?></td>
            <?php if ($hasDisc): ?><td class="right"><?= Dec::isPositive($l['discount_amount'] ?? '0') ? $money($l['discount_amount']) : '—' ?></td><?php endif ?>
            <td class="right"><?= $money($l['taxable_value']) ?></td>
            <?php foreach ($taxCols as [, $k]): ?>
                <td class="right"><?= Dec::isPositive($l[$k . '_rate']) ? $rate($l[$k . '_rate']) : '—' ?></td>
                <td class="right"><?= Dec::isPositive($l[$k . '_amount']) ? $money($l[$k . '_amount']) : '—' ?></td>
            <?php endforeach ?>
            <?php if ($hasCess): ?><td class="right"><?= $money($l['cess_amount']) ?></td><?php endif ?>
            <td class="right bold"><?= $money($l['line_total']) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>

<table style="margin-top:8px">
    <tr>
        <td style="width:57%">
            <?php if (($set['show_tax_summary'] ?? true) && $s['tax_summary'] !== []): ?>
            <table class="taxsum">
                <tr><th>Tax type</th><th>Rate</th><th class="right">Taxable value (₹)</th><th class="right">Tax (₹)</th></tr>
                <?php foreach ($s['tax_summary'] as $ts): ?>
                    <tr><td><?= esc($ts['tax_type']) ?></td><td><?= $rate($ts['rate']) ?></td><td class="right"><?= $money($ts['taxable_value']) ?></td><td class="right"><?= $money($ts['tax_amount']) ?></td></tr>
                <?php endforeach ?>
            </table>
            <?php endif ?>
            <?php if ($reverse): ?><div class="small" style="margin-top:4px"><span class="bold">Tax payable on reverse charge: Yes</span> — GST on such lines is payable by the recipient and is not included above.</div><?php endif ?>
            <?php if ($set['show_amount_words'] ?? true): ?>
                <div style="margin-top:6px"><span class="label">Amount in words</span><br><span class="bold"><?= esc((string) ($t['amount_in_words'] ?? '')) ?></span></div>
            <?php endif ?>
        </td>
        <td style="width:3%"></td>
        <td style="width:40%">
            <table class="sum">
                <?php if (Dec::isPositive($t['discount_total'] ?? '0')): ?>
                <tr><td>Sub-total</td><td class="right"><?= $money($t['subtotal']) ?></td></tr>
                <tr><td>Discount</td><td class="right">− <?= $money($t['discount_total']) ?></td></tr>
                <?php endif ?>
                <tr><td>Taxable value</td><td class="right"><?= $money($t['taxable_total']) ?></td></tr>
                <?php foreach ($taxCols as [$label, $k]): ?>
                    <tr><td><?= $label ?></td><td class="right"><?= $money($t[$k . '_total']) ?></td></tr>
                <?php endforeach ?>
                <?php if ($hasCess): ?><tr><td>Cess</td><td class="right"><?= $money($t['cess_total']) ?></td></tr><?php endif ?>
                <?php if (! Dec::isZero($t['round_off'] ?? '0')): ?><tr><td>Round-off</td><td class="right"><?= $money($t['round_off']) ?></td></tr><?php endif ?>
                <tr class="grand"><td>Total (₹)</td><td class="right"><?= $money($t['grand_total']) ?></td></tr>
            </table>
        </td>
    </tr>
</table>

<table style="margin-top:10px">
    <tr>
        <td style="width:58%">
            <?php if (($set['show_bank'] ?? true) && ! empty($s['bank']) && ! in_array($docType, ['credit_note'], true)): $b = $s['bank']; ?>
                <div class="box">
                    <div class="label">Payment details</div>
                    <table><tr><td>
                        <?php if (! empty($b['beneficiary_name'])): ?><div>Beneficiary: <span class="bold"><?= esc($b['beneficiary_name']) ?></span></div><?php endif ?>
                        <?php if (! empty($b['bank_name'])): ?><div>Bank: <?= esc($b['bank_name']) ?><?= ! empty($b['branch']) ? ', ' . esc($b['branch']) : '' ?></div><?php endif ?>
                        <?php if (! empty($b['account_number'])): ?><div>A/c no.: <span class="bold"><?= esc($b['account_number']) ?></span><?= ! empty($b['account_type']) ? ' (' . esc(ucfirst($b['account_type'])) . ')' : '' ?></div><?php endif ?>
                        <?php if (! empty($b['ifsc'])): ?><div>IFSC: <span class="bold"><?= esc($b['ifsc']) ?></span></div><?php endif ?>
                        <?php if (! empty($b['upi_id'])): ?><div>UPI: <?= esc($b['upi_id']) ?></div><?php endif ?>
                    </td><?php if (! empty($images['upi_qr'])): ?><td style="width:28mm" class="right"><img src="<?= $images['upi_qr'] ?>" style="width:<?= min(30, (float) ($s['branding']['upi_qr']['width_mm'] ?? 26)) ?>mm" alt="UPI QR"></td><?php endif ?></tr></table>
                </div>
            <?php endif ?>
            <?php if (($set['show_terms'] ?? true) && (! empty($h['terms']) || ! empty($h['notes']))): ?>
                <div style="margin-top:6px">
                    <?php if (! empty($h['notes'])): ?><div class="label">Notes</div><div class="small"><?= nl2br(esc((string) $h['notes'])) ?></div><?php endif ?>
                    <?php if (! empty($h['terms'])): ?><div class="label" style="margin-top:4px">Terms &amp; conditions</div><div class="small"><?= nl2br(esc((string) $h['terms'])) ?></div><?php endif ?>
                </div>
            <?php endif ?>
        </td>
        <td style="width:4%"></td>
        <td style="width:38%" class="center sig">
            <div class="small">For <span class="bold"><?= esc($sup['legal_name'] ?: '') ?></span></div>
            <?php if (! empty($h['signed']) && (! empty($images['sign_stamp']) || ! empty($images['signature']) || ! empty($images['stamp']))): ?>
                <div style="height:78px; margin-top:4px">
                    <?php if (! empty($images['sign_stamp'])): ?><img src="<?= $images['sign_stamp'] ?>" style="width:<?= (float) ($s['branding']['sign_stamp']['width_mm'] ?? 40) ?>mm" alt="signature and stamp">
                    <?php else: ?>
                        <?php if (! empty($images['stamp'])): ?><img src="<?= $images['stamp'] ?>" style="width:<?= (float) ($s['branding']['stamp']['width_mm'] ?? 26) ?>mm" alt="stamp"><?php endif ?>
                        <?php if (! empty($images['signature'])): ?><img src="<?= $images['signature'] ?>" style="width:<?= (float) ($s['branding']['signature']['width_mm'] ?? 35) ?>mm" alt="signature"><?php endif ?>
                    <?php endif ?>
                </div>
                <div class="bold">Authorised Signatory</div>
            <?php else: ?>
                <div style="height:60px"></div>
                <div class="bold">Authorised Signatory</div>
                <div class="small muted">Computer-generated document — signature not applied</div>
            <?php endif ?>
        </td>
    </tr>
</table>

<?php if (! empty($sup['disclaimer'])): ?><div class="small muted" style="margin-top:8px"><?= esc($sup['disclaimer']) ?></div><?php endif ?>
<?php if (! empty($set['footer_note'])): ?><div class="small muted center" style="margin-top:6px"><?= esc($set['footer_note']) ?></div><?php endif ?>
</body></html>

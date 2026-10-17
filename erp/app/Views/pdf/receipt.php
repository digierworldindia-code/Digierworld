<?php
/**
 * Receipt / refund voucher (A5 landscape). Never prints bank account
 * numbers or CIF — only the account's display name.
 *
 * @var array<string, mixed> $r
 * @var list<array<string, mixed>> $allocs
 * @var array<string, mixed> $contact
 * @var array<string, mixed> $supplier
 * @var string|null $logo
 * @var array<string, string> $modes
 * @var string $words
 */
$isRefund = $r['receipt_type'] === 'refund';
$void     = $r['status'] === 'void';
?>
<!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 10mm 12mm 14mm 12mm; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 9pt; color: #111; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .muted { color: #555; }
    .right { text-align: right; }
    .title { font-size: 14pt; font-weight: bold; letter-spacing: 1px; }
    .box { border: 1px solid #999; padding: 6px 8px; }
    .grid td { padding: 3px 6px; border-bottom: 1px solid #ddd; }
    .grid th { padding: 4px 6px; background: #111; color: #fff; font-size: 8pt; text-align: left; }
    .amount { font-size: 15pt; font-weight: bold; }
    .label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .5px; color: #555; }
    .watermark { position: fixed; top: 35%; left: 18%; font-size: 64pt; color: rgba(200, 0, 0, .12); transform: rotate(-20deg); font-weight: bold; }
    .rule { border-top: 2px solid #b08d57; margin: 6px 0 8px; }
</style></head>
<body>
<?php if ($void): ?><div class="watermark">VOID</div><?php endif ?>
<table>
    <tr>
        <td style="width:60%">
            <?php if ($logo): ?><img src="<?= $logo ?>" style="max-height:38px; max-width:150px" alt=""><br><?php endif ?>
            <strong style="font-size:11pt"><?= esc((string) ($supplier['legal_name'] ?: $supplier['trade_name'])) ?></strong><br>
            <?php foreach ($supplier['address'] as $line): ?><span class="muted"><?= esc($line) ?></span><br><?php endforeach ?>
            <?php if ($supplier['gstin']): ?>GSTIN: <strong><?= esc($supplier['gstin']) ?></strong><?php endif ?>
        </td>
        <td class="right">
            <div class="title"><?= $isRefund ? 'REFUND VOUCHER' : 'RECEIPT VOUCHER' ?></div>
            <table style="width:auto; margin-left:auto">
                <tr><td class="label right">Voucher no.</td><td class="right"><strong><?= esc($r['receipt_number']) ?></strong></td></tr>
                <tr><td class="label right">Date</td><td class="right"><?= dmy($r['receipt_date']) ?></td></tr>
            </table>
        </td>
    </tr>
</table>
<div class="rule"></div>
<table>
    <tr>
        <td style="width:58%" class="box">
            <div class="label"><?= $isRefund ? 'Paid to' : 'Received from' ?></div>
            <strong><?= esc((string) ($contact['legal_name'] ?: $contact['name'])) ?></strong><br>
            <?php if ($contact['gstin']): ?>GSTIN: <?= esc($contact['gstin']) ?><br><?php endif ?>
            <div style="margin-top:4px"><span class="label">The sum of</span><br><?= esc($words) ?></div>
        </td>
        <td style="width:4%"></td>
        <td class="box">
            <div class="label">Amount</div>
            <div class="amount">₹ <?= inr($r['amount'], false) ?></div>
            <div style="margin-top:4px"><span class="label">Mode</span> <?= esc($modes[$r['mode']] ?? $r['mode']) ?><?php if ($r['reference_no']): ?> · Ref <?= esc($r['reference_no']) ?><?php endif ?></div>
            <?php if ($r['mode'] === 'cheque' && $r['cheque_date']): ?><div><span class="label">Cheque date</span> <?= dmy($r['cheque_date']) ?> (subject to realisation)</div><?php endif ?>
            <div><span class="label"><?= $isRefund ? 'Paid from' : 'Received in' ?></span> <?= esc($r['account_name']) ?></div>
        </td>
    </tr>
</table>
<?php if (! $isRefund): ?>
<table class="grid" style="margin-top:8px">
    <thead><tr><th>Against document</th><th>Date</th><th class="right">Amount applied (₹)</th></tr></thead>
    <tbody>
    <?php foreach ($allocs as $a): ?><tr><td><?= esc(doc_type_label($a['doc_type'])) ?> <?= esc($a['document_number']) ?></td><td><?= dmy($a['issue_date']) ?></td><td class="right"><?= inr($a['amount'], false) ?></td></tr><?php endforeach ?>
    <?php if ((float) $r['unapplied_amount'] > 0): ?><tr><td colspan="2">On account / advance (to be adjusted against future invoices)</td><td class="right"><?= inr($r['unapplied_amount'], false) ?></td></tr><?php endif ?>
    <?php if ($allocs === [] && (float) $r['unapplied_amount'] <= 0): ?><tr><td colspan="3" class="muted">—</td></tr><?php endif ?>
    </tbody>
</table>
<?php endif ?>
<?php if ($r['notes']): ?><p class="muted" style="margin-top:6px">Note: <?= esc($r['notes']) ?></p><?php endif ?>
<table style="margin-top:22px">
    <tr><td class="muted" style="font-size:7.5pt">Computer-generated voucher. <?= $isRefund ? '' : 'This is an acknowledgement of payment received; it is not a tax invoice.' ?></td>
        <td class="right" style="width:40%">For <strong><?= esc((string) ($supplier['legal_name'] ?: $supplier['trade_name'])) ?></strong><br><br><br>Authorised Signatory</td></tr>
</table>
</body></html>

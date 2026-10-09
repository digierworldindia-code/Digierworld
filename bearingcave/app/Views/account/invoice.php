<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $i = $invoice; ?>
<?= view('components/page_header', ['title' => 'Invoice ' . $i['invoice_number'], 'breadcrumbs' => ['Invoices' => $area . '/billing', $i['invoice_number'] => null], 'actions' => '<button class="btn btn-light btn-sm" onclick="window.print()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>']) ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="bc-card"><div class="bc-card-body p-4">
            <div class="d-flex justify-content-between mb-4"><div><div class="fw-bold fs-5 text-navy">BearingCave</div><div class="small text-muted"><?= esc(policy('platform.support_email')) ?></div></div><div class="text-end"><?= status_badge($i['status']) ?><div class="small text-muted mt-1"><?= esc(ucfirst($i['invoice_type'])) ?> invoice</div></div></div>
            <dl class="dl-grid small mb-4"><dt>Billed to</dt><dd><?= esc($company['legal_name']) ?><br><?= esc(trim(($company['address_line1'] ?? '') . ', ' . ($company['city'] ?? '') . ', ' . country_name($company['country_code']), ', ')) ?></dd><dt>Issued</dt><dd><?= fdate($i['issued_at']) ?></dd><dt>Due</dt><dd><?= fdate($i['due_at']) ?></dd><?php if ($i['paid_at']): ?><dt>Paid</dt><dd><?= fdate($i['paid_at']) ?></dd><?php endif ?></dl>
            <table class="table table-sm"><thead><tr><th>Description</th><th class="text-end">Amount</th></tr></thead><tbody>
                <tr><td><?= esc($i['notes'] ?: ucfirst($i['invoice_type'])) ?></td><td class="text-end"><?= money($i['subtotal'], $i['currency']) ?></td></tr>
                <tr><td>Tax (<?= esc(rtrim(rtrim((string) $i['tax_rate'], '0'), '.')) ?>%)<?= $i['tax_note'] ? '<div class="small text-warning">' . esc($i['tax_note']) . '</div>' : '' ?></td><td class="text-end"><?= money($i['tax_amount'], $i['currency']) ?></td></tr>
                <tr class="fw-bold"><td>Total</td><td class="text-end"><?= money($i['total'], $i['currency']) ?></td></tr>
            </tbody></table>
            <p class="small text-muted mb-0"><?= esc((string) $refundPolicy) ?></p>
        </div></div>
    </div>
    <div class="col-lg-5">
        <?php if ($i['status'] === 'issued'): ?>
        <div class="bc-card mb-3"><div class="bc-card-header"><h2>Pay this invoice</h2></div><div class="bc-card-body">
            <?php if (! $gateways): ?><p class="small text-muted mb-0">No payment method is configured. Please contact BearingCave finance.</p><?php else: ?>
            <form method="post" action="<?= site_url($area . '/billing/invoices/' . $i['id'] . '/pay') ?>">
                <?= csrf_field() ?><input type="hidden" name="idempotency_key" value="<?= esc('inv-' . $i['id'] . '-' . bin2hex(random_bytes(6)), 'attr') ?>">
                <?php foreach ($gateways as $code => $g): ?><div class="form-check mb-2"><input class="form-check-input" type="radio" name="gateway" value="<?= esc($code, 'attr') ?>" id="g<?= esc($code, 'attr') ?>" required<?= $code === array_key_first($gateways) ? ' checked' : '' ?>><label class="form-check-label" for="g<?= esc($code, 'attr') ?>"><?= esc($g->label()) ?><?= $g->isLive() ? '' : ' <span class="badge-sample">TEST MODE</span>' ?></label></div><?php endforeach ?>
                <button class="btn btn-primary w-100 mt-2" type="submit">Continue</button>
            </form>
            <?php endif ?>
        </div></div>
        <?php endif ?>
        <?php foreach ($payments as $p): ?>
            <div class="bc-card mb-3 <?= $p['is_sandbox'] ? 'notice-sandbox' : '' ?>"><div class="bc-card-body small">
                <div class="d-flex justify-content-between"><strong><?= esc(str_replace('_', ' ', ucfirst($p['provider']))) ?></strong><?= status_badge($p['status']) ?></div>
                <div class="text-muted"><?= fdt($p['created_at']) ?> · <?= money($p['amount'], $p['currency']) ?><?= $p['provider_reference'] ? ' · Ref ' . esc($p['provider_reference']) : '' ?></div>
                <?php if ($p['notes'] && in_array($p['status'], ['pending', 'initiated'], true)): ?><div class="mt-2" style="white-space:pre-line"><?= esc($p['notes']) ?></div><?php endif ?>
                <?php if ($p['is_sandbox'] && in_array($p['status'], ['pending', 'initiated'], true)): ?>
                    <div class="mt-2"><?= post_button($area . '/billing/payments/' . $p['id'] . '/simulate', 'Simulate provider confirmation (sandbox)', 'btn btn-sm btn-warning') ?></div>
                    <div class="mt-1">Sandbox only — no money is moved and nothing is settled.</div>
                <?php endif ?>
            </div></div>
        <?php endforeach ?>
    </div>
</div>
<?= $this->endSection() ?>

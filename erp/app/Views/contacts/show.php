<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('billing.invoice.create_draft') && $c['is_customer']): ?><a href="<?= site_url('billing/documents/new?type=tax_invoice&contact=' . $c['id']) ?>" class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-plus"></i> New invoice</a><?php endif ?>
<?php if (can('finance.receipt.create') && $c['is_customer']): ?><a href="<?= site_url('finance/receipts/new?contact=' . $c['id']) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cash"></i> Record payment</a><?php endif ?>
<?php if (can('contacts.edit')): ?><a href="<?= site_url('contacts/' . $c['id'] . '/edit') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a><?php endif ?>
<?php if (can('contacts.merge')): ?><a href="<?= site_url('contacts/' . $c['id'] . '/merge') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-intersect"></i> Merge</a><?php endif ?>
<?php if (can('contacts.delete') && $c['is_active']): ?><form method="post" action="<?= site_url('contacts/' . $c['id'] . '/archive') ?>" data-confirm="Mark this contact inactive? History is kept." class="d-inline"><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm">Archive</button></form><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if (! $c['is_active']): ?><div class="alert alert-secondary">This contact is inactive.</div><?php endif ?>
<div class="row g-3">
<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body small">
        <div class="mb-2"><span class="mono text-muted"><?= esc($c['contact_code']) ?></span>
            <?= $c['is_customer'] ? '<span class="badge badge-neutral">Customer</span>' : '' ?> <?= $c['is_vendor'] ? '<span class="badge badge-neutral">Vendor</span>' : '' ?> <?= $c['is_prospect'] ? '<span class="badge badge-neutral">Prospect</span>' : '' ?>
            <?= $c['dnd'] ? '<span class="badge badge-neutral">Do not call</span>' : '' ?></div>
        <dl class="row mb-0">
            <?php foreach ([
                'Legal name' => $c['legal_name'], 'Contact' => $c['contact_person'], 'Phone' => $c['phone'], 'Alt phone' => $c['alt_phone'], 'WhatsApp' => $c['whatsapp'], 'Email' => $c['email'],
                'GSTIN' => $c['gstin'], 'GST type' => ucfirst((string) $c['gst_registration_type']), 'PAN' => $c['pan'],
                'State' => $state ? $state['name'] . ' (' . $state['code'] . ')' . ($c['state_source'] === 'gstin' ? ' · from GSTIN' : ($c['state_source'] === 'manual' ? ' · manual' : '')) : null,
                'City' => trim(($c['city'] ?? '') . ' ' . ($c['pincode'] ?? '')), 'Industry' => $c['industry'], 'Source' => $c['source'], 'Category' => $c['category'], 'Tags' => $c['tags'],
                'Credit days' => $c['credit_days'] !== null ? $c['credit_days'] . ' days' : null,
            ] as $label => $val): if ($val === null || $val === '') { continue; } ?>
                <dt class="col-4 text-muted fw-normal"><?= $label ?></dt><dd class="col-8"><?= esc((string) $val) ?></dd>
            <?php endforeach ?>
        </dl>
        <?php if ($c['phone']): ?><div class="mt-2 d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="tel:<?= esc($c['phone']) ?>"><i class="bi bi-telephone"></i> Call</a><a class="btn btn-sm btn-outline-success" href="https://wa.me/91<?= esc((string) normalize_phone($c['whatsapp'] ?: $c['phone'])) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a></div><?php endif ?>
    </div></div>
    <?php foreach ($addresses as $a): ?>
        <div class="card mb-3"><div class="card-body small"><div class="card-section-title"><?= esc(ucfirst($a['address_type'])) ?> address</div>
            <?= esc($a['line1']) ?><?= $a['line2'] ? '<br>' . esc($a['line2']) : '' ?><br><?= esc(trim(($a['city'] ?? '') . ' ' . ($a['pincode'] ?? ''))) ?> <?= $a['state_code'] ? '(' . esc($a['state_code']) . ')' : '' ?></div></div>
    <?php endforeach ?>
    <?php if ($people !== []): ?><div class="card mb-3"><div class="card-body small"><div class="card-section-title">People</div>
        <?php foreach ($people as $p): ?><div class="border-bottom py-1"><strong><?= esc($p['name']) ?></strong><?= $p['is_primary'] ? ' <span class="badge badge-gold">primary</span>' : '' ?> <span class="text-muted"><?= esc((string) $p['designation']) ?></span><br><?= esc(trim(($p['phone'] ?? '') . ' ' . ($p['email'] ?? ''))) ?></div><?php endforeach ?>
    </div></div><?php endif ?>
    <div class="card mb-3"><div class="card-body small"><div class="card-section-title">Attachments</div>
        <?php foreach ($files as $f): ?><div><a href="<?= site_url('files/' . $f['file_id']) ?>"><i class="bi bi-paperclip"></i> <?= esc($f['label'] ?: $f['original_name']) ?></a> <span class="text-muted"><?= number_format($f['size_bytes'] / 1024, 0) ?> KB</span></div><?php endforeach ?>
        <?php if ($files === []): ?><span class="text-muted">None.</span><?php endif ?>
        <?php if (can('contacts.edit')): ?>
        <form method="post" action="<?= site_url('contacts/' . $c['id'] . '/files') ?>" enctype="multipart/form-data" class="mt-2 d-flex gap-1"><?= csrf_field() ?>
            <input type="file" name="file" class="form-control form-control-sm" required aria-label="Attachment"><input name="label" class="form-control form-control-sm" placeholder="Label" aria-label="Label"><button class="btn btn-sm btn-primary">Upload</button></form>
        <?php endif ?>
    </div></div>
    <?php if ($c['notes']): ?><div class="card"><div class="card-body small"><div class="card-section-title">Notes</div><?= nl2br(esc($c['notes'])) ?></div></div><?php endif ?>
</div>
<div class="col-xl-8">
    <?php if ($summary !== null): ?>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Billed</div><div class="kpi-value fs-5"><?= inr($summary['billed']) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Received (net)</div><div class="kpi-value fs-5"><?= inr($summary['received']) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="kpi-card accent"><div class="kpi-label">Outstanding</div><div class="kpi-value fs-5 <?= (float) $summary['due'] > 0 ? 'text-danger' : '' ?>"><?= inr($summary['due']) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Advance held</div><div class="kpi-value fs-5"><?= inr($summary['advance']) ?></div><div class="kpi-sub">Opening: <?= inr($summary['opening']) ?></div></div></div>
    </div>
    <?php endif ?>

    <?php if ($docs !== [] || service('billingAccess')->visibleTypes() !== []): ?>
    <div class="table-card mb-3"><div class="p-3 border-bottom"><strong>Billing documents</strong></div><div class="table-responsive">
        <table class="table table-sm table-hover small mb-0"><thead><tr><th>Document</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($docs as $d): ?><tr>
            <td><a href="<?= site_url('billing/documents/' . $d['id']) ?>"><?= esc(doc_type_label($d['doc_type'])) ?> <?= esc($d['document_number'] ?? $d['draft_ref']) ?></a></td>
            <td><?= dmy($d['issue_date']) ?></td><td><?= dmy($d['due_date']) ?></td><td class="num"><?= inr($d['grand_total']) ?></td><td class="num"><?= $d['status'] === 'finalized' ? inr($d['balance_due']) : '—' ?></td>
            <td><?= status_badge($d['status']) ?> <?= $d['status'] === 'finalized' && $d['payment_status'] !== 'na' ? status_badge($d['payment_status']) : '' ?></td></tr>
        <?php endforeach ?>
        <?php if ($docs === []): ?><tr><td colspan="6" class="text-muted">No documents you can access.</td></tr><?php endif ?>
        </tbody></table></div></div>
    <?php endif ?>

    <?php if (can('finance.receipt.view')): ?>
    <div class="table-card mb-3"><div class="p-3 border-bottom"><strong>Receipts & refunds</strong></div><div class="table-responsive">
        <table class="table table-sm small mb-0"><thead><tr><th>No.</th><th>Date</th><th>Mode</th><th class="num">Amount</th><th class="num">Unapplied</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($receipts as $r): ?><tr><td><a href="<?= site_url('finance/receipts/' . $r['id']) ?>"><?= esc($r['receipt_number']) ?></a><?= $r['receipt_type'] === 'refund' ? ' <span class="badge badge-warning">refund</span>' : '' ?></td><td><?= dmy($r['receipt_date']) ?></td><td><?= esc(strtoupper($r['mode'])) ?></td><td class="num"><?= inr($r['amount']) ?></td><td class="num"><?= inr($r['unapplied_amount']) ?></td><td><?= status_badge($r['status']) ?></td></tr><?php endforeach ?>
        <?php if ($receipts === []): ?><tr><td colspan="6" class="text-muted">No receipts.</td></tr><?php endif ?>
        </tbody></table></div></div>
    <?php endif ?>

    <?php if ($leads !== [] || $visits !== []): ?>
    <div class="table-card mb-3"><div class="p-3 border-bottom"><strong>CRM activity</strong></div>
        <table class="table table-sm small mb-0"><tbody>
        <?php foreach ($leads as $l): ?><tr><td>Lead</td><td><a href="<?= site_url('crm/leads/' . $l['id']) ?>"><?= esc($l['lead_code'] . ' ' . $l['company_name']) ?></a></td><td><?= esc($l['stage']) ?></td><td><?= dmy($l['next_followup_at'], true) ?></td></tr><?php endforeach ?>
        <?php foreach ($visits as $v): ?><tr><td>Visit</td><td><?= esc($v['purpose']) ?> (<?= esc((string) $v['visitor']) ?>)</td><td><?= status_badge($v['status']) ?></td><td><?= dmy($v['scheduled_at'], true) ?></td></tr><?php endforeach ?>
        </tbody></table></div>
    <?php endif ?>

    <?php if ($expenses !== []): ?>
    <div class="table-card mb-3"><div class="p-3 border-bottom"><strong>Purchases / expenses (as vendor)</strong></div>
        <table class="table table-sm small mb-0"><thead><tr><th>No.</th><th>Date</th><th>Description</th><th class="num">Total</th><th>Paid</th></tr></thead><tbody>
        <?php foreach ($expenses as $e): ?><tr><td><a href="<?= site_url('finance/expenses/' . $e['id']) ?>"><?= esc($e['expense_number']) ?></a></td><td><?= dmy($e['expense_date']) ?></td><td><?= esc($e['description']) ?></td><td class="num"><?= inr($e['total_amount']) ?></td><td><?= status_badge($e['payment_status']) ?></td></tr><?php endforeach ?>
        </tbody></table></div>
    <?php endif ?>
</div>
</div>
<?= $this->endSection() ?>

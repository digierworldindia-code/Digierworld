<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $m @var array|null $warranty @var array|null $sale @var list<array> $claims @var list<array> $events */ ?>
<div class="page-head">
    <div>
        <div class="small"><a href="<?= site_url('admin/mattresses') ?>">Mattresses</a></div>
        <h1 class="mono"><?= esc($m['serial_number']) ?> <?= pill($m['current_status']) ?><?= $m['deleted_at'] ? ' ' . pill('VOID', 'Removed') : '' ?></h1>
        <p><?= esc($m['product']) ?> · <?= esc($m['size_label']) ?> · SKU <?= esc($m['sku']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-light" href="<?= site_url('admin/mattresses/' . $m['id'] . '/label') ?>"><i class="bi bi-qr-code"></i> Label</a>
    </div>
</div>
<?php if ($m['deleted_at']): ?><div class="alert alert-danger">Removed on <?= esc(local_time($m['deleted_at'])) ?>: <?= esc($m['delete_reason']) ?></div><?php endif ?>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="panel">
            <div class="panel-head"><h2>Unit</h2></div>
            <div class="panel-body"><dl class="dl-grid">
                <dt>Product</dt><dd><?= esc($m['product']) ?>, <?= esc($m['size_label']) ?> (MRP <?= inr((float) $m['mrp']) ?>)</dd>
                <dt>Batch</dt><dd><a class="mono" href="<?= site_url('admin/batches/' . $m['batch_id']) ?>"><?= esc($m['batch_code']) ?></a> · <?= esc($m['plant']) ?></dd>
                <dt>Manufactured</dt><dd><?= esc(local_time($m['manufactured_at'])) ?></dd>
                <dt>Dispatched</dt><dd><?= esc(local_time($m['dispatched_at'])) ?></dd>
                <dt>Received by dealer</dt><dd><?= esc(local_time($m['received_at'])) ?></dd>
                <dt>Sold</dt><dd><?= esc(local_time($m['sold_at'])) ?></dd>
                <dt>Current dealer</dt><dd><?= $m['dealer'] ? '<a href="' . site_url('admin/dealers/' . $m['dealer_id']) . '">' . esc($m['dealer']) . '</a> · ' . esc($m['dealer_city']) : '—' ?></dd>
                <?php if ($m['grade_note']): ?><dt>Condition note</dt><dd><?= esc($m['grade_note']) ?></dd><?php endif ?>
                <?php if ($replacementFor): ?><dt>Replacement for</dt><dd><a class="mono" href="<?= site_url('admin/mattresses/' . $replacementFor['id']) ?>"><?= esc($replacementFor['serial_number']) ?></a> under claim <a href="<?= site_url('admin/claims/' . $replacementFor['claim_id']) ?>"><?= esc($replacementFor['claim_number']) ?></a></dd><?php endif ?>
                <?php if ($replacedBy): ?><dt>Replaced by</dt><dd><a class="mono" href="<?= site_url('admin/mattresses/' . $replacedBy['id']) ?>"><?= esc($replacedBy['serial_number']) ?></a> on <?= esc(local_date($replacedBy['issued_at'])) ?></dd><?php endif ?>
            </dl></div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Sale and warranty</h2></div>
            <div class="panel-body">
                <?php if ($sale === null): ?><p class="text-muted mb-0">Not sold yet. The warranty starts when the dealer records the sale.</p><?php else: ?>
                <dl class="dl-grid">
                    <dt>Invoice</dt><dd><?= esc($sale['invoice_number']) ?> · <?= esc($sale['dealer']) ?></dd>
                    <dt>Sold on</dt><dd><?= esc(local_date($sale['sold_at'])) ?> for <?= inr((float) $sale['sale_price']) ?> (<?= esc(humanise($sale['payment_mode'])) ?>)</dd>
                    <dt>Customer</dt><dd><?= esc($sale['full_name']) ?><?= $sale['city'] ? ', ' . esc($sale['city']) : '' ?></dd>
                    <?php if ($warranty): ?>
                    <dt>Warranty</dt><dd><?= pill($warranty['status']) ?> <?= esc(local_date($warranty['start_date'])) ?> to <?= esc(local_date($warranty['end_date'])) ?> (<?= (int) $warranty['years'] ?> years)
                        <?php if ($ctx->can('warranty:read')): ?> · <a href="<?= site_url('admin/warranties/' . $warranty['id']) ?>">Open</a><?php endif ?></dd>
                    <?php endif ?>
                </dl>
                <?php endif ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Claims</h2></div>
            <?php if ($claims === []): ?><div class="table-empty">No claims on this unit.</div><?php else: ?>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Claim</th><th>Status</th><th>Risk</th><th>Submitted</th></tr></thead>
                <tbody><?php foreach ($claims as $c): ?>
                    <tr><td><a href="<?= site_url('admin/claims/' . $c['id']) ?>"><?= esc($c['claim_number']) ?></a><div class="small text-muted"><?= esc($c['reported_issue']) ?></div></td>
                    <td><?= pill($c['status']) ?></td><td><?= $c['risk_level'] ? pill($c['risk_level']) : '—' ?></td><td class="small"><?= esc(local_date($c['submitted_at'])) ?></td></tr>
                <?php endforeach ?></tbody>
            </table></div>
            <?php endif ?>
        </div>

        <?php if ($ctx->can('mattress:delete') && ! $m['deleted_at'] && ! in_array($m['current_status'], ['SOLD', 'CLAIM_OPEN'], true)): ?>
        <div class="panel">
            <div class="panel-head"><h2>Remove this record</h2></div>
            <form class="panel-body" method="post" action="<?= site_url('admin/mattresses/' . $m['id'] . '/delete') ?>" data-confirm="Remove <?= esc($m['serial_number'], 'attr') ?>? The serial is never reissued.">
                <?= csrf_field() ?>
                <p class="small text-muted">For a unit that was serialised in error or destroyed before sale. The record is hidden, not erased, and the serial is never reissued.</p>
                <div class="d-flex gap-2"><input class="form-control" name="reason" required minlength="5" maxlength="500" placeholder="Reason"><button class="btn btn-danger" type="submit">Remove</button></div>
            </form>
        </div>
        <?php endif ?>
    </div>

    <div class="col-xl-5">
        <div class="panel">
            <div class="panel-head"><h2>Lifecycle</h2></div>
            <div class="panel-body">
                <ol class="timeline">
                <?php foreach ($events as $e): ?>
                    <li>
                        <div class="fw-semibold"><?= esc(humanise($e['event_type'])) ?><?= $e['to_status'] ? ' → ' . pill($e['to_status']) : '' ?></div>
                        <div class="when"><?= esc(local_time($e['occurred_at'])) ?> · <?= esc($e['actor'] ?? 'System') ?><?= $e['dealer'] ? ' · ' . esc($e['dealer']) : '' ?></div>
                        <?php $payload = json_decode((string) $e['payload'], true); ?>
                        <?php if (is_array($payload) && $payload !== []): ?><div class="small text-muted"><?= esc(implode(' · ', array_map(static fn ($k, $v) => humanise(strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $k))) . ': ' . (is_scalar($v) ? $v : json_encode($v)), array_keys($payload), $payload))) ?></div><?php endif ?>
                    </li>
                <?php endforeach ?>
                </ol>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

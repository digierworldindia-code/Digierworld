<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array $w @var list<array> $claims @var list<array> $history */ ?>
<div class="page-head">
    <div><div class="small"><a href="<?= site_url('admin/warranties') ?>">Warranties</a></div>
    <h1 class="mono"><?= esc($w['serial_number']) ?> <?= pill($w['status']) ?></h1>
    <p><?= esc($w['product']) ?>, <?= esc($w['size_label']) ?> · <?= (int) $w['years'] ?>-year term</p></div>
</div>
<div class="row g-3">
    <div class="col-xl-7">
        <div class="panel"><div class="panel-head"><h2>Cover</h2></div><div class="panel-body"><dl class="dl-grid">
            <dt>Mattress</dt><dd><a class="mono" href="<?= site_url('admin/mattresses/' . $w['mattress_id']) ?>"><?= esc($w['serial_number']) ?></a></dd>
            <dt>Runs</dt><dd><?= esc(local_date($w['start_date'])) ?> to <?= esc(local_date($w['end_date'])) ?></dd>
            <dt>Terms version</dt><dd><?= esc($w['terms_version'] ?? '—') ?></dd>
            <dt>Dealer</dt><dd><a href="<?= site_url('admin/dealers/' . $w['dealer_id']) ?>"><?= esc($w['dealer']) ?></a></dd>
            <dt>Customer</dt><dd><?= esc($w['customer'] ?? '—') ?><?= $w['customer_city'] ? ', ' . esc($w['customer_city']) : '' ?></dd>
            <dt>Sale</dt><dd><?= esc($w['invoice_number'] ?? '—') ?><?= $w['sold_at'] ? ' on ' . esc(local_date($w['sold_at'])) : '' ?><?= $w['sale_price'] ? ' · ' . inr((float) $w['sale_price']) : '' ?></dd>
            <?php if ($w['void_reason']): ?><dt>Void reason</dt><dd><?= esc($w['void_reason']) ?></dd><?php endif ?>
        </dl></div></div>
        <?php if ($claims !== []): ?>
        <div class="panel"><div class="panel-head"><h2>Claims</h2></div><table class="table"><tbody>
            <?php foreach ($claims as $c): ?><tr><td><a href="<?= site_url('admin/claims/' . $c['id']) ?>"><?= esc($c['claim_number']) ?></a></td><td><?= pill($c['status']) ?></td><td class="small"><?= esc(local_date($c['submitted_at'])) ?></td></tr><?php endforeach ?>
        </tbody></table></div>
        <?php endif ?>
    </div>
    <div class="col-xl-5">
        <?php if ($ctx->can('warranty:void')): ?>
        <?php if ($w['status'] === 'ACTIVE'): ?>
        <form class="panel" method="post" action="<?= site_url('admin/warranties/' . $w['id'] . '/void') ?>" data-confirm="Void this warranty? The public page will say the cover is no longer valid.">
            <div class="panel-head"><h2>Void</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <p class="small text-muted">For a sale reversed, a unit returned, or cover withdrawn for cause. The record stays; the status changes.</p>
                <div class="d-flex gap-2"><input class="form-control" name="reason" required minlength="5" maxlength="500" placeholder="Reason"><button class="btn btn-danger" type="submit">Void</button></div>
            </div>
        </form>
        <?php endif ?>
        <form class="panel" method="post" action="<?= site_url('admin/warranties/' . $w['id'] . '/override') ?>" data-confirm="Change the warranty dates?">
            <div class="panel-head"><h2>Correct the dates</h2></div>
            <div class="panel-body"><?= csrf_field() ?>
                <p class="small text-muted">For a sale entered with the wrong date. The previous values are kept in the record history and the audit trail.</p>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label" for="start_date">Starts</label><input class="form-control" type="date" id="start_date" name="start_date" required value="<?= esc($w['start_date'], 'attr') ?>"></div>
                    <div class="col-6"><label class="form-label" for="end_date">Ends</label><input class="form-control" type="date" id="end_date" name="end_date" required value="<?= esc($w['end_date'], 'attr') ?>"></div>
                    <div class="col-12"><label class="form-label" for="reason-dates">Reason</label><input class="form-control" id="reason-dates" name="reason" required minlength="5" maxlength="500"></div>
                </div>
                <button class="btn btn-light mt-3" type="submit">Save dates</button>
            </div>
        </form>
        <?php endif ?>
        <?php if ($history !== []): ?>
        <div class="panel"><div class="panel-head"><h2>Record history</h2></div><div class="panel-body">
            <?php foreach ($history as $h): ?><p class="small mb-1">v<?= (int) $h['version'] ?> · <?= esc(local_time($h['changed_at'])) ?> — <?= esc($h['reason'] ?? '') ?></p><?php endforeach ?>
        </div></div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

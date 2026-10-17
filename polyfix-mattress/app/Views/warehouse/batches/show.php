<?= $this->extend('layouts/floor') ?>

<?php
/**
 * One batch: how far it has been counted, the mix it holds, and the two
 * things the operator came here to do — scan, or print the labels.
 *
 * @var array<string,mixed>       $b
 * @var array<string,mixed>       $progress
 * @var list<array<string,mixed>> $mix
 */
$remaining = (int) $progress['remaining'];
$complete  = (int) $progress['produced'] > 0 && $remaining === 0;
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1 class="mono"><?= esc($b['batch_code']) ?></h1>
    <p><?= esc(local_date($b['manufactured_on'])) ?> · <?= esc($b['warehouse']) ?></p>
</div>

<div class="panel mb-3">
    <div class="scanner__counter"><?= esc($progress['counter']['label']) ?></div>
    <div class="p-3">
        <?php if ($complete): ?>
            <p class="mb-0 text-success"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                Every mattress in this batch has been counted.</p>
        <?php else: ?>
            <p class="mb-0"><strong><?= $remaining ?></strong> still to scan.</p>
        <?php endif ?>
    </div>
</div>

<div class="floor-menu mb-3">
    <?php if (! $complete): ?>
        <a class="floor-action" href="<?= site_url('warehouse/batches/' . $b['id'] . '/scan') ?>">
            <i class="bi bi-qr-code-scan" aria-hidden="true"></i>
            <span><strong>Scan mattresses</strong><span>Camera stays open</span></span>
        </a>
    <?php endif ?>
    <a class="floor-action floor-action--muted" href="<?= site_url('warehouse/batches/' . $b['id'] . '/labels') ?>">
        <i class="bi bi-printer" aria-hidden="true"></i>
        <span><strong>Print labels</strong><span><?= (int) $progress['produced'] ?> QR labels</span></span>
    </a>
</div>

<h2 class="h2 mb-2">What is in it</h2>
<div class="panel mb-3">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Model</th><th>Size</th><th class="text-end">Counted</th></tr></thead>
            <tbody>
            <?php foreach ($mix as $row): ?>
                <tr>
                    <td><?= esc($row['product']) ?></td>
                    <td class="small"><?= esc($row['size_label']) ?></td>
                    <td class="text-end text-nowrap"><?= (int) $row['scanned'] ?> / <?= (int) $row['n'] ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (! $complete && $ctx->can('batch:write')): ?>
    <!-- A run that finished short needs an explanation attached, or it looks
         like unfinished work for ever. No status is set: the figures are the
         record, and this adds the reason beside them. -->
    <details class="panel p-3">
        <summary class="fw-semibold">Finish this batch short</summary>
        <form class="mt-3" method="post" action="<?= site_url('warehouse/batches/' . $b['id'] . '/close') ?>"
              data-confirm="Close this batch with <?= $remaining ?> mattresses still unscanned?">
            <?= csrf_field() ?>
            <label class="form-label" for="reason">Why is it short?</label>
            <textarea class="form-control mb-2" id="reason" name="reason" rows="2" maxlength="500" required
                      placeholder="e.g. four units failed inspection and were scrapped on the line"></textarea>
            <button class="btn btn-light btn-floor w-100" type="submit">Close at <?= (int) $progress['scanned'] ?> of <?= (int) $progress['produced'] ?></button>
        </form>
    </details>
<?php endif ?>

<?= $this->endSection() ?>

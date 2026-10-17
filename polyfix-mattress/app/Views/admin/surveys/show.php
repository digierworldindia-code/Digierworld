<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/**
 * One survey, in full, for the office.
 *
 * The figures are the ones frozen when the agent submitted, so this reads
 * the same next year as it does today.
 *
 * @var array<string,mixed>       $s
 * @var list<array<string,mixed>> $items
 * @var array<string,int>         $counts
 */
$tone = [
    'MATCHED'                  => 'success',
    'POSSIBLE_UNRECORDED_SALE' => 'warning',
    'REVIEW_REQUIRED'          => 'warning',
    'STOCK_DISCREPANCY'        => 'danger',
    'DAMAGED'                  => 'danger',
];
$observations = [
    'PRESENT'          => 'Physically available',
    'DEALER_SAYS_SOLD' => 'Dealer says sold',
    'NOT_FOUND'        => 'Not found',
    'DAMAGED'          => 'Damaged',
    'OTHER'            => 'Other',
];
?>
<div class="page-head">
    <div>
        <div class="small"><a href="<?= site_url('admin/surveys') ?>">Surveys</a></div>
        <h1 class="mono"><?= esc($s['reference']) ?></h1>
        <p><?= esc($s['dealer']) ?> · visited by <?= esc($s['agent']) ?> on <?= esc(local_date($s['started_at'])) ?></p>
    </div>
    <div class="d-flex gap-2">
        <?= pill($s['status']) ?>
        <?php if ($s['status'] === 'SUBMITTED' && $ctx->can('survey:assign')): ?>
            <form method="post" action="<?= site_url('admin/surveys/' . $s['id'] . '/reviewed') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-primary btn-sm" type="submit">Mark reviewed</button>
            </form>
        <?php endif ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="stat"><div class="stat__label">On record</div><div class="stat__value"><?= (int) $counts['system_total'] ?></div><div class="stat__note">at the time of the visit</div></div></div>
    <div class="col-6 col-lg-3"><div class="stat"><div class="stat__label">Checked</div><div class="stat__value"><?= (int) $counts['examined'] ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat"><div class="stat__label">Found</div><div class="stat__value"><?= (int) $counts['physically_found'] ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat"><div class="stat__label">To look into</div><div class="stat__value text-danger"><?= (int) $counts['discrepancies'] ?></div></div></div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="panel">
            <div class="panel-head"><h2>Unit by unit</h2></div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Serial</th><th>Model</th><th>Records said</th><th>Agent found</th><th>Outcome</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><a class="mono" href="<?= site_url('admin/mattresses/' . $item['mattress_id']) ?>"><?= esc($item['serial_number']) ?></a></td>
                        <td class="small"><?= esc($item['product_name']) ?><span class="d-block text-muted"><?= esc($item['size_label']) ?></span></td>
                        <td class="small"><?= esc(humanise($item['system_status'])) ?></td>
                        <td class="small"><?= esc($observations[$item['observation']] ?? $item['observation']) ?>
                            <?php if ($item['remark'] !== null): ?><span class="d-block text-muted">“<?= esc($item['remark']) ?>”</span><?php endif ?>
                            <?php if ((int) $item['scanned'] === 0): ?><span class="d-block text-muted">entered by hand</span><?php endif ?></td>
                        <td>
                            <span class="badge text-bg-<?= $tone[$item['outcome']] ?? 'secondary' ?>"><?= esc(humanise($item['outcome'])) ?></span>
                            <?php if ($item['discrepancy_id'] !== null): ?>
                                <a class="d-block small" href="<?= site_url('admin/discrepancies') ?>"><?= esc(humanise($item['discrepancy_status'])) ?></a>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($items === []): ?><tr><td colspan="5" class="table-empty">Nothing was recorded on this visit.</td></tr><?php endif ?>
                </tbody>
            </table></div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="panel mb-3">
            <div class="panel-head"><h2>The visit</h2></div>
            <div class="panel-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Dealer</dt><dd class="col-7"><a href="<?= site_url('admin/dealers/' . $s['dealer_id']) ?>"><?= esc($s['dealer']) ?></a></dd>
                    <dt class="col-5">Agent</dt><dd class="col-7"><?= esc($s['agent']) ?></dd>
                    <dt class="col-5">Started</dt><dd class="col-7"><?= esc(local_time($s['started_at'])) ?></dd>
                    <dt class="col-5">Submitted</dt><dd class="col-7"><?= $s['submitted_at'] !== null ? esc(local_time($s['submitted_at'])) : '—' ?></dd>
                    <?php if ($s['reviewer'] !== null): ?>
                        <dt class="col-5">Reviewed</dt><dd class="col-7"><?= esc($s['reviewer']) ?>, <?= esc(local_date($s['reviewed_at'])) ?></dd>
                    <?php endif ?>
                    <dt class="col-5">Recorded as sold</dt><dd class="col-7"><?= (int) $counts['system_sold'] ?></dd>
                    <dt class="col-5">Dealer says sold</dt><dd class="col-7"><?= (int) $counts['reported_sold'] ?></dd>
                    <dt class="col-5">Not found</dt><dd class="col-7"><?= (int) $counts['not_found'] ?></dd>
                    <dt class="col-5">Damaged</dt><dd class="col-7"><?= (int) $counts['damaged'] ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($s['agent_remarks'] !== null && $s['agent_remarks'] !== ''): ?>
            <div class="panel">
                <div class="panel-head"><h2>Agent's remarks</h2></div>
                <div class="panel-body"><p class="mb-0 small" style="white-space:pre-line"><?= esc($s['agent_remarks']) ?></p></div>
            </div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

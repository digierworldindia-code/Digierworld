<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/**
 * Stock discrepancies raised by surveys, and settling them.
 *
 * Settling one records the conclusion. It does not create a sale, void a
 * warranty or move a mattress — those corrections are made on the screens
 * that own them, so every change still goes through the service that
 * validates and audits it. The note here says what was done and why.
 *
 * @var list<array<string,mixed>> $rows
 * @var string|null               $status
 * @var array<string,int>         $counts
 */
$tone = ['OPEN' => 'warning', 'UNDER_REVIEW' => 'info', 'RESOLVED' => 'success', 'DISMISSED' => 'secondary'];
?>
<div class="page-head">
    <div>
        <h1>Stock discrepancies</h1>
        <p>Where a survey found something the records did not expect. Nothing here changes a sale or a
            warranty by itself — record the conclusion, then make any correction on its own screen.</p>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach (['OPEN' => 'Open', 'UNDER_REVIEW' => 'Under review', 'RESOLVED' => 'Resolved', 'DISMISSED' => 'Dismissed'] as $key => $label): ?>
        <div class="col-6 col-lg-3">
            <a class="stat d-block text-decoration-none" href="<?= site_url('admin/discrepancies?status=' . $key) ?>">
                <div class="stat__label"><?= esc($label) ?></div>
                <div class="stat__value"><?= (int) ($counts[$key] ?? 0) ?></div>
            </a>
        </div>
    <?php endforeach ?>
</div>

<?php if ($status !== null): ?>
    <p class="small"><a href="<?= site_url('admin/discrepancies') ?>">Show every discrepancy</a></p>
<?php endif ?>

<div class="panel">
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Mattress</th><th>Dealer</th><th>Disagreement</th><th>Raised</th><th>Status</th>
            <?php if ($ctx->can('discrepancy:resolve')): ?><th>Settle</th><?php endif ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td>
                    <a class="mono" href="<?= site_url('admin/mattresses/' . $row['mattress_id']) ?>"><?= esc($row['serial_number']) ?></a>
                    <span class="d-block small text-muted"><?= esc($row['product_name']) ?> · <?= esc($row['size_label']) ?></span>
                </td>
                <td class="small"><?= esc($row['dealer']) ?>
                    <?php if ($row['survey_reference'] !== null): ?>
                        <a class="d-block mono" href="<?= site_url('admin/surveys') ?>"><?= esc($row['survey_reference']) ?></a>
                    <?php endif ?>
                </td>
                <td class="small">
                    <strong><?= esc(humanise($row['kind'])) ?></strong>
                    <span class="d-block text-muted">
                        records said <?= esc(strtolower(humanise($row['system_status']))) ?>,
                        agent found <?= esc(strtolower(humanise($row['observation']))) ?>
                    </span>
                    <?php if ($row['agent_remark'] !== null): ?>
                        <span class="d-block">“<?= esc($row['agent_remark']) ?>”</span>
                    <?php endif ?>
                    <?php if ($row['resolution_note'] !== null): ?>
                        <span class="d-block text-success">Office: <?= esc($row['resolution_note']) ?></span>
                    <?php endif ?>
                </td>
                <td class="small"><?= esc(local_date($row['raised_at'])) ?>
                    <span class="d-block text-muted"><?= esc($row['raised_by_name'] ?? '') ?></span></td>
                <td><span class="badge text-bg-<?= $tone[$row['status']] ?? 'secondary' ?>"><?= esc(humanise($row['status'])) ?></span></td>
                <?php if ($ctx->can('discrepancy:resolve')): ?>
                    <td style="min-width:16rem">
                        <?php if (in_array($row['status'], ['RESOLVED', 'DISMISSED'], true)): ?>
                            <span class="small text-muted">Settled <?= esc(local_date($row['resolved_at'])) ?></span>
                        <?php else: ?>
                            <form method="post" action="<?= site_url('admin/discrepancies/' . $row['id'] . '/resolve') ?>">
                                <?= csrf_field() ?>
                                <label class="visually-hidden" for="note-<?= esc($row['id'], 'attr') ?>">What was concluded?</label>
                                <textarea class="form-control form-control-sm mb-1" id="note-<?= esc($row['id'], 'attr') ?>"
                                          name="note" rows="2" maxlength="1000"
                                          placeholder="What was concluded, and what was corrected where"></textarea>
                                <div class="d-flex gap-1">
                                    <label class="visually-hidden" for="outcome-<?= esc($row['id'], 'attr') ?>">Outcome</label>
                                    <select class="form-select form-select-sm" id="outcome-<?= esc($row['id'], 'attr') ?>" name="outcome">
                                        <option value="UNDER_REVIEW">Looking into it</option>
                                        <option value="RESOLVED">Resolved</option>
                                        <option value="DISMISSED">Dismissed</option>
                                    </select>
                                    <button class="btn btn-sm btn-primary" type="submit">Save</button>
                                </div>
                            </form>
                        <?php endif ?>
                    </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="<?= $ctx->can('discrepancy:resolve') ? 6 : 5 ?>" class="table-empty">Nothing to look into.</td></tr>
        <?php endif ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>

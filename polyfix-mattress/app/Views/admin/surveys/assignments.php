<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/**
 * Who covers which dealers, and how often.
 *
 * The interval is per assignment rather than global. Six months is the
 * default the business asked for, and a dealer who needs watching more
 * closely can be set shorter without changing anybody else's round.
 *
 * @var list<array<string,mixed>> $rows
 * @var list<array<string,mixed>> $agents
 * @var list<array<string,mixed>> $dealers
 * @var int                       $defaultInterval
 */
$today = local_time(utc_now(), 'Y-m-d');
?>
<div class="page-head">
    <div>
        <h1>Agent assignments</h1>
        <p>Which dealers each field agent visits, and how often. Surveys are scheduled from the last visit.</p>
    </div>
</div>

<div class="row g-3">
    <div class="<?= $ctx->can('survey:assign') ? 'col-xl-8' : 'col-12' ?>">
        <div class="panel">
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Agent</th><th>Dealer</th><th class="num">Every</th><th>Last visit</th><th>Next due</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $overdue = (int) $row['active'] === 1
                        && ($row['next_due_on'] === null || $row['next_due_on'] <= $today);
                    ?>
                    <tr class="<?= (int) $row['active'] === 0 ? 'opacity-50' : '' ?>">
                        <td><?= esc($row['agent']) ?><span class="d-block small text-muted"><?= esc($row['agent_email']) ?></span></td>
                        <td><?= esc($row['dealer']) ?><span class="d-block small text-muted mono"><?= esc($row['dealer_code']) ?></span></td>
                        <td class="num"><?= (int) $row['survey_interval_days'] ?> days</td>
                        <td class="small"><?= $row['last_surveyed_on'] !== null ? esc(local_date($row['last_surveyed_on'])) : 'never' ?></td>
                        <td class="small">
                            <?php if ((int) $row['active'] === 0): ?>
                                <span class="text-muted">ended</span>
                            <?php elseif ($row['next_due_on'] === null): ?>
                                <span class="text-warning">first visit owed</span>
                            <?php else: ?>
                                <span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= esc(local_date($row['next_due_on'])) ?></span>
                            <?php endif ?>
                        </td>
                        <td class="text-end">
                            <?php if ($ctx->can('survey:assign') && (int) $row['active'] === 1): ?>
                                <form method="post" action="<?= site_url('admin/assignments/' . $row['id'] . '/end') ?>"
                                      data-confirm="End this assignment? The surveys already done are kept.">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-light" type="submit">End</button>
                                </form>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="table-empty">Nobody is assigned yet.</td></tr>
                <?php endif ?>
                </tbody>
            </table></div>
        </div>
    </div>

    <?php if ($ctx->can('survey:assign')): ?>
        <div class="col-xl-4">
            <form class="panel" method="post" action="<?= site_url('admin/assignments') ?>">
                <div class="panel-head"><h2>Assign a dealer</h2></div>
                <div class="panel-body">
                    <?= csrf_field() ?>

                    <?php if ($agents === []): ?>
                        <p class="small mb-0">No field agents yet. Create a user and give them the
                            <strong>Field Agent</strong> role first.</p>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label" for="agent_user_id">Agent</label>
                            <select class="form-select<?= invalid('agent_user_id') ?>" id="agent_user_id" name="agent_user_id" required>
                                <?php foreach ($agents as $a): ?>
                                    <option value="<?= esc($a['id'], 'attr') ?>"<?= old('agent_user_id') === $a['id'] ? ' selected' : '' ?>>
                                        <?= esc($a['full_name']) ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="dealer_id">Dealer</label>
                            <select class="form-select<?= invalid('dealer_id') ?>" id="dealer_id" name="dealer_id" required>
                                <?php foreach ($dealers as $d): ?>
                                    <option value="<?= esc($d['id'], 'attr') ?>"<?= old('dealer_id') === $d['id'] ? ' selected' : '' ?>>
                                        <?= esc($d['business_name']) ?><?= $d['city'] !== null ? ' — ' . esc($d['city']) : '' ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="interval_days">Visit every</label>
                            <div class="input-group">
                                <input class="form-control<?= invalid('interval_days') ?>" type="number" id="interval_days"
                                       name="interval_days" min="7" max="1095" step="1"
                                       value="<?= esc(old('interval_days', (string) $defaultInterval), 'attr') ?>" required>
                                <span class="input-group-text">days</span>
                            </div>
                            <div class="form-text"><?= $defaultInterval ?> days is six months, the standard round.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="notes">Note <span class="text-muted">(optional)</span></label>
                            <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="500"><?= esc(old('notes')) ?></textarea>
                        </div>

                        <button class="btn btn-primary w-100" type="submit">Save assignment</button>
                        <p class="form-text mb-0">Assigning a dealer again updates the existing round rather than
                            adding a second one.</p>
                    <?php endif ?>
                </div>
            </form>
        </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

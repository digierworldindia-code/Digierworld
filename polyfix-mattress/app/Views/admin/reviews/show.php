<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/**
 * One warranty review, and every decision available on it.
 *
 * The evidence sits beside the buttons on purpose: whether a sale exists,
 * whether an invoice was uploaded, what the agent actually saw. Upholding
 * ends a customer's cover, so the screen makes the person pressing it read
 * what they are deciding from.
 *
 * @var array<string,mixed>       $c
 * @var list<array<string,mixed>> $events
 * @var array<string,string>      $reasons
 * @var string|null               $invoice
 */
$tone = [
    'RECOMMENDED'           => 'warning',
    'UNDER_REVIEW'          => 'info',
    'INFORMATION_REQUESTED' => 'info',
    'UPHELD'                => 'danger',
    'DISMISSED'             => 'success',
    'WITHDRAWN'             => 'secondary',
];
$open    = in_array($c['status'], ['RECOMMENDED', 'UNDER_REVIEW', 'INFORMATION_REQUESTED'], true);
$decides = $ctx->can('warranty_review:decide');
?>
<div class="page-head">
    <div>
        <div class="small"><a href="<?= site_url('admin/warranty-reviews') ?>">Warranty reviews</a></div>
        <h1 class="mono"><?= esc($c['reference']) ?></h1>
        <p>Raised by <?= esc($c['raised_by_name'] ?? 'an agent') ?> on <?= esc(local_date($c['raised_at'])) ?></p>
    </div>
    <span class="badge text-bg-<?= $tone[$c['status']] ?? 'secondary' ?> align-self-start"><?= esc(humanise($c['status'])) ?></span>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="panel mb-3">
            <div class="panel-head"><h2>What is being questioned</h2></div>
            <div class="panel-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Mattress</dt>
                    <dd class="col-sm-8"><a class="mono" href="<?= site_url('admin/mattresses/' . $c['mattress_id']) ?>"><?= esc($c['serial_number']) ?></a>
                        <span class="d-block small text-muted"><?= esc($c['product_name']) ?> · <?= esc($c['size_label']) ?>
                            · <?= esc(humanise($c['mattress_status'])) ?></span></dd>

                    <dt class="col-sm-4">Dealer</dt>
                    <dd class="col-sm-8"><a href="<?= site_url('admin/dealers/' . $c['dealer_id']) ?>"><?= esc($c['dealer']) ?></a>
                        <span class="d-block small text-muted"><?= esc($c['dealer_city'] ?? '') ?> · <?= esc($c['dealer_phone'] ?? '') ?></span></dd>

                    <dt class="col-sm-4">Reason given</dt>
                    <dd class="col-sm-8"><?= esc($reasons[$c['reason']] ?? $c['reason']) ?></dd>

                    <?php if ($c['agent_remark'] !== null): ?>
                        <dt class="col-sm-4">Agent's remark</dt>
                        <dd class="col-sm-8">“<?= esc($c['agent_remark']) ?>”</dd>
                    <?php endif ?>

                    <dt class="col-sm-4">Sale on record</dt>
                    <dd class="col-sm-8"><?= $c['invoice_number'] !== null
                        ? '<span class="mono">' . esc($c['invoice_number']) . '</span> · ' . esc(local_date($c['sale_date']))
                        : '<span class="text-danger">none</span>' ?></dd>

                    <dt class="col-sm-4">Warranty</dt>
                    <dd class="col-sm-8"><?= $c['warranty_status'] !== null
                        ? esc(humanise($c['warranty_status'])) . ', ' . esc(local_date($c['warranty_start_date']))
                          . ' to ' . esc(local_date($c['warranty_end_date']))
                        : 'none on record' ?></dd>

                    <dt class="col-sm-4">Invoice uploaded</dt>
                    <dd class="col-sm-8"><?= $invoice !== null
                        ? '<a href="' . site_url('media/' . $invoice) . '">View the file the dealer uploaded</a>'
                        : '<span class="text-muted">nothing uploaded</span>' ?></dd>

                    <?php if ($c['survey_reference'] !== null): ?>
                        <dt class="col-sm-4">From survey</dt>
                        <dd class="col-sm-8"><a class="mono" href="<?= site_url('admin/surveys') ?>"><?= esc($c['survey_reference']) ?></a></dd>
                    <?php endif ?>
                </dl>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>History</h2></div>
            <ul class="list-unstyled mb-0">
                <?php foreach ($events as $event): ?>
                    <li class="p-3 border-bottom">
                        <div class="d-flex justify-content-between gap-2">
                            <strong><?= esc(humanise($event['event'])) ?></strong>
                            <span class="small text-muted text-nowrap"><?= esc(local_time($event['occurred_at'])) ?></span>
                        </div>
                        <?php if ($event['note'] !== null): ?>
                            <div class="small mt-1"><?= nl2br(esc($event['note'])) ?></div>
                        <?php endif ?>
                        <div class="small text-muted">
                            <?= esc($event['actor'] ?? 'system') ?>
                            <?= (int) $event['dealer_visible'] === 0 ? ' · internal' : '' ?>
                        </div>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>

    <div class="col-xl-5">
        <?php if ($c['decided_at'] !== null): ?>
            <div class="alert alert-<?= $c['status'] === 'UPHELD' ? 'danger' : 'success' ?>">
                <strong><?= $c['status'] === 'UPHELD' ? 'Warranty rejected' : 'Recommendation dismissed' ?></strong>
                <span class="d-block small"><?= esc($c['decision_reason']) ?></span>
                <span class="d-block small text-muted"><?= esc($c['decided_by_name'] ?? '') ?> · <?= esc(local_time($c['decided_at'])) ?></span>
            </div>
        <?php endif ?>

        <?php if (! $decides): ?>
            <div class="panel panel-body small text-muted">
                You can read this case. Deciding it needs the warranty review permission.
            </div>
        <?php elseif ($open): ?>
            <?php if ($c['status'] === 'RECOMMENDED'): ?>
                <form class="mb-3" method="post" action="<?= site_url('admin/warranty-reviews/' . $c['id'] . '/take') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-light w-100" type="submit">Pick this up</button>
                </form>
            <?php endif ?>

            <div class="panel mb-3">
                <div class="panel-head"><h2>Ask the dealer</h2></div>
                <form class="panel-body" method="post" action="<?= site_url('admin/warranty-reviews/' . $c['id'] . '/request-information') ?>">
                    <?= csrf_field() ?>
                    <label class="form-label" for="message">What do they need to send?</label>
                    <textarea class="form-control mb-2" id="message" name="message" rows="3" maxlength="1000" required
                              placeholder="e.g. please upload the customer invoice for this serial"></textarea>
                    <button class="btn btn-light w-100" type="submit">Request it</button>
                </form>
            </div>

            <div class="panel mb-3 border-danger">
                <div class="panel-head"><h2>Reject the warranty</h2></div>
                <form class="panel-body" method="post"
                      action="<?= site_url('admin/warranty-reviews/' . $c['id'] . '/uphold') ?>"
                      data-confirm="Reject this customer's warranty? They will be told the reason you give here.">
                    <?= csrf_field() ?>
                    <label class="form-label" for="uphold-reason">Reason, in words the customer could be shown</label>
                    <textarea class="form-control mb-2" id="uphold-reason" name="reason" rows="3" maxlength="1000" required></textarea>
                    <button class="btn btn-danger w-100" type="submit">Uphold and void the warranty</button>
                    <p class="form-text mb-0">This ends the cover. It can be put back on this screen afterwards.</p>
                </form>
            </div>

            <div class="panel mb-3">
                <div class="panel-head"><h2>Dismiss the recommendation</h2></div>
                <form class="panel-body" method="post" action="<?= site_url('admin/warranty-reviews/' . $c['id'] . '/dismiss') ?>">
                    <?= csrf_field() ?>
                    <label class="form-label" for="dismiss-reason">Why is it not a problem?</label>
                    <textarea class="form-control mb-2" id="dismiss-reason" name="reason" rows="3" maxlength="1000" required></textarea>
                    <button class="btn btn-primary w-100" type="submit">Dismiss — warranty stands</button>
                </form>
            </div>
        <?php elseif ($c['status'] === 'UPHELD'): ?>
            <div class="panel mb-3">
                <div class="panel-head"><h2>Restore the warranty</h2></div>
                <form class="panel-body" method="post" action="<?= site_url('admin/warranty-reviews/' . $c['id'] . '/restore') ?>"
                      data-confirm="Restore this warranty?">
                    <?= csrf_field() ?>
                    <label class="form-label" for="restore-reason">Why is it being put back?</label>
                    <textarea class="form-control mb-2" id="restore-reason" name="reason" rows="3" maxlength="1000" required></textarea>
                    <button class="btn btn-primary w-100" type="submit">Restore eligibility</button>
                    <p class="form-text mb-0">It comes back as it was — expired if its term has since run out.</p>
                </form>
            </div>
        <?php endif ?>

        <?php if ($decides): ?>
            <div class="panel">
                <div class="panel-head"><h2>Add a note</h2></div>
                <form class="panel-body" method="post" action="<?= site_url('admin/warranty-reviews/' . $c['id'] . '/comment') ?>">
                    <?= csrf_field() ?>
                    <label class="form-label" for="note">Note</label>
                    <textarea class="form-control mb-2" id="note" name="note" rows="3" maxlength="2000" required></textarea>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="dealer_visible" name="dealer_visible" value="1">
                        <label class="form-check-label" for="dealer_visible">The dealer can see this note</label>
                    </div>
                    <button class="btn btn-light w-100" type="submit">Add</button>
                </form>
            </div>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

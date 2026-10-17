<?= $this->extend('layouts/floor') ?>

<?php
/**
 * Recommending that a warranty be reviewed.
 *
 * The wording on this screen is deliberate. The agent is not rejecting
 * anything, and the form says so twice — once above the reasons and once on
 * the button — because somebody tapping through a form on a phone should
 * not be able to think they have cancelled a customer's cover.
 *
 * @var string                   $code
 * @var array<string,mixed>|null $unit
 * @var array<string,mixed>|null $dealer
 * @var string|null              $survey
 * @var array<string,string>     $reasons
 */
?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1>Report a warranty problem</h1>
    <p>This asks the office to look. It does not reject anything.</p>
</div>

<?php if ($unit === null): ?>
    <form class="panel p-3" method="get" action="<?= site_url('agent/reviews/new') ?>">
        <label class="form-label fw-semibold" for="code">Which mattress?</label>
        <div class="input-group input-group-lg">
            <input class="form-control mono" type="search" id="code" name="code" value="<?= esc($code, 'attr') ?>"
                   maxlength="300" autocomplete="off" autocapitalize="characters" spellcheck="false"
                   placeholder="<?= esc(brand('serialPrefix'), 'attr') ?>-<?= esc(local_time(utc_now(), 'Y'), 'attr') ?>-000123" autofocus>
            <button class="btn btn-primary" type="submit">Find</button>
        </div>
        <?php if ($code !== ''): ?>
            <p class="text-danger small mb-0 mt-2">Nothing carries that serial.</p>
        <?php endif ?>
    </form>
<?php else: ?>
    <div class="panel mb-3">
        <div class="panel-head">
            <strong class="mono"><?= esc($unit['serial_number']) ?></strong>
            <?= pill($unit['current_status']) ?>
        </div>
        <div class="p-3 small">
            <?= esc($unit['product_name'] ?? '') ?> · <?= esc($unit['size_label'] ?? '') ?>
            <?php if ($dealer !== null): ?>
                <span class="d-block text-muted">At <?= esc($dealer['business_name']) ?><?= $dealer['city'] !== null ? ', ' . esc($dealer['city']) : '' ?></span>
            <?php endif ?>
        </div>
    </div>

    <form method="post" action="<?= site_url('agent/reviews') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="code" value="<?= esc($unit['serial_number'], 'attr') ?>">
        <?php if ($survey !== null && is_uuid((string) $survey)): ?>
            <input type="hidden" name="survey_id" value="<?= esc($survey, 'attr') ?>">
        <?php endif ?>

        <div class="panel p-3 mb-3">
            <fieldset>
                <legend class="form-label fw-semibold">What is wrong?</legend>
                <?php foreach ($reasons as $key => $label): ?>
                    <div class="form-check py-1">
                        <input class="form-check-input" type="radio" name="reason" id="reason-<?= $key ?>"
                               value="<?= $key ?>"<?= $key === 'INVOICE_NOT_UPLOADED' ? ' checked' : '' ?> required>
                        <label class="form-check-label" for="reason-<?= $key ?>"><?= esc($label) ?></label>
                    </div>
                <?php endforeach ?>
            </fieldset>

            <div class="mt-3">
                <label class="form-label" for="remark">Anything else? <span class="text-muted fw-normal">(required if you chose Other)</span></label>
                <textarea class="form-control" id="remark" name="remark" rows="3" maxlength="1000"
                          placeholder="What you saw, and what the dealer said"></textarea>
            </div>
        </div>

        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            The customer's warranty stays exactly as it is. Someone who reviews warranties will
            check this and decide, and the dealer can upload a missing invoice in the meantime.
        </div>

        <div class="floor-commit">
            <button class="btn btn-primary w-100" type="submit">Send to the office for review</button>
        </div>
    </form>
<?php endif ?>

<?= $this->endSection() ?>

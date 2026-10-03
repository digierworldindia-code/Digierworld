<?php
/**
 * Stepper entry for single-round reports: Details → sections → Review.
 *
 * @var array<string, mixed>       $report
 * @var list<array<string, mixed>> $structure
 * @var list<array<string, mixed>> $rounds
 * @var bool                       $canEdit
 * @var array<string, mixed>       $actions
 * @var list<string>               $problems
 * @var string                     $idemKey
 */
$round = $rounds[0] ?? ['observations' => []];
$last  = count($structure) + 1;
$sr    = 0;
?>
<nav class="qms-steps" aria-label="Report sections" data-steps>
    <button type="button" data-goto="0" aria-current="step"><?= qms_icon('bi-card-heading') ?> Details</button>
    <?php foreach ($structure as $i => $section): ?>
        <button type="button" data-goto="<?= $i + 1 ?>"><span data-step-icon aria-hidden="true"></span><?= esc($section['title']) ?></button>
    <?php endforeach ?>
    <button type="button" data-goto="<?= $last ?>"><?= qms_icon('bi-check2-square') ?> Review</button>
</nav>

<section class="card mb-3 qms-step-panel" data-step="0" data-step-title="Report details" aria-labelledby="st-0">
    <div class="card-header" id="st-0">Report details</div>
    <div class="card-body"><?= $this->include('inspections/_header_form') ?></div>
</section>

<?php foreach ($structure as $i => $section): ?>
    <section class="qms-step-panel mb-3" data-step="<?= $i + 1 ?>" data-step-title="<?= esc($section['title'], 'attr') ?>" aria-labelledby="st-<?= $i + 1 ?>">
        <h2 class="h5 mb-2" id="st-<?= $i + 1 ?>"><?= esc($section['title']) ?></h2>
        <?php if (($section['description'] ?? '') !== ''): ?><p class="text-muted"><?= esc($section['description']) ?></p><?php endif ?>
        <?php foreach ($section['parameters'] as $param): $sr++; ?>
            <?= view('inspections/_param', [
                'param' => $param, 'obs' => $round['observations'][(int) $param['id']] ?? null, 'sr' => $sr,
                'editable' => $canEdit, 'gaugeOptions' => $gaugeOptions, 'blockExpired' => $blockExpired,
            ]) ?>
        <?php endforeach ?>
        <?php if ($section['parameters'] === []): ?><div class="qms-empty">No parameters in this section.</div><?php endif ?>
    </section>
<?php endforeach ?>

<section class="card mb-3 qms-step-panel" data-step="<?= $last ?>" data-step-title="Review and submit" aria-labelledby="st-review">
    <div class="card-header" id="st-review">Review and submit</div>
    <div class="card-body">
        <div class="qms-kpis">
            <div class="qms-kpi"><div class="label"><?= qms_icon('bi-list-check') ?> Parameters complete</div><div class="value" data-sum-done>–</div></div>
            <div class="qms-kpi qms-kpi--pass"><div class="label"><?= qms_icon('bi-check-circle') ?> Passed</div><div class="value" data-sum-pass>–</div></div>
            <div class="qms-kpi qms-kpi--fail"><div class="label"><?= qms_icon('bi-x-octagon') ?> Out of spec</div><div class="value" data-sum-fail>–</div></div>
        </div>
        <?= $this->include('inspections/_problems') ?>
        <p class="text-muted small mb-0"><?= qms_icon('bi-pen') ?> Submitting is your electronic signature as the operator
            (<?= esc($currentUser['display_name']) ?>). After submission the values can no longer be changed; corrections need a return or a revision.</p>
    </div>
</section>

<div class="qms-actionbar">
    <div class="qms-status">
        <span class="qms-progress" data-progress>Report details</span>
        <span class="qms-save-state" data-save-state role="status" aria-live="polite">All changes saved</span>
    </div>
    <button class="btn btn-outline-secondary btn-lg" type="button" data-prev><?= qms_icon('bi-chevron-left') ?> <span class="d-none d-sm-inline">Previous</span></button>
    <button class="btn btn-outline-primary btn-lg" type="button" data-next><span class="d-none d-sm-inline">Next</span> <?= qms_icon('bi-chevron-right') ?></button>
    <?php if ($canEdit): ?>
        <button class="btn btn-outline-primary btn-lg" type="button" data-save-now><?= qms_icon('bi-cloud-arrow-up') ?> Save</button>
    <?php endif ?>
    <?php if ($actions['submit']): ?>
        <button class="btn btn-success btn-lg" type="button" data-submit data-idem="<?= esc($idemKey, 'attr') ?>"><?= qms_icon('bi-send-check') ?> Submit</button>
    <?php endif ?>
</div>

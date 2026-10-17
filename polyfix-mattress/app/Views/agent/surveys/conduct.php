<?= $this->extend('layouts/floor') ?>

<?php
/**
 * Conducting the survey.
 *
 * The agent sets "what am I recording?" once and walks the shelf scanning.
 * That selector sits inside the scanner and is posted with every scan, so
 * the common case — a shelf of mattresses that are all there — is one tap
 * each, and only the exceptions need the selector changed.
 *
 * Below it, everything the records place with this dealer, so a unit whose
 * label will not scan can be ticked off the list instead.
 *
 * @var array<string,mixed>                    $s
 * @var array<string,int>                      $counts
 * @var list<array<string,mixed>>              $items
 * @var list<array<string,mixed>>              $stock
 * @var array<string, array<string,mixed>>     $examined
 */
$observations = [
    'PRESENT'          => ['Physically available', 'bi-check-circle'],
    'DEALER_SAYS_SOLD' => ['Dealer says sold', 'bi-cash-coin'],
    'NOT_FOUND'        => ['Not found', 'bi-question-circle'],
    'DAMAGED'          => ['Damaged', 'bi-exclamation-octagon'],
    'OTHER'            => ['Other', 'bi-three-dots'],
];
$outcomeTone = [
    'MATCHED'                  => 'success',
    'POSSIBLE_UNRECORDED_SALE' => 'warning',
    'REVIEW_REQUIRED'          => 'warning',
    'STOCK_DISCREPANCY'        => 'danger',
    'DAMAGED'                  => 'danger',
];

ob_start(); ?>
<div class="p-3 border-bottom bg-body-secondary">
    <label class="form-label fw-semibold mb-1" for="observation">What am I recording?</label>
    <select class="form-select form-select-lg" id="observation" name="observation" data-scanner-field="observation">
        <?php foreach ($observations as $key => [$label, $icon]): ?>
            <option value="<?= $key ?>"><?= esc($label) ?></option>
        <?php endforeach ?>
    </select>
    <p class="small text-muted mb-0 mt-2">
        Set this once and scan down the shelf. Change it only for the exceptions.
    </p>
</div>
<?php $before = ob_get_clean(); ?>

<?= $this->section('content') ?>

<div class="page-head">
    <h1><?= esc($s['dealer']) ?></h1>
    <p>Survey <span class="mono"><?= esc($s['reference']) ?></span>
        · started <?= esc(local_time($s['started_at'])) ?></p>
</div>

<?= view('partials/scanner', [
    'endpoint' => site_url('agent/surveys/' . $s['id'] . '/record'),
    'counter'  => $counts['examined'] . ' / ' . $counts['system_total'] . ' Mattresses Checked',
    'hint'     => 'Scan the label, or pick the mattress from the list below.',
    'before'   => $before,
]) ?>

<!-- ------------------------------------------------- what is left -->
<h2 class="h2 mt-4 mb-2">
    Still to check <span class="text-muted fw-normal">(<?= max(0, count($stock) - count($items)) ?>)</span>
</h2>

<?php
$remaining = array_values(array_filter($stock, static fn ($row) => ! isset($examined[$row['id']])));
?>

<?php if ($remaining === []): ?>
    <div class="panel p-4 text-center text-success">
        <i class="bi bi-check2-circle fs-3 d-block mb-2" aria-hidden="true"></i>
        Every mattress on the records has been checked.
    </div>
<?php else: ?>
    <div class="panel mb-3">
        <ul class="list-unstyled mb-0" style="max-height:24rem;overflow-y:auto">
            <?php foreach ($remaining as $row): ?>
                <li class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <span class="mono fw-semibold"><?= esc($row['serial_number']) ?></span>
                            <span class="d-block small text-muted">
                                <?= esc($row['product_name']) ?> · <?= esc($row['size_label']) ?>
                                · records say <?= esc(strtolower(humanise($row['current_status']))) ?>
                            </span>
                        </div>
                    </div>
                    <form class="d-flex gap-2 mt-2" method="post" action="<?= site_url('agent/surveys/' . $s['id'] . '/observe') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="code" value="<?= esc($row['serial_number'], 'attr') ?>">
                        <label class="visually-hidden" for="obs-<?= esc($row['id'], 'attr') ?>">What did you find?</label>
                        <select class="form-select form-select-sm" id="obs-<?= esc($row['id'], 'attr') ?>" name="observation">
                            <?php foreach ($observations as $key => [$label, $icon]): ?>
                                <option value="<?= $key ?>"><?= esc($label) ?></option>
                            <?php endforeach ?>
                        </select>
                        <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit">Record</button>
                    </form>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<!-- ------------------------------------------------- what was found -->
<?php if ($items !== []): ?>
    <h2 class="h2 mt-4 mb-2">Checked <span class="text-muted fw-normal">(<?= count($items) ?>)</span></h2>
    <div class="panel mb-3">
        <ul class="list-unstyled mb-0" style="max-height:20rem;overflow-y:auto">
            <?php foreach ($items as $item): ?>
                <li class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <span class="mono fw-semibold"><?= esc($item['serial_number']) ?></span>
                            <span class="d-block small text-muted">
                                <?= esc($observations[$item['observation']][0] ?? $item['observation']) ?>
                                <?= $item['remark'] !== null ? ' — ' . esc($item['remark']) : '' ?>
                            </span>
                        </div>
                        <span class="badge text-bg-<?= $outcomeTone[$item['outcome']] ?? 'secondary' ?>">
                            <?= esc(humanise($item['outcome'])) ?>
                        </span>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<!-- ------------------------------------------------- finishing -->
<form method="post" action="<?= site_url('agent/surveys/' . $s['id'] . '/submit') ?>"
      data-confirm="Finish this survey? The figures are fixed once it is submitted.">
    <?= csrf_field() ?>
    <div class="panel p-3 mb-3">
        <label class="form-label fw-semibold" for="remarks">Anything to add? <span class="text-muted fw-normal">(optional)</span></label>
        <textarea class="form-control" id="remarks" name="remarks" rows="3" maxlength="4000"
                  placeholder="e.g. stockroom was being reorganised; two units could not be reached"></textarea>
    </div>
    <div class="floor-commit">
        <button class="btn btn-primary w-100" type="submit"<?= $counts['examined'] === 0 ? ' disabled' : '' ?>>
            <?= $counts['examined'] === 0 ? 'Check a mattress first' : 'Finish the survey' ?>
        </button>
    </div>
</form>

<details class="panel p-3 mt-3">
    <summary>Abandon this survey</summary>
    <form class="mt-3" method="post" action="<?= site_url('agent/surveys/' . $s['id'] . '/abandon') ?>"
          data-confirm="Abandon this survey? Nothing you recorded will be reported.">
        <?= csrf_field() ?>
        <label class="form-label" for="reason">Why?</label>
        <textarea class="form-control mb-2" id="reason" name="reason" rows="2" maxlength="500" required
                  placeholder="e.g. opened at the wrong dealer"></textarea>
        <button class="btn btn-light btn-floor w-100" type="submit">Abandon</button>
    </form>
</details>

<?= $this->endSection() ?>

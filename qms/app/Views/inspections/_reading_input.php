<?php
/**
 * One reading control. All controls carry data-reading="<n>" inside the
 * observation container (data-obs) so inspection-form.js can track them.
 *
 * @var array<string, mixed>      $param
 * @var array<string, mixed>      $obs      observation with readings keyed by reading_no
 * @var int                       $n
 * @var bool                      $disabled
 * @var bool                      $compact  grid cell (In-Process)
 */
use App\Enums\ObservationType;

$reading = $obs['readings'][$n] ?? null;
$value   = reading_input($param, $reading);
$result  = $reading['result'] ?? null;
$state   = $result === 'PASS' ? ' is-pass' : ($result === 'FAIL' ? ' is-fail' : '');
$id      = 'r-' . $obs['id'] . '-' . $n;
$dis     = $disabled ? ' disabled' : '';
$label   = esc($param['name'], 'attr') . ', observation ' . $n;
$type    = ObservationType::from($param['observation_type']);
$cls     = $compact ? 'form-control form-control-sm qms-cell' : 'form-control qms-reading';

switch ($type):
    case ObservationType::OkNotOk:
    case ObservationType::Visual:
    case ObservationType::GoNoGo:
        $choiceClass = ['OK' => 'btn-outline-success btn-choice-ok', 'NOT_OK' => 'btn-outline-danger btn-choice-notok', 'GO' => 'btn-outline-success btn-choice-go', 'NO_GO' => 'btn-outline-danger btn-choice-nogo'];
        if ($compact): ?>
            <select class="form-select form-select-sm qms-cell<?= $state ?>" id="<?= $id ?>" data-reading="<?= $n ?>" aria-label="<?= $label ?>"<?= $dis ?>>
                <option value="">–</option>
                <?php foreach ($type->choices() as $code => $text): ?>
                    <option value="<?= $code ?>"<?= $value === $code ? ' selected' : '' ?>><?= esc($text) ?></option>
                <?php endforeach ?>
            </select>
        <?php else: ?>
            <div class="qms-choice" role="radiogroup" aria-label="<?= $label ?>" id="<?= $id ?>">
                <?php foreach ($type->choices() as $code => $text): ?>
                    <input class="btn-check" type="radio" name="<?= $id ?>" id="<?= $id ?>-<?= $code ?>" value="<?= $code ?>" data-reading="<?= $n ?>"<?= $value === $code ? ' checked' : '' ?><?= $dis ?>>
                    <label class="btn <?= $choiceClass[$code] ?>" for="<?= $id ?>-<?= $code ?>"><?= qms_icon(in_array($code, ['OK', 'GO'], true) ? 'bi-check-lg' : 'bi-x-lg') ?> <?= esc($text) ?></label>
                <?php endforeach ?>
            </div>
        <?php endif;
        break;
    case ObservationType::Date: ?>
        <input class="<?= $cls . $state ?>" type="date" id="<?= $id ?>" data-reading="<?= $n ?>" value="<?= esc($value, 'attr') ?>" aria-label="<?= $label ?>"<?= $dis ?>>
        <?php break;
    case ObservationType::Time: ?>
        <input class="<?= $cls . $state ?>" type="time" id="<?= $id ?>" data-reading="<?= $n ?>" value="<?= esc($value, 'attr') ?>" aria-label="<?= $label ?>"<?= $dis ?>>
        <?php break;
    case ObservationType::Text: ?>
        <input class="<?= $cls . $state ?>" type="text" id="<?= $id ?>" data-reading="<?= $n ?>" maxlength="255" value="<?= esc($value, 'attr') ?>" aria-label="<?= $label ?>" autocomplete="off"<?= $dis ?>>
        <?php break;
    default: ?>
        <input class="<?= $cls . $state ?> num" type="text" inputmode="decimal" id="<?= $id ?>" data-reading="<?= $n ?>" maxlength="20" value="<?= esc($value, 'attr') ?>"
               aria-label="<?= $label ?>" autocomplete="off" enterkeyhint="next"<?= $dis ?>>
        <?php break;
endswitch;

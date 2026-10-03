<?php
/**
 * Parameter card for single-round reports (Setup Change Approval, Product Parameter Inspection).
 *
 * @var array<string, mixed>             $param
 * @var array<string, mixed>|null        $obs
 * @var int                              $sr
 * @var bool                             $editable
 * @var array<int, list<array<string, mixed>>> $gaugeOptions
 * @var bool                             $blockExpired
 */
if ($obs === null) {
    return;
}
$places    = (int) $param['decimal_places'];
$limits    = in_array($param['observation_type'], ['NUMERIC', 'PERCENTAGE'], true);
$unit      = $param['observation_type'] === 'PERCENTAGE' ? '%' : (string) ($param['unit_symbol'] ?? '');
$gaugeOpts = $param['gauge_type_id'] !== null ? ($gaugeOptions[(int) $param['gauge_type_id']] ?? []) : null;
$verdict   = static function (?string $result, string $type): array {
    return match ($result) {
        'PASS'  => ['is-pass', in_array($type, ['NUMERIC', 'PERCENTAGE'], true) ? '✓ Within limits' : '✓ PASS'],
        'FAIL'  => ['is-fail', in_array($type, ['NUMERIC', 'PERCENTAGE'], true) ? '✗ OUT OF SPEC' : '✗ FAIL'],
        default => ['', ''],
    };
};
?>
<div class="qms-param<?= $obs['result'] === 'FAIL' ? ' is-fail' : '' ?>" id="obs-<?= (int) $obs['id'] ?>" data-obs="<?= (int) $obs['id'] ?>"
     data-type="<?= esc($param['observation_type'], 'attr') ?>" data-lsl="<?= esc((string) $obs['lsl'], 'attr') ?>" data-usl="<?= esc((string) $obs['usl'], 'attr') ?>"
     data-decimals="<?= $places ?>" data-date-rule="<?= esc($param['date_rule'], 'attr') ?>" data-count="<?= (int) $param['observation_count'] ?>"
     data-mandatory="<?= (int) $param['is_mandatory'] ?>" data-name="<?= esc($param['name'], 'attr') ?>">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0">
            <div class="p-title"><?= $sr ?>. <?= esc($param['name']) ?><?= (int) $param['is_mandatory'] === 1 ? '' : ' <span class="small text-muted fw-normal">(optional)</span>' ?></div>
            <div class="p-spec">
                <?php if (($param['specification_text'] ?? '') !== ''): ?><span>Spec <b><?= esc($param['specification_text']) ?></b></span><?php endif ?>
                <?php if ($limits): ?>
                    <span>LSL <b><?= $obs['lsl'] === null ? '–' : esc(qms_decimal($obs['lsl'], $places)) ?></b></span>
                    <span>USL <b><?= $obs['usl'] === null ? '–' : esc(qms_decimal($obs['usl'], $places)) ?></b></span>
                    <?php if ($unit !== ''): ?><span>Unit <b><?= esc($unit) ?></b></span><?php endif ?>
                <?php endif ?>
                <?php if ($param['method_name'] !== null): ?><span>Method <b><?= esc($param['method_name']) ?></b></span><?php endif ?>
                <?php if ($param['gauge_type_name'] !== null): ?><span>Instrument <b><?= esc($param['gauge_type_name']) ?></b></span><?php endif ?>
                <?php if ($param['date_rule'] === 'ON_OR_AFTER_INSPECTION_DATE'): ?><span>Must not be before the inspection date</span><?php endif ?>
                <?php if ($param['prefill_source'] !== 'NONE'): ?><span class="text-primary"><?= qms_icon('bi-magic') ?> Filled from master data</span><?php endif ?>
            </div>
            <?php if (($param['help_text'] ?? '') !== ''): ?><div class="small text-muted mt-1"><?= qms_icon('bi-info-circle') ?> <?= esc($param['help_text']) ?></div><?php endif ?>
        </div>
        <div class="p-result" data-result><?= result_badge($obs['result']) ?></div>
    </div>

    <div class="qms-readings">
        <?php for ($n = 1; $n <= (int) $param['observation_count']; $n++): [$vClass, $vText] = $verdict($obs['readings'][$n]['result'] ?? null, $param['observation_type']); ?>
            <div class="qms-reading-cell">
                <label class="qms-reading-label" for="r-<?= (int) $obs['id'] ?>-<?= $n ?>">Observation <?= $n ?><?= $limits && $unit !== '' ? ' (' . esc($unit) . ')' : '' ?></label>
                <?= view('inspections/_reading_input', ['param' => $param, 'obs' => $obs, 'n' => $n, 'disabled' => ! $editable, 'compact' => false]) ?>
                <div class="qms-verdict <?= $vClass ?>" id="v-<?= (int) $obs['id'] ?>-<?= $n ?>" aria-live="polite"><?= esc($vText) ?></div>
                <div class="invalid-feedback d-block" data-error-for="cell-<?= (int) $obs['id'] ?>-<?= $n ?>"></div>
            </div>
        <?php endfor ?>
    </div>

    <div class="qms-param-foot">
        <?php if ($gaugeOpts !== null): ?>
            <?php
            $known = array_column($gaugeOpts, null, 'id');
            $sel   = $obs['gauge_id'] === null ? null : (int) $obs['gauge_id'];
            ?>
            <div>
                <label class="form-label<?= (int) $param['gauge_required'] === 1 ? ' required' : '' ?>" for="g-<?= (int) $obs['id'] ?>">Gauge ID</label>
                <select class="form-select form-select-lg" id="g-<?= (int) $obs['id'] ?>" data-gauge<?= $editable ? '' : ' disabled' ?>>
                    <option value="">Choose gauge</option>
                    <?php foreach ($gaugeOpts as $g): $a = $g['availability']; ?>
                        <option value="<?= (int) $g['id'] ?>" data-usable="<?= $a['usable'] ? 1 : 0 ?>" data-state="<?= esc($a['label'], 'attr') ?>"<?= $sel === (int) $g['id'] ? ' selected' : '' ?>>
                            <?= esc($g['gauge_code'] . ' · ' . $g['gauge_name']) ?><?= $a['usable'] ? '' : ' — ' . esc($a['label']) ?>
                        </option>
                    <?php endforeach ?>
                    <?php if ($sel !== null && ! isset($known[$sel])): ?>
                        <option value="<?= $sel ?>" data-usable="<?= (int) $obs['gauge_cal_valid'] ?>" data-state="Not available any more" selected><?= esc((string) $obs['gauge_code']) ?></option>
                    <?php endif ?>
                </select>
                <?php
                $selected = $sel !== null ? ($known[$sel]['availability'] ?? null) : null;
                $gState   = $selected === null ? '' : ($selected['usable'] ? $selected['label'] : $selected['label'] . ($blockExpired ? ' – submission will be blocked' : ''));
                ?>
                <div class="qms-gauge-state <?= $selected !== null && ! $selected['usable'] ? 'text-danger fw-bold' : 'text-muted' ?>" data-gauge-state
                     data-block="<?= $blockExpired ? 1 : 0 ?>"><?= esc($gState) ?></div>
            </div>
        <?php endif ?>
        <div class="<?= $gaugeOpts === null ? 'qms-span-2' : '' ?>">
            <label class="form-label" for="rm-<?= (int) $obs['id'] ?>">Remarks</label>
            <input class="form-control form-control-lg" id="rm-<?= (int) $obs['id'] ?>" data-remarks maxlength="500" autocomplete="off"
                   value="<?= esc((string) ($obs['remarks'] ?? ''), 'attr') ?>"<?= $editable ? '' : ' disabled' ?>>
        </div>
        <div class="invalid-feedback d-block qms-span-2" data-error-for="obs-<?= (int) $obs['id'] ?>"></div>
    </div>
</div>

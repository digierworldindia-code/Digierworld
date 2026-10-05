<?php
/**
 * Parameter card of single-round reports (Setup Change Approval, Product Parameter Inspection).
 * Variables: $param, $obs (observation or null), $sr, $canEdit, $gaugeOptions, $blockExpired.
 */
defined('QMS') || exit;

if ($obs === null) {
    return;
}
$places    = (int) $param['decimal_places'];
$limits    = obs_uses_limits($param['observation_type']);
$unit      = $param['observation_type'] === 'PERCENTAGE' ? '%' : (string) ($param['unit_symbol'] ?? '');
$gaugeOpts = $param['gauge_type_id'] !== null ? ($gaugeOptions[(int) $param['gauge_type_id']] ?? []) : null;
$verdict   = static function (?string $result) use ($limits): array {
    return match ($result) {
        'PASS'  => ['is-pass', $limits ? '✓ Within limits' : '✓ PASS'],
        'FAIL'  => ['is-fail', $limits ? '✗ OUT OF SPEC' : '✗ FAIL'],
        default => ['', ''],
    };
};
$oid = (int) $obs['id'];
?>
<div class="qms-param<?= $obs['result'] === 'FAIL' ? ' is-fail' : '' ?>" id="obs-<?= $oid ?>"<?= insp_obs_attributes($param, $obs) ?>>
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0">
            <div class="p-title"><?= $sr ?>. <?= e($param['name']) ?><?= (int) $param['is_mandatory'] === 1 ? '' : ' <span class="small text-muted fw-normal">(optional)</span>' ?></div>
            <div class="p-spec">
                <?php if (($param['specification_text'] ?? '') !== ''): ?><span>Spec <b><?= e($param['specification_text']) ?></b></span><?php endif ?>
                <?php if ($limits): ?>
                    <span>LSL <b><?= $obs['lsl'] === null ? '–' : e(qms_decimal($obs['lsl'], $places)) ?></b></span>
                    <span>USL <b><?= $obs['usl'] === null ? '–' : e(qms_decimal($obs['usl'], $places)) ?></b></span>
                    <?php if ($unit !== ''): ?><span>Unit <b><?= e($unit) ?></b></span><?php endif ?>
                <?php endif ?>
                <?php if ($param['method_name'] !== null): ?><span>Method <b><?= e($param['method_name']) ?></b></span><?php endif ?>
                <?php if ($param['gauge_type_name'] !== null): ?><span>Instrument <b><?= e($param['gauge_type_name']) ?></b></span><?php endif ?>
                <?php if ($param['date_rule'] === 'ON_OR_AFTER_INSPECTION_DATE'): ?><span>Must not be before the inspection date</span><?php endif ?>
                <?php if ($param['prefill_source'] !== 'NONE'): ?><span class="text-primary"><?= qms_icon('bi-magic') ?> Filled from master data</span><?php endif ?>
            </div>
            <?php if (($param['help_text'] ?? '') !== ''): ?><div class="small text-muted mt-1"><?= qms_icon('bi-info-circle') ?> <?= e($param['help_text']) ?></div><?php endif ?>
        </div>
        <div class="p-result" data-result><?= result_badge($obs['result']) ?></div>
    </div>

    <div class="qms-readings">
        <?php for ($n = 1; $n <= (int) $param['observation_count']; $n++): [$vClass, $vText] = $verdict($obs['readings'][$n]['result'] ?? null); ?>
            <div class="qms-reading-cell">
                <label class="qms-reading-label" for="r-<?= $oid ?>-<?= $n ?>">Observation <?= $n ?><?= $limits && $unit !== '' ? ' (' . e($unit) . ')' : '' ?></label>
                <?= insp_reading_input($param, $obs, $n, ! $canEdit, false) ?>
                <div class="qms-verdict <?= $vClass ?>" id="v-<?= $oid ?>-<?= $n ?>" aria-live="polite"><?= e($vText) ?></div>
                <div class="invalid-feedback d-block" data-error-for="cell-<?= $oid ?>-<?= $n ?>"></div>
            </div>
        <?php endfor ?>
    </div>

    <div class="qms-param-foot">
        <?php if ($gaugeOpts !== null):
            $known = array_column($gaugeOpts, null, 'id');
            $sel   = $obs['gauge_id'] === null ? null : (int) $obs['gauge_id']; ?>
            <div>
                <label class="form-label<?= (int) $param['gauge_required'] === 1 ? ' required' : '' ?>" for="g-<?= $oid ?>">Gauge ID</label>
                <select class="form-select form-select-lg" id="g-<?= $oid ?>" data-gauge<?= $canEdit ? '' : ' disabled' ?>>
                    <option value="">Choose gauge</option>
                    <?php foreach ($gaugeOpts as $g): $a = $g['availability']; ?>
                        <option value="<?= (int) $g['id'] ?>" data-usable="<?= $a['usable'] ? 1 : 0 ?>" data-state="<?= e($a['label']) ?>"<?= $sel === (int) $g['id'] ? ' selected' : '' ?>>
                            <?= e($g['gauge_code'] . ' · ' . $g['gauge_name']) ?><?= $a['usable'] ? '' : ' — ' . e($a['label']) ?>
                        </option>
                    <?php endforeach ?>
                    <?php if ($sel !== null && ! isset($known[$sel])): ?>
                        <option value="<?= $sel ?>" data-usable="<?= (int) $obs['gauge_cal_valid'] ?>" data-state="Not available any more" selected><?= e((string) $obs['gauge_code']) ?></option>
                    <?php endif ?>
                </select>
                <?php
                $selected = $sel !== null ? ($known[$sel]['availability'] ?? null) : null;
                $gState   = $selected === null ? '' : ($selected['usable'] ? $selected['label'] : $selected['label'] . ($blockExpired ? ' – submission will be blocked' : ''));
                ?>
                <div class="qms-gauge-state <?= $selected !== null && ! $selected['usable'] ? 'text-danger fw-bold' : 'text-muted' ?>" data-gauge-state
                     data-block="<?= $blockExpired ? 1 : 0 ?>"><?= e($gState) ?></div>
            </div>
        <?php endif ?>
        <div class="<?= $gaugeOpts === null ? 'qms-span-2' : '' ?>">
            <label class="form-label" for="rm-<?= $oid ?>">Remarks</label>
            <input class="form-control form-control-lg" id="rm-<?= $oid ?>" data-remarks maxlength="500" autocomplete="off"
                   value="<?= e((string) ($obs['remarks'] ?? '')) ?>"<?= $canEdit ? '' : ' disabled' ?>>
        </div>
        <div class="invalid-feedback d-block qms-span-2" data-error-for="obs-<?= $oid ?>"></div>
    </div>
</div>

<?php
/**
 * In-Process Inspection Report: parameters down, shifts x inspection times across.
 *
 * @var array<string, mixed>       $report
 * @var list<array<string, mixed>> $structure
 * @var list<array<string, mixed>> $rounds
 * @var list<array<string, mixed>> $shifts
 * @var bool                       $canEdit
 * @var array<string, mixed>       $actions
 * @var array<string, mixed>       $user
 * @var string                     $idemKey
 */
$inspections = service('inspections');
$authz       = service('authorization');
$canVerify   = $authz->userCan($user, 'inspection.round_verify');
$canReopen   = $authz->userCan($user, 'inspection.round_reopen');
$reauth      = (bool) qms_setting('workflow.reauth_on_sign', true);
$current     = service('productionCalendar')->current();
$nowTime     = service('clock')->plantNow()->format('H:i');
$byShift     = [];
foreach ($rounds as $round) {
    $byShift[(int) $round['shift_id']][] = $round;
}
$editable = [];
foreach ($rounds as $round) {
    $editable[(int) $round['id']] = $inspections->canEditRound($report, $round, $user);
}
$params = [];
foreach ($structure as $section) {
    foreach ($section['parameters'] as $param) {
        $params[] = $param;
    }
}
$stateBadge = static fn (string $s): string => match ($s) {
    'VERIFIED' => '<span class="qms-badge qms-badge--approved">' . qms_icon('bi-patch-check') . ' Verified</span>',
    'SIGNED'   => '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-pen') . ' Signed</span>',
    default    => '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-pencil') . ' Open</span>',
};
$roundLabel = static fn (array $r): string => '#' . $r['round_no'] . ' (' . substr((string) $r['inspection_time'], 0, 5) . ')';
// Columns per shift: its rounds + one "add" column while the sheet is editable.
$colsFor = static fn (array $list): int => max(1, count($list) + ($canEdit ? 1 : 0));
$footCell = static function (callable $render) use ($shifts, $byShift, $canEdit): string {
    $html = '';
    foreach ($shifts as $shift) {
        $list = $byShift[(int) $shift['id']] ?? [];
        foreach ($list as $round) {
            $html .= '<td>' . $render($round) . '</td>';
        }
        if ($canEdit || $list === []) {
            $html .= '<td class="qms-add-col"></td>';
        }
    }

    return $html;
};
?>
<div class="card mb-3">
    <div class="card-header">Report details</div>
    <div class="card-body"><?= $this->include('inspections/_header_form') ?></div>
</div>

<div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-2">
    <h2 class="h5 mb-0">Inspections by shift and time</h2>
    <div class="small text-muted">Fill your own inspection column, then sign it. Signed columns are locked.</div>
</div>

<div class="qms-grid-wrap mb-3">
    <table class="qms-grid">
        <thead>
        <tr>
            <th class="sticky-col" rowspan="2" scope="col">Parameter / specification</th>
            <?php foreach ($shifts as $shift): ?>
                <th colspan="<?= $colsFor($byShift[(int) $shift['id']] ?? []) ?>" scope="colgroup"<?= (int) ($current['shift']['id'] ?? 0) === (int) $shift['id'] && $current['date'] === $report['inspection_date'] ? ' class="is-current-shift"' : '' ?>>
                    Shift <?= esc($shift['code']) ?> <span class="fw-normal">(<?= esc(substr($shift['start_time'], 0, 5) . '–' . substr($shift['end_time'], 0, 5)) ?>)</span>
                </th>
            <?php endforeach ?>
        </tr>
        <tr>
            <?php foreach ($shifts as $shift): $list = $byShift[(int) $shift['id']] ?? []; ?>
                <?php foreach ($list as $round): $ed = $editable[(int) $round['id']]; ?>
                    <th scope="col" id="round-<?= (int) $round['id'] ?>" class="qms-round-head">
                        <div class="small">Inspection #<?= (int) $round['round_no'] ?></div>
                        <input class="form-control form-control-sm" type="time" value="<?= esc(substr((string) $round['inspection_time'], 0, 5), 'attr') ?>"
                               data-round="<?= (int) $round['id'] ?>" data-round-field="inspection_time" aria-label="Time of inspection #<?= (int) $round['round_no'] ?>"<?= $ed ? '' : ' disabled' ?>>
                        <span class="qms-round-state"><?= $stateBadge($round['status']) ?></span>
                        <div class="invalid-feedback d-block" data-error-for="round-<?= (int) $round['id'] ?>"></div>
                    </th>
                <?php endforeach ?>
                <?php if ($canEdit): ?>
                    <th scope="col" class="qms-add-col">
                        <?php $isCurrent = (int) ($current['shift']['id'] ?? 0) === (int) $shift['id']; ?>
                        <form method="post" action="<?= site_url('inspections/' . $report['id'] . '/rounds') ?>" data-flush-save>
                            <?= csrf_field() ?>
                            <input type="hidden" name="shift_id" value="<?= (int) $shift['id'] ?>">
                            <label class="small" for="add-<?= (int) $shift['id'] ?>">New inspection</label>
                            <input class="form-control form-control-sm" type="time" id="add-<?= (int) $shift['id'] ?>" name="inspection_time" required
                                   value="<?= esc($isCurrent ? $nowTime : substr($shift['start_time'], 0, 5), 'attr') ?>">
                            <button class="btn btn-sm btn-primary w-100 mt-1" type="submit" data-once><?= qms_icon('bi-plus-lg') ?> Add</button>
                        </form>
                    </th>
                <?php elseif ($list === []): ?>
                    <th scope="col" class="qms-add-col small text-muted">No inspection</th>
                <?php endif ?>
            <?php endforeach ?>
        </tr>
        </thead>
        <tbody>
        <?php $sr = 0; foreach ($params as $param): $sr++; ?>
            <tr>
                <th class="sticky-col" scope="row">
                    <div class="fw-bold"><?= $sr ?>. <?= esc($param['name']) ?></div>
                    <div class="small text-muted">
                        <?= esc((string) ($param['specification_text'] ?? '')) ?>
                        <?php $lim = spec_limits($param['lsl'], $param['usl'], (int) $param['decimal_places'], $param['unit_symbol']); ?>
                        <?= $lim !== '' ? '<div>' . esc($lim) . '</div>' : '' ?>
                        <?= $param['method_label'] !== null ? '<div>' . esc($param['method_label']) . ($param['gauge_type_name'] !== null ? ' · ' . esc($param['gauge_type_name']) : '') . '</div>' : '' ?>
                    </div>
                </th>
                <?php foreach ($shifts as $shift): $list = $byShift[(int) $shift['id']] ?? []; ?>
                    <?php foreach ($list as $round): $obs = $round['observations'][(int) $param['id']] ?? null; $ed = $editable[(int) $round['id']]; ?>
                        <?php if ($obs === null): ?><td></td><?php continue; endif ?>
                        <td class="qms-cell-wrap<?= $obs['result'] === 'FAIL' ? ' is-fail' : '' ?>" data-obs="<?= (int) $obs['id'] ?>"
                            data-type="<?= esc($param['observation_type'], 'attr') ?>" data-lsl="<?= esc((string) $obs['lsl'], 'attr') ?>" data-usl="<?= esc((string) $obs['usl'], 'attr') ?>"
                            data-decimals="<?= (int) $param['decimal_places'] ?>" data-date-rule="<?= esc($param['date_rule'], 'attr') ?>" data-count="<?= (int) $param['observation_count'] ?>"
                            data-mandatory="<?= (int) $param['is_mandatory'] ?>" data-name="<?= esc($param['name'], 'attr') ?>" data-round-id="<?= (int) $round['id'] ?>">
                            <?php for ($n = 1; $n <= (int) $param['observation_count']; $n++): ?>
                                <?= view('inspections/_reading_input', ['param' => $param, 'obs' => $obs, 'n' => $n, 'disabled' => ! $ed, 'compact' => true]) ?>
                                <span class="qms-mini-verdict" id="v-<?= (int) $obs['id'] ?>-<?= $n ?>" aria-live="polite"><?= ($obs['readings'][$n]['result'] ?? null) === 'FAIL' ? '✗ OOS' : '' ?></span>
                                <div class="invalid-feedback d-block small" data-error-for="cell-<?= (int) $obs['id'] ?>-<?= $n ?>"></div>
                            <?php endfor ?>
                            <?php if ($param['gauge_type_id'] !== null): ?>
                                <select class="form-select form-select-sm mt-1" data-gauge aria-label="Gauge for <?= esc($param['name'], 'attr') ?>, inspection #<?= (int) $round['round_no'] ?>"<?= $ed ? '' : ' disabled' ?>>
                                    <option value=""><?= (int) $param['gauge_required'] === 1 ? 'Gauge ID *' : 'Gauge ID' ?></option>
                                    <?php $found = false; foreach ($gaugeOptions[(int) $param['gauge_type_id']] ?? [] as $g): $found = $found || (int) $obs['gauge_id'] === (int) $g['id']; ?>
                                        <option value="<?= (int) $g['id'] ?>" data-usable="<?= $g['availability']['usable'] ? 1 : 0 ?>" data-state="<?= esc($g['availability']['label'], 'attr') ?>"<?= (int) $obs['gauge_id'] === (int) $g['id'] ? ' selected' : '' ?>><?= esc($g['gauge_code']) ?><?= $g['availability']['usable'] ? '' : ' ⚠' ?></option>
                                    <?php endforeach ?>
                                    <?php if ($obs['gauge_id'] !== null && ! $found): ?><option value="<?= (int) $obs['gauge_id'] ?>" selected><?= esc((string) $obs['gauge_code']) ?></option><?php endif ?>
                                </select>
                                <div class="qms-gauge-state small" data-gauge-state data-block="<?= $blockExpired ? 1 : 0 ?>"></div>
                            <?php endif ?>
                            <div class="invalid-feedback d-block small" data-error-for="obs-<?= (int) $obs['id'] ?>"></div>
                        </td>
                    <?php endforeach ?>
                    <?php if ($canEdit || $list === []): ?><td class="qms-add-col"></td><?php endif ?>
                <?php endforeach ?>
            </tr>
        <?php endforeach ?>
        </tbody>
        <tbody class="round-foot">
        <tr>
            <th class="sticky-col" scope="row">Lot status</th>
            <?= $footCell(static function (array $r) use ($editable): string {
                $html = '<select class="form-select form-select-sm" data-round="' . (int) $r['id'] . '" data-round-field="lot_status" aria-label="Lot status, inspection #' . (int) $r['round_no'] . '"' . ($editable[(int) $r['id']] ? '' : ' disabled') . '><option value="">–</option>';
                foreach (['ACCEPTED' => 'Accepted', 'REJECTED' => 'Rejected', 'REWORK' => 'Rework', 'ON_HOLD' => 'On hold'] as $k => $v) {
                    $html .= '<option value="' . $k . '"' . ($r['lot_status'] === $k ? ' selected' : '') . '>' . $v . '</option>';
                }

                return $html . '</select>';
            }) ?>
        </tr>
        <?php foreach (['accepted_qty' => 'Accepted qty', 'rejected_qty' => 'Rejected qty', 'rework_qty' => 'Rework qty'] as $field => $label): ?>
            <tr>
                <th class="sticky-col" scope="row"><?= $label ?></th>
                <?= $footCell(static fn (array $r): string => '<input class="form-control form-control-sm num" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="9" data-round="' . (int) $r['id'] . '" data-round-field="' . $field . '" value="' . esc((string) $r[$field], 'attr') . '" aria-label="' . $label . ', inspection #' . (int) $r['round_no'] . '"' . ($editable[(int) $r['id']] ? '' : ' disabled') . '>') ?>
            </tr>
        <?php endforeach ?>
        <tr>
            <th class="sticky-col" scope="row">Remarks</th>
            <?= $footCell(static fn (array $r): string => '<input class="form-control form-control-sm" maxlength="500" data-round="' . (int) $r['id'] . '" data-round-field="remarks" value="' . esc((string) ($r['remarks'] ?? ''), 'attr') . '" aria-label="Remarks, inspection #' . (int) $r['round_no'] . '"' . ($editable[(int) $r['id']] ? '' : ' disabled') . '>') ?>
        </tr>
        <tr>
            <th class="sticky-col" scope="row">Inspected by operator</th>
            <?= $footCell(static function (array $r) use ($editable, $report, $roundLabel): string {
                if ($r['status'] !== 'OPEN') {
                    return '<strong>' . esc($r['operator_name'] ?? $r['operator_username'] ?? '') . '</strong><div class="small text-muted">' . esc(plant_dt($r['operator_signed_at'], 'd-m H:i')) . '</div>';
                }
                if (! $editable[(int) $r['id']]) {
                    return '<span class="text-muted small">Not signed</span>';
                }

                return '<form method="post" action="' . site_url('inspections/' . $report['id'] . '/rounds/' . $r['id'] . '/sign') . '" data-flush-save data-confirm="Sign inspection ' . esc($roundLabel($r), 'attr') . '? Its values become read-only.">'
                    . csrf_field() . '<button class="btn btn-sm btn-success w-100" type="submit" data-once>' . qms_icon('bi-pen') . ' Sign</button></form>';
            }) ?>
        </tr>
        <tr>
            <th class="sticky-col" scope="row">Inspected by QE</th>
            <?= $footCell(static function (array $r) use ($canVerify, $user, $report, $reauth, $roundLabel): string {
                if ($r['status'] === 'VERIFIED') {
                    return '<strong>' . esc($r['qe_name'] ?? $r['qe_username'] ?? '') . '</strong><div class="small text-muted">' . esc(plant_dt($r['qe_verified_at'], 'd-m H:i')) . '</div>';
                }
                if ($r['status'] === 'SIGNED' && $canVerify && (int) $r['operator_user_id'] !== (int) $user['id']) {
                    return '<button class="btn btn-sm btn-primary w-100" type="button" data-sign-action="verify" data-sign-url="' . site_url('inspections/' . $report['id'] . '/rounds/' . $r['id'] . '/verify') . '"'
                        . ' data-sign-title="Verify inspection ' . esc($roundLabel($r), 'attr') . '" data-sign-meaning="Inspected By Quality Engineer: you confirm the readings of this inspection."'
                        . ' data-sign-remarks="none" data-sign-password="' . ($reauth ? 'yes' : 'no') . '" data-sign-button="Verify">' . qms_icon('bi-patch-check') . ' Verify</button>';
                }

                return '<span class="text-muted small">–</span>';
            }) ?>
        </tr>
        <tr>
            <th class="sticky-col" scope="row"><span class="visually-hidden">Inspection actions</span></th>
            <?= $footCell(static function (array $r) use ($canReopen, $editable, $report, $roundLabel): string {
                $html = '';
                if ($r['status'] !== 'OPEN' && $canReopen) {
                    $html .= '<button class="btn btn-sm btn-outline-secondary w-100" type="button" data-sign-action="reopen" data-sign-url="' . site_url('inspections/' . $report['id'] . '/rounds/' . $r['id'] . '/reopen') . '"'
                        . ' data-sign-title="Reopen inspection ' . esc($roundLabel($r), 'attr') . '" data-sign-meaning="The signatures of this inspection are cleared so the values can be corrected."'
                        . ' data-sign-remarks="required" data-sign-password="no" data-sign-button="Reopen" data-sign-tone="btn-warning">' . qms_icon('bi-unlock') . ' Reopen</button>';
                }
                $empty = true;
                foreach ($r['observations'] as $o) {
                    foreach ($o['readings'] as $rd) {
                        $empty = $empty && $rd['result'] === null;
                    }
                }
                if ($r['status'] === 'OPEN' && $editable[(int) $r['id']] && $empty) {
                    $html .= '<form method="post" action="' . site_url('inspections/' . $report['id'] . '/rounds/' . $r['id'] . '/delete') . '" data-confirm="Remove empty inspection ' . esc($roundLabel($r), 'attr') . '?">'
                        . csrf_field() . '<button class="btn btn-sm btn-link text-danger w-100" type="submit">' . qms_icon('bi-trash') . ' Remove</button></form>';
                }

                return $html;
            }) ?>
        </tr>
        </tbody>
    </table>
</div>

<?= $this->include('inspections/_problems') ?>

<div class="qms-actionbar">
    <div class="qms-status">
        <span class="qms-progress"><?= count($rounds) ?> inspection(s) on this sheet</span>
        <span class="qms-save-state" data-save-state role="status" aria-live="polite">All changes saved</span>
    </div>
    <?php if ($canEdit): ?>
        <button class="btn btn-outline-primary btn-lg" type="button" data-save-now><?= qms_icon('bi-cloud-arrow-up') ?> Save</button>
    <?php endif ?>
    <?php if ($actions['submit']): ?>
        <button class="btn btn-success btn-lg" type="button" data-submit data-idem="<?= esc($idemKey, 'attr') ?>"
                data-confirm="Submit the In-Process sheet for <?= esc(plant_date($report['inspection_date']), 'attr') ?>? No more inspections can be added afterwards."><?= qms_icon('bi-send-check') ?> Submit sheet</button>
    <?php endif ?>
</div>

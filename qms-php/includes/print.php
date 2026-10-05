<?php
/**
 * Everything the A4 printout needs, laid out like the paper formats: sectioned
 * Setup Change Approval, method-matrix Product Parameter Inspection and the
 * shift × inspection-time In-Process grid. The browser prints it or saves it
 * as PDF ("Save as PDF" in the print dialog) – no PDF library is needed.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/workflow.php';

function print_model(int $reportId, array $user): array
{
    $data   = insp_load($reportId, $user);
    $report = $data['report'];
    $ref    = insp_ref($report);

    $model = [
        'report'      => $report,
        'ref'         => $ref,
        'layout'      => $report['layout'],
        'orientation' => $report['layout'] === 'SHIFT_GRID' ? 'L' : 'P',
        'company'     => [
            'name'    => setting_str('company.name', 'Company'),
            'address' => setting_str('company.address'),
            'logo'    => setting_str('company.logo') !== '' ? url('logo.php') : null,
        ],
        'watermark'   => match ($report['status']) {
            'QA_APPROVED' => null,
            'SUPERSEDED'  => 'SUPERSEDED',
            'CANCELLED'   => 'CANCELLED',
            'REJECTED'    => 'REJECTED',
            'DRAFT'       => 'DRAFT',
            'RETURNED'    => 'RETURNED',
            default       => 'NOT APPROVED',
        },
        'steps'       => wf_steps($report, $data['approvals']),
        'printedBy'   => trim(($user['full_name'] ?? $user['username']) . ($user['employee_code'] ? ' (' . $user['employee_code'] . ')' : '')),
        'printedAt'   => plant_now()->format('d-m-Y H:i'),
        'times'       => [
            'received' => $report['received_at'] === null ? '' : to_plant($report['received_at'], 'H:i'),
            'finish'   => $report['finish_at'] === null ? '' : to_plant($report['finish_at'], 'H:i'),
        ],
    ];

    return $model + ($report['layout'] === 'SHIFT_GRID' ? print_grid($data) : print_sheet($data));
}

/** Sectioned / method-matrix layouts: one round, one row per parameter. */
function print_sheet(array $data): array
{
    $round    = $data['rounds'][0] ?? ['observations' => []];
    $maxObs   = 1;
    $methods  = [];
    $sections = [];
    $sr       = 0;
    foreach ($data['structure'] as $section) {
        $rows = [];
        foreach ($section['parameters'] as $param) {
            $maxObs = max($maxObs, (int) $param['observation_count']);
            if ($param['inspection_method_id'] !== null) {
                $methods[(int) $param['inspection_method_id']] = true;
            }
            $rows[] = print_row(++$sr, $param, $round['observations'][(int) $param['id']] ?? null);
        }
        $sections[] = ['title' => $section['title'], 'rows' => $rows];
    }
    $ids           = array_keys($methods);
    $methodColumns = $ids === [] ? [] : db_all('SELECT id, short_label, name FROM inspection_methods WHERE id IN (' . db_in($ids) . ') ORDER BY sort_order, id', $ids);

    return ['sections' => $sections, 'maxObs' => $maxObs, 'methodColumns' => $methodColumns];
}

/** In-Process grid: parameters down, shifts × inspection times across. */
function print_grid(array $data): array
{
    $planned = (int) ($data['report']['planned_rounds_per_shift'] ?? 0);
    $byShift = [];
    foreach ($data['rounds'] as $round) {
        $byShift[(int) $round['shift_id']][] = $round;
    }
    $shifts = [];
    foreach ($data['shifts'] as $shift) {
        $rounds   = $byShift[(int) $shift['id']] ?? [];
        $shifts[] = ['shift' => $shift, 'columns' => array_pad($rounds, max(count($rounds), $planned, 1), null)];
        unset($byShift[(int) $shift['id']]);
    }
    foreach ($byShift as $rounds) {
        // Rounds of a shift that was retired later still print.
        $shifts[] = ['shift' => ['code' => $rounds[0]['shift_code'] ?? '?', 'name' => ''], 'columns' => $rounds];
    }

    $rows = [];
    $sr   = 0;
    foreach ($data['structure'] as $section) {
        foreach ($section['parameters'] as $param) {
            $cells = [];
            foreach ($data['rounds'] as $round) {
                $cells[(int) $round['id']] = print_readings($param, $round['observations'][(int) $param['id']] ?? null);
            }
            $first  = $data['rounds'][0]['observations'][(int) $param['id']] ?? null;
            $rows[] = [
                'sr'     => ++$sr,
                'name'   => $param['name'],
                'spec'   => (string) ($param['specification_text'] ?? ''),
                'limits' => spec_limits($first['lsl'] ?? $param['lsl'], $first['usl'] ?? $param['usl'], (int) $param['decimal_places'], $param['unit_symbol']),
                'method' => (string) ($param['method_label'] ?? ''),
                'cells'  => $cells,
            ];
        }
    }
    $totals = ['accepted' => 0, 'rejected' => 0, 'rework' => 0];
    foreach ($data['rounds'] as $round) {
        $totals['accepted'] += (int) $round['accepted_qty'];
        $totals['rejected'] += (int) $round['rejected_qty'];
        $totals['rework']   += (int) $round['rework_qty'];
    }

    return ['gridShifts' => $shifts, 'gridRows' => $rows, 'totals' => $totals];
}

function print_row(int $sr, array $param, ?array $obs): array
{
    $places = (int) $param['decimal_places'];
    $lsl    = $obs['lsl'] ?? $param['lsl'];
    $usl    = $obs['usl'] ?? $param['usl'];

    return [
        'sr'         => $sr,
        'name'       => $param['name'],
        'spec'       => (string) ($param['specification_text'] ?? ''),
        'lsl'        => $lsl === null ? '' : qms_decimal((string) $lsl, $places),
        'usl'        => $usl === null ? '' : qms_decimal((string) $usl, $places),
        'unit'       => (string) ($param['unit_symbol'] ?? ''),
        'methodId'   => $param['inspection_method_id'] === null ? null : (int) $param['inspection_method_id'],
        'method'     => (string) ($param['method_label'] ?? ''),
        'instrument' => (string) ($param['gauge_type_name'] ?? ''),
        'gauge'      => (string) ($obs['gauge_code'] ?? ''),
        'gaugeOk'    => $obs === null || $obs['gauge_cal_valid'] === null || (int) $obs['gauge_cal_valid'] === 1,
        'obsCount'   => (int) $param['observation_count'],
        'readings'   => print_readings($param, $obs),
        'result'     => $obs['result'] ?? null,
        'remarks'    => (string) ($obs['remarks'] ?? ''),
    ];
}

/** @return list<array{text: string, fail: bool}> */
function print_readings(array $param, ?array $obs): array
{
    $out = [];
    for ($n = 1; $n <= (int) $param['observation_count']; $n++) {
        $reading = $obs['readings'][$n] ?? null;
        $out[]   = ['text' => reading_text($param, $reading), 'fail' => ($reading['result'] ?? null) === 'FAIL'];
    }

    return $out;
}

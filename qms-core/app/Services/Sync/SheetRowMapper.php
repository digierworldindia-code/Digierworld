<?php

namespace App\Services\Sync;

use App\Enums\ReportStatus;
use App\Enums\WorkflowStage;
use App\Services\Inspections\InspectionService;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use App\Services\Workflow\WorkflowService;
use App\Core\Database;

/**
 * Builds Google Sheets rows from the CURRENT database state
 * (docs/architecture/08-google-sheets-integration.md §8.2).
 *
 * Column 1 is always the sync key used for de-duplication:
 *   Reports:      <report no>|R<revision>
 *   Observations: <report no>|R<revision>|<round>|<template parameter id>|<reading no>
 */
class SheetRowMapper
{
    public const REPORT_HEADERS = [
        'Sync Key', 'Report No', 'Revision', 'Report Type', 'Production Date', 'Shift', 'Part Number', 'Part Name', 'Machine',
        'Operator', 'Setter', 'Status', 'Overall Result', 'OOS Readings', 'Accepted', 'Rejected', 'Rework', 'Expired Gauge Used',
        'Submitted By', 'Submitted At', 'Production Verified By', 'Production Verified At', 'Quality Verified By',
        'Quality Verified At', 'QA Approved By', 'QA Approved At', 'Template / Version', 'Format Doc No / Rev', 'QMS ID', 'Synced At',
    ];

    public const OBSERVATION_HEADERS = [
        'Sync Key', 'Report No', 'Revision', 'Report Type', 'Production Date', 'Part Number', 'Machine', 'Shift', 'Round',
        'Inspection Time', 'Section', 'Parameter Code', 'Parameter', 'Specification', 'LSL', 'USL', 'Unit', 'Method',
        'Gauge ID', 'Gauge Calibration Valid', 'Reading No', 'Value', 'Result', 'Remarks',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly Clock $clock,
        private readonly InspectionService $inspections,
        private readonly WorkflowService $workflow,
    ) {
    }

    /**
     * @param array<string, mixed> $report
     */
    public function reportKey(array $report): string
    {
        return $report['report_no'] . '|R' . (int) $report['revision_no'];
    }

    /**
     * @return array{key: string, values: list<scalar|null>}
     */
    public function reportRow(int $reportId): array
    {
        $report    = $this->inspections->report($reportId);
        $approvals = $this->inspections->approvals($reportId);
        $signers   = [];
        foreach ($this->workflow->steps($report, $approvals) as $step) {
            $sig = $step['state'] === 'done' ? $step['signature'] : null;
            $signers[$step['key']] = [
                $sig === null ? '' : trim(($sig['full_name'] ?? $sig['username']) . ($sig['employee_code'] ? ' (' . $sig['employee_code'] . ')' : '')),
                $sig === null ? '' : $this->clock->toPlant($sig['signed_at'], 'Y-m-d H:i'),
            ];
        }

        $rounds = $this->db->table('inspection_rounds ro')
            ->select('ro.*, s.code AS shift_code, e.full_name AS operator_name')
            ->join('shifts s', 's.id = ro.shift_id', 'left')
            ->join('users u', 'u.id = ro.operator_user_id', 'left')
            ->join('employees e', 'e.id = u.employee_id', 'left')
            ->where('ro.report_id', $reportId)->orderBy('ro.round_no')->get()->getResultArray();
        $grid = $report['layout'] === 'SHIFT_GRID';

        $quantities = ['', '', ''];
        if ($grid) {
            $quantities = [0, 0, 0];
            foreach ($rounds as $round) {
                $quantities[0] += (int) $round['accepted_qty'];
                $quantities[1] += (int) $round['rejected_qty'];
                $quantities[2] += (int) $round['rework_qty'];
            }
        }
        $expired = $this->db->table('inspection_observations')->where('report_id', $reportId)
            ->where('gauge_id IS NOT NULL')->where('gauge_cal_valid', 0)->countAllResults() > 0;

        $shift    = $grid ? implode(', ', array_values(array_unique(array_filter(array_column($rounds, 'shift_code'))))) : (string) ($report['shift_code'] ?? '');
        $operator = $grid
            ? implode(', ', array_values(array_unique(array_filter(array_column($rounds, 'operator_name')))))
            : trim(($report['operator_name'] ?? '') . ($report['operator_code'] !== null ? ' (' . $report['operator_code'] . ')' : ''));
        $submitted = $signers['SUBMISSION'] ?? ['', ''];
        $stage     = static fn (WorkflowStage $s): array => $signers[$s->value] ?? ['', ''];

        $values = [
            $this->reportKey($report),
            (string) $report['report_no'],
            (int) $report['revision_no'],
            (string) $report['type_code'],
            (string) $report['inspection_date'],
            $shift,
            (string) $report['part_number'],
            (string) $report['part_name'],
            (string) $report['machine_code'],
            $operator,
            trim(($report['setter_name'] ?? '') . ($report['setter_code'] !== null ? ' (' . $report['setter_code'] . ')' : '')),
            ReportStatus::from($report['status'])->label(),
            (string) ($report['overall_result'] ?? ''),
            (int) $report['oos_count'],
            $quantities[0],
            $quantities[1],
            $quantities[2],
            $expired ? 'YES' : 'NO',
            $submitted[0],
            $submitted[1],
            ...$stage(WorkflowStage::Production),
            ...$stage(WorkflowStage::Quality),
            ...$stage(WorkflowStage::Qa),
            $report['template_code'] . ' v' . (int) $report['template_version'],
            $report['format_doc_no'] . ' Rev ' . $report['format_rev_no'],
            (int) $report['id'],
            $this->clock->toPlant($this->clock->nowUtcString(), 'Y-m-d H:i'),
        ];

        return ['key' => $values[0], 'values' => $values];
    }

    /**
     * One row per reading of a final report. $mode: ALL or OOS_ONLY.
     *
     * @return list<array{key: string, values: list<scalar|null>}>
     */
    public function observationRows(int $reportId, string $mode): array
    {
        $report = $this->inspections->report($reportId);
        $rows   = $this->db->table('inspection_readings rd')
            ->select('rd.*, o.lsl, o.usl, o.remarks, o.gauge_cal_valid, o.template_parameter_id, ro.round_no, ro.inspection_time,
                      s.code AS shift_code, g.gauge_code, tp.name, tp.specification_text, tp.observation_type, tp.decimal_places,
                      ip.code AS parameter_code, ts.title AS section_title, ts.sort_order AS section_sort, tp.sort_order AS param_sort,
                      u.symbol AS unit_symbol, m.short_label AS method_label')
            ->join('inspection_observations o', 'o.id = rd.observation_id')
            ->join('inspection_rounds ro', 'ro.id = o.round_id')
            ->join('shifts s', 's.id = ro.shift_id', 'left')
            ->join('gauges g', 'g.id = o.gauge_id', 'left')
            ->join('template_parameters tp', 'tp.id = o.template_parameter_id')
            ->join('template_sections ts', 'ts.id = tp.section_id')
            ->join('inspection_parameters ip', 'ip.id = tp.parameter_id')
            ->join('units u', 'u.id = tp.unit_id', 'left')
            ->join('inspection_methods m', 'm.id = tp.inspection_method_id', 'left')
            ->where('o.report_id', $reportId)
            ->where('rd.result IS NOT NULL')
            ->orderBy('ro.round_no')->orderBy('ts.sort_order')->orderBy('tp.sort_order')->orderBy('rd.reading_no')
            ->get()->getResultArray();

        $base = $this->reportKey($report);
        $out  = [];
        foreach ($rows as $row) {
            if ($mode === 'OOS_ONLY' && $row['result'] !== 'FAIL') {
                continue;
            }
            $key   = $base . '|' . (int) $row['round_no'] . '|' . (int) $row['template_parameter_id'] . '|' . (int) $row['reading_no'];
            $out[] = ['key' => $key, 'values' => [
                $key,
                (string) $report['report_no'],
                (int) $report['revision_no'],
                (string) $report['type_code'],
                (string) $report['inspection_date'],
                (string) $report['part_number'],
                (string) $report['machine_code'],
                $report['layout'] === 'SHIFT_GRID' ? (string) ($row['shift_code'] ?? '') : (string) ($report['shift_code'] ?? ''),
                (int) $row['round_no'],
                $row['inspection_time'] === null ? '' : substr((string) $row['inspection_time'], 0, 5),
                (string) $row['section_title'],
                (string) $row['parameter_code'],
                (string) $row['name'],
                (string) ($row['specification_text'] ?? ''),
                $this->number($row['lsl']),
                $this->number($row['usl']),
                $row['observation_type'] === 'PERCENTAGE' ? '%' : (string) ($row['unit_symbol'] ?? ''),
                (string) ($row['method_label'] ?? ''),
                (string) ($row['gauge_code'] ?? ''),
                $row['gauge_cal_valid'] === null ? '' : ((int) $row['gauge_cal_valid'] === 1 ? 'YES' : 'NO'),
                (int) $row['reading_no'],
                $this->value($row),
                (string) $row['result'],
                (string) ($row['remarks'] ?? ''),
            ]];
        }

        return $out;
    }

    /**
     * Numbers go to Sheets as numbers (so they can be charted); everything else as text.
     *
     * @param array<string, mixed> $row
     */
    private function value(array $row): string|float
    {
        if ($row['value_numeric'] !== null) {
            return (float) $row['value_numeric'];
        }

        return match (true) {
            $row['value_choice'] !== null => str_replace('_', ' ', (string) $row['value_choice']),
            $row['value_date'] !== null   => (string) $row['value_date'],
            $row['value_time'] !== null   => substr((string) $row['value_time'], 0, 5),
            default                       => (string) ($row['value_text'] ?? ''),
        };
    }

    private function number(?string $decimal): string|float
    {
        return $decimal === null ? '' : (float) $decimal;
    }
}

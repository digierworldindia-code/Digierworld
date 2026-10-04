<?php

namespace App\Services\Print;

use App\Enums\ReportStatus;
use App\Services\Files\UploadService;
use App\Services\Inspections\InspectionService;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use App\Services\Workflow\WorkflowService;
use App\Core\Database;

/**
 * Everything an A4 printout (HTML or PDF) needs, laid out like the traditional
 * paper formats: sectioned Setup Change Approval, method-matrix Product
 * Parameter Inspection and the shift x inspection-time In-Process grid.
 */
class PrintViewModelBuilder
{
    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly Clock $clock,
        private readonly InspectionService $inspections,
        private readonly WorkflowService $workflow,
        private readonly UploadService $uploads,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function build(int $reportId, array $user, bool $embedLogo): array
    {
        $data   = $this->inspections->load($reportId, $user);
        $report = $data['report'];
        $status = ReportStatus::from($report['status']);

        $ref = $report['report_no'] !== null
            ? $report['report_no'] . ((int) $report['revision_no'] > 0 ? ' Rev ' . $report['revision_no'] : '')
            : $report['type_code'] . ' draft #' . $report['id'];

        $model = [
            'report'      => $report,
            'ref'         => $ref,
            'filename'    => preg_replace('/[^A-Za-z0-9_-]+/', '_', $ref) . '.pdf',
            'layout'      => $report['layout'],
            'orientation' => $report['layout'] === 'SHIFT_GRID' ? 'L' : 'P',
            'company'     => [
                'name'    => $this->settings->string('company.name', 'Company'),
                'address' => $this->settings->string('company.address'),
                'logo'    => $this->logo($embedLogo),
            ],
            'watermark'   => match ($status) {
                ReportStatus::QaApproved => null,
                ReportStatus::Superseded => 'SUPERSEDED',
                ReportStatus::Cancelled  => 'CANCELLED',
                ReportStatus::Rejected   => 'REJECTED',
                ReportStatus::Draft      => 'DRAFT',
                ReportStatus::Returned   => 'RETURNED',
                default                  => 'NOT APPROVED',
            },
            'steps'       => $this->workflow->steps($report, $data['approvals']),
            'approvals'   => $data['approvals'],
            'printedBy'   => trim(($user['full_name'] ?? $user['username']) . ($user['employee_code'] ? ' (' . $user['employee_code'] . ')' : '')),
            'printedAt'   => $this->clock->toPlant($this->clock->nowUtcString(), 'd-m-Y H:i'),
            'times'       => [
                'received' => $report['received_at'] === null ? '' : $this->clock->toPlant($report['received_at'], 'H:i'),
                'finish'   => $report['finish_at'] === null ? '' : $this->clock->toPlant($report['finish_at'], 'H:i'),
            ],
        ];

        if ($report['layout'] === 'SHIFT_GRID') {
            return $model + $this->grid($data);
        }

        return $model + $this->sheet($data);
    }

    /**
     * Sectioned / method-matrix layouts: one round, one row per parameter.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sheet(array $data): array
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
                $obs    = $round['observations'][(int) $param['id']] ?? null;
                $rows[] = $this->row(++$sr, $param, $obs);
            }
            $sections[] = ['title' => $section['title'], 'rows' => $rows];
        }

        $methodColumns = $methods === [] ? [] : $this->db->table('inspection_methods')->select('id, short_label, name')
            ->whereIn('id', array_keys($methods))->orderBy('sort_order')->orderBy('id')->get()->getResultArray();

        return [
            'sections'      => $sections,
            'maxObs'        => $maxObs,
            'methodColumns' => $methodColumns,
            'round'         => $round,
        ];
    }

    /**
     * In-Process grid: parameters down, shifts x inspection times across.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function grid(array $data): array
    {
        $planned = (int) ($data['report']['planned_rounds_per_shift'] ?? 0);
        $byShift = [];
        foreach ($data['rounds'] as $round) {
            $byShift[(int) $round['shift_id']][] = $round;
        }

        $shifts = [];
        foreach ($data['shifts'] as $shift) {
            $rounds = $byShift[(int) $shift['id']] ?? [];
            $count  = max(count($rounds), $planned, 1);
            $shifts[] = ['shift' => $shift, 'columns' => array_pad($rounds, $count, null)];
            unset($byShift[(int) $shift['id']]);
        }
        foreach ($byShift as $rounds) {
            // Rounds of a shift that has since been retired still print.
            $shifts[] = ['shift' => ['code' => $rounds[0]['shift_code'] ?? '?', 'name' => ''], 'columns' => $rounds];
        }

        $rows = [];
        $sr   = 0;
        foreach ($data['structure'] as $section) {
            foreach ($section['parameters'] as $param) {
                $cells = [];
                foreach ($data['rounds'] as $round) {
                    $obs = $round['observations'][(int) $param['id']] ?? null;
                    $cells[(int) $round['id']] = $this->readings($param, $obs);
                }
                $first  = $data['rounds'][0]['observations'][(int) $param['id']] ?? null;
                $rows[] = [
                    'sr'      => ++$sr,
                    'section' => $section['title'],
                    'name'    => $param['name'],
                    'spec'    => (string) ($param['specification_text'] ?? ''),
                    'limits'  => spec_limits($first['lsl'] ?? $param['lsl'], $first['usl'] ?? $param['usl'], (int) $param['decimal_places'], $param['unit_symbol']),
                    'method'  => (string) ($param['method_label'] ?? ''),
                    'cells'   => $cells,
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

    /**
     * @param array<string, mixed>      $param
     * @param array<string, mixed>|null $obs
     *
     * @return array<string, mixed>
     */
    private function row(int $sr, array $param, ?array $obs): array
    {
        $places = (int) $param['decimal_places'];

        return [
            'sr'        => $sr,
            'name'      => $param['name'],
            'spec'      => (string) ($param['specification_text'] ?? ''),
            'lsl'       => ($obs['lsl'] ?? $param['lsl']) === null ? '' : qms_decimal((string) ($obs['lsl'] ?? $param['lsl']), $places),
            'usl'       => ($obs['usl'] ?? $param['usl']) === null ? '' : qms_decimal((string) ($obs['usl'] ?? $param['usl']), $places),
            'unit'      => (string) ($param['unit_symbol'] ?? ''),
            'methodId'  => $param['inspection_method_id'] === null ? null : (int) $param['inspection_method_id'],
            'method'    => (string) ($param['method_label'] ?? ''),
            'instrument' => (string) ($param['gauge_type_name'] ?? ''),
            'gauge'     => (string) ($obs['gauge_code'] ?? ''),
            'gaugeOk'   => $obs === null || $obs['gauge_cal_valid'] === null || (int) $obs['gauge_cal_valid'] === 1,
            'obsCount'  => (int) $param['observation_count'],
            'readings'  => $this->readings($param, $obs),
            'result'    => $obs['result'] ?? null,
            'remarks'   => (string) ($obs['remarks'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>      $param
     * @param array<string, mixed>|null $obs
     *
     * @return list<array{text: string, fail: bool}>
     */
    private function readings(array $param, ?array $obs): array
    {
        $out = [];
        for ($n = 1; $n <= (int) $param['observation_count']; $n++) {
            $reading = $obs['readings'][$n] ?? null;
            $out[]   = ['text' => reading_text($param, $reading), 'fail' => ($reading['result'] ?? null) === 'FAIL'];
        }

        return $out;
    }

    /**
     * Logo as data URI (PDF: no network or file access needed) or the media URL.
     */
    private function logo(bool $embed): ?string
    {
        $name = $this->settings->string('company.logo');
        if ($name === '') {
            return null;
        }
        if (! $embed) {
            return site_url('media/logo');
        }
        $file = $this->uploads->path('logo', $name);

        return $file === null ? null : 'data:image/png;base64,' . base64_encode((string) file_get_contents($file));
    }
}

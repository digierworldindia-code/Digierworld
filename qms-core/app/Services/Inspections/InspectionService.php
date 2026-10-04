<?php

namespace App\Services\Inspections;

use App\Enums\ReportStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ConcurrencyException;
use App\Exceptions\NotFoundException;
use App\Exceptions\RecordLockedException;
use App\Exceptions\ValidationException;
use App\Libraries\Uuid;
use App\Services\Access\AuthorizationService;
use App\Services\Audit\AuditService;
use App\Services\Auth\AuthService;
use App\Services\Gauges\GaugeService;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use App\Services\Support\TransactionRunner;
use App\Services\Templates\TemplateResolver;
use App\Core\Database;

/**
 * Inspection entry: start, load, autosave, IPR rounds, edit/view policy.
 * Submission and approvals live in WorkflowService.
 *
 * Every value is validated and evaluated here (SpecEvaluator); LSL/USL always
 * come from the template snapshot on the observation, never from the client.
 */
class InspectionService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly AuthorizationService $authz,
        private readonly AuditService $audit,
        private readonly Clock $clock,
        private readonly TransactionRunner $tx,
        private readonly SettingsService $settings,
        private readonly TemplateResolver $resolver,
        private readonly SpecEvaluator $evaluator,
        private readonly ProductionCalendar $calendar,
        private readonly GaugeService $gauges,
    ) {
    }

    public function evaluator(): SpecEvaluator
    {
        return $this->evaluator;
    }

    // ================================================================ start

    /**
     * @return array<string, mixed>
     */
    public function wizardOptions(): array
    {
        $current = $this->calendar->current();

        return [
            'reportTypes' => $this->db->table('report_types rt')
                ->select("rt.*, (SELECT COUNT(*) FROM inspection_templates t WHERE t.report_type_id = rt.id AND t.status = 'PUBLISHED') AS template_count", false)
                ->where('rt.is_active', 1)->orderBy('rt.id')->get()->getResultArray(),
            'parts'       => $this->db->table('parts')->select('id, part_number, part_name')->where('is_active', 1)->orderBy('part_number')->get()->getResultArray(),
            'shifts'      => $this->calendar->shifts(),
            'current'     => $current,
            'dates'       => $this->calendar->allowedDates(),
        ];
    }

    /**
     * Machines on which the part can be inspected for the report type (a template applies).
     *
     * @return list<array<string, mixed>>
     */
    public function machineOptions(int $reportTypeId, int $partId): array
    {
        return array_map(static fn (array $m): array => [
            'id' => (int) $m['id'], 'code' => $m['machine_code'], 'name' => $m['machine_name'],
            'template' => $m['template_code'], 'ambiguous' => $m['ambiguous'],
        ], $this->resolver->machinesFor($reportTypeId, $partId));
    }

    /**
     * Creates a draft (or returns the existing one for the same tablet request /
     * the open In-Process day sheet of the part + machine + date).
     *
     * @param array<string, mixed> $input client_uuid, report_type_id, part_id, machine_id, inspection_date, shift_id
     * @param array<string, mixed> $user
     */
    public function start(array $input, array $user): int
    {
        $uuid = strtolower(trim((string) ($input['client_uuid'] ?? '')));
        if ($uuid === '') {
            $uuid = Uuid::v4();
        } elseif (! Uuid::isV4($uuid)) {
            throw ValidationException::single('client_uuid', 'Invalid request. Reload the page and try again.');
        }

        $existing = $this->db->table('inspection_reports')->select('id, created_by')->where('client_uuid', $uuid)->get(1)->getRowArray();
        if ($existing !== null) {
            if ((int) $existing['created_by'] !== (int) $user['id']) {
                throw new AuthorizationException('This request belongs to another user.');
            }

            return (int) $existing['id'];
        }

        $errors = [];
        $type   = $this->db->table('report_types')->where('id', (int) ($input['report_type_id'] ?? 0))->where('is_active', 1)->get(1)->getRowArray();
        $part   = $this->db->table('parts')->where('id', (int) ($input['part_id'] ?? 0))->where('is_active', 1)->get(1)->getRowArray();
        $machine = $this->db->table('machines')->where('id', (int) ($input['machine_id'] ?? 0))->where('is_active', 1)->get(1)->getRowArray();
        if ($type === null) {
            $errors['report_type_id'] = 'Choose a report type.';
        }
        if ($part === null) {
            $errors['part_id'] = 'Choose a part.';
        }
        if ($machine === null) {
            $errors['machine_id'] = 'Choose a machine.';
        }
        $date = (string) ($input['inspection_date'] ?? '');
        if (! $this->calendar->isAllowedDate($date)) {
            $errors['inspection_date'] = 'Choose today\'s production date (back-dating is limited by the administrator).';
        }
        $shiftId = null;
        if ($type !== null && $type['header_shift'] !== 'HIDDEN') {
            $shiftId = (int) ($input['shift_id'] ?? 0);
            if ($shiftId === 0 && $type['header_shift'] === 'REQUIRED') {
                $errors['shift_id'] = 'Choose the shift.';
            } elseif ($shiftId !== 0 && ! in_array($shiftId, array_map('intval', array_column($this->calendar->shifts(), 'id')), true)) {
                $errors['shift_id'] = 'Choose a valid shift.';
            }
            $shiftId = $shiftId === 0 ? null : $shiftId;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $template = $this->resolver->resolve((int) $type['id'], (int) $part['id'], (int) $machine['id']);
        if ($template === null) {
            throw ValidationException::single('template', "No published {$type['name']} template applies to part {$part['part_number']} on machine {$machine['machine_code']}. Ask the QA Admin to map one.");
        }

        return $this->tx->run(function (Database $db) use ($uuid, $type, $part, $machine, $date, $shiftId, $template, $user): int {
            if ($type['layout'] === 'SHIFT_GRID') {
                // Serialise day-sheet creation per machine, then reuse the open sheet.
                $db->query('SELECT id FROM machines WHERE id = ? FOR UPDATE', [(int) $machine['id']]);
                $open = $db->table('inspection_reports')->select('id')
                    ->where('report_type_id', $type['id'])->where('part_id', $part['id'])->where('machine_id', $machine['id'])
                    ->where('inspection_date', $date)->where('revision_no', 0)->where('status !=', 'CANCELLED')
                    ->get(1)->getRowArray();
                if ($open !== null) {
                    return (int) $open['id'];
                }
            }

            $now = $this->clock->nowUtcString();
            $row = [
                'client_uuid'          => $uuid,
                'report_type_id'       => (int) $type['id'],
                'template_id'          => (int) $template['id'],
                'part_id'              => (int) $part['id'],
                'machine_id'           => (int) $machine['id'],
                'shift_id'             => $type['layout'] === 'SHIFT_GRID' ? null : $shiftId,
                'inspection_date'      => $date,
                // Minute precision, like the HH:MM finish time entered later.
                'received_at'          => $type['header_timing'] !== 'HIDDEN' ? substr($now, 0, 16) . ':00' : null,
                'operator_employee_id' => $type['header_operator'] !== 'HIDDEN' ? $user['employee_id'] : null,
                'status'               => ReportStatus::Draft->value,
                'overall_result'       => 'INCOMPLETE',
                'created_at'           => $now,
                'created_by'           => $user['id'],
                'updated_at'           => $now,
                'updated_by'           => $user['id'],
            ];
            $db->table('inspection_reports')->insert($row);
            $reportId = (int) $db->insertID();

            if ($type['layout'] !== 'SHIFT_GRID') {
                $this->createRound($reportId, null, null, $user);
            }
            $this->refreshTotals($reportId);
            $this->audit->log('CREATE', 'inspection', $reportId, null, [
                'type' => $type['code'], 'template' => $template['template_code'] . ' v' . $template['version'],
                'part' => $part['part_number'], 'machine' => $machine['machine_code'], 'date' => $date,
            ], "{$type['code']} draft #{$reportId}");

            return $reportId;
        });
    }

    // ================================================================ load

    /**
     * Report header with everything needed to display it.
     *
     * @return array<string, mixed>
     */
    public function report(int $reportId): array
    {
        $row = $this->db->table('inspection_reports r')
            ->select('r.*, rt.code AS type_code, rt.name AS type_name, rt.layout, rt.header_shift, rt.header_operator, rt.header_setter, rt.header_timing,
                      rt.requires_production_verification, rt.requires_quality_verification, rt.requires_qa_approval,
                      t.template_code, t.version AS template_version, t.name AS template_name, t.format_doc_no, t.format_rev_no,
                      t.format_made_date, t.format_rev_date, t.planned_rounds_per_shift,
                      p.part_number, p.part_name, p.drawing_number, p.drawing_revision,
                      m.machine_code, m.machine_name, m.pm_due_date,
                      s.code AS shift_code, s.name AS shift_name,
                      op.employee_code AS operator_code, op.full_name AS operator_name, op.skill_level AS operator_skill,
                      st.employee_code AS setter_code, st.full_name AS setter_name,
                      cu.username AS created_by_name, su.username AS submitted_by_name, au.username AS approved_by_name')
            ->join('report_types rt', 'rt.id = r.report_type_id')
            ->join('inspection_templates t', 't.id = r.template_id')
            ->join('parts p', 'p.id = r.part_id')
            ->join('machines m', 'm.id = r.machine_id')
            ->join('shifts s', 's.id = r.shift_id', 'left')
            ->join('employees op', 'op.id = r.operator_employee_id', 'left')
            ->join('employees st', 'st.id = r.setter_employee_id', 'left')
            ->join('users cu', 'cu.id = r.created_by', 'left')
            ->join('users su', 'su.id = r.submitted_by', 'left')
            ->join('users au', 'au.id = r.approved_by', 'left')
            ->where('r.id', $reportId)->get(1)->getRowArray();

        return $row ?? throw new NotFoundException('Inspection report not found.');
    }

    /**
     * Full view model for the entry, detail and print screens.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function load(int $reportId, array $user): array
    {
        $report = $this->report($reportId);
        if (! $this->canView($report, $user)) {
            $this->authz->denied('inspection.view', 'You may not open this inspection report.');
        }

        $structure = service('templates')->structure((int) $report['template_id']);
        $rounds    = $this->rounds($reportId);
        $gaugeTypes = [];
        foreach ($structure as $section) {
            foreach ($section['parameters'] as $param) {
                if ($param['gauge_type_id'] !== null) {
                    $gaugeTypes[(int) $param['gauge_type_id']] = true;
                }
            }
        }
        $gaugeOptions = [];
        foreach (array_keys($gaugeTypes) as $gaugeTypeId) {
            $gaugeOptions[$gaugeTypeId] = $this->gauges->optionsForType($gaugeTypeId, (string) $report['inspection_date']);
        }

        return [
            'report'       => $report,
            'structure'    => $structure,
            'rounds'       => $rounds,
            'gaugeOptions' => $gaugeOptions,
            'approvals'    => $this->approvals($reportId),
            'shifts'       => $this->calendar->shifts(),
            'employees'    => $this->db->table('employees')->select('id, employee_code, full_name, skill_level')->where('is_active', 1)->orderBy('full_name')->get()->getResultArray(),
            'canEdit'      => $this->canEdit($report, $user),
            'revisions'    => $this->db->table('inspection_reports')->select('id, revision_no, status, is_current')
                ->where('report_no', $report['report_no'] ?? '#none')->orderBy('revision_no')->get()->getResultArray(),
        ];
    }

    /**
     * Rounds with observations keyed by template parameter and readings keyed by reading number.
     *
     * @return list<array<string, mixed>>
     */
    public function rounds(int $reportId): array
    {
        $rounds = $this->db->table('inspection_rounds ro')
            ->select('ro.*, s.code AS shift_code, s.sort_order AS shift_sort, ou.username AS operator_username, oe.full_name AS operator_name,
                      qu.username AS qe_username, qe.full_name AS qe_name, cu.username AS created_by_name')
            ->join('shifts s', 's.id = ro.shift_id', 'left')
            ->join('users ou', 'ou.id = ro.operator_user_id', 'left')
            ->join('employees oe', 'oe.id = ou.employee_id', 'left')
            ->join('users qu', 'qu.id = ro.qe_user_id', 'left')
            ->join('employees qe', 'qe.id = qu.employee_id', 'left')
            ->join('users cu', 'cu.id = ro.created_by', 'left')
            ->where('ro.report_id', $reportId)
            ->orderBy('s.sort_order')->orderBy('ro.inspection_time')->orderBy('ro.round_no')
            ->get()->getResultArray();
        if ($rounds === []) {
            return [];
        }

        $observations = $this->db->table('inspection_observations o')
            ->select('o.*, g.gauge_code')
            ->join('gauges g', 'g.id = o.gauge_id', 'left')
            ->where('o.report_id', $reportId)->get()->getResultArray();
        $readings = $observations === [] ? [] : $this->db->table('inspection_readings')
            ->whereIn('observation_id', array_column($observations, 'id'))->get()->getResultArray();

        $readingsByObs = [];
        foreach ($readings as $reading) {
            $readingsByObs[$reading['observation_id']][(int) $reading['reading_no']] = $reading;
        }
        $obsByRound = [];
        foreach ($observations as $obs) {
            $obs['readings'] = $readingsByObs[$obs['id']] ?? [];
            $obsByRound[$obs['round_id']][(int) $obs['template_parameter_id']] = $obs;
        }
        foreach ($rounds as &$round) {
            $round['observations'] = $obsByRound[$round['id']] ?? [];
        }

        return $rounds;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function approvals(int $reportId): array
    {
        return $this->db->table('inspection_approvals a')
            ->select('a.*, u.username, e.full_name, e.employee_code, e.designation, r.name AS role_name')
            ->join('users u', 'u.id = a.user_id')
            ->join('employees e', 'e.id = u.employee_id', 'left')
            ->join('roles r', 'r.id = a.role_id')
            ->where('a.report_id', $reportId)
            ->orderBy('a.signed_at')->orderBy('a.id')
            ->get()->getResultArray();
    }

    // ================================================================ policy

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $user
     */
    public function canView(array $report, array $user): bool
    {
        if ($this->authz->userCan($user, 'inspection.view_all')) {
            return true;
        }
        $uid = (int) $user['id'];
        if ($this->authz->userCan($user, 'inspection.view_own')
            && ((int) $report['created_by'] === $uid || (int) ($report['submitted_by'] ?? 0) === $uid
                || $this->db->table('inspection_rounds')->where('report_id', $report['id'])
                    ->groupStart()->where('operator_user_id', $uid)->orWhere('created_by', $uid)->groupEnd()->countAllResults() > 0)) {
            return true;
        }

        // The open In-Process day sheet is shared by the operators of all shifts.
        return $report['layout'] === 'SHIFT_GRID' && $report['status'] === 'DRAFT' && $this->authz->userCan($user, 'inspection.create');
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $user
     */
    public function canEdit(array $report, array $user): bool
    {
        if (! $this->authz->userCan($user, 'inspection.create')) {
            return false;
        }
        $uid = (int) $user['id'];

        return match ($report['status']) {
            'DRAFT'    => $report['layout'] === 'SHIFT_GRID' || (int) $report['created_by'] === $uid,
            'RETURNED' => (int) $report['created_by'] === $uid || (int) ($report['submitted_by'] ?? 0) === $uid,
            default    => false,
        };
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $round
     * @param array<string, mixed> $user
     */
    public function canEditRound(array $report, array $round, array $user): bool
    {
        if (! $this->canEdit($report, $user) || $round['status'] !== 'OPEN') {
            return false;
        }

        return $report['layout'] !== 'SHIFT_GRID'
            || (int) $round['created_by'] === (int) $user['id']
            || (int) ($round['operator_user_id'] ?? 0) === (int) $user['id']
            || $report['status'] === 'RETURNED';
    }

    // ================================================================ autosave

    /**
     * Saves changed cells / gauges / remarks / round details / header in one transaction.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function saveDraft(int $reportId, array $payload, array $user): array
    {
        return $this->tx->run(function (Database $db) use ($reportId, $payload, $user): array {
            $db->query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
            $report = $this->report($reportId);
            if (! ReportStatus::from($report['status'])->isEditable()) {
                throw new RecordLockedException('This report has been submitted and can no longer be changed.');
            }
            if (! $this->canEdit($report, $user)) {
                throw new AuthorizationException('You may not edit this report.');
            }

            $changes  = [];
            $touched  = [];
            $errors   = [];
            $params   = $this->templateParameters((int) $report['template_id']);
            $rounds   = [];
            foreach ($db->table('inspection_rounds')->where('report_id', $reportId)->get()->getResultArray() as $round) {
                $rounds[(int) $round['id']] = $round;
            }

            if (isset($payload['header']) && is_array($payload['header'])) {
                $changes['header'] = $this->saveHeader($report, $payload['header'], (int) ($payload['lock_version'] ?? -1), $user);
                $report            = $this->report($reportId);
            }

            foreach ((array) ($payload['observations'] ?? []) as $item) {
                $obs = $this->observationFor($reportId, (int) ($item['observation_id'] ?? 0));
                $this->assertRoundEditable($report, $rounds[(int) $obs['round_id']] ?? null, $user);
                $param  = $params[(int) $obs['template_parameter_id']];
                $update = [];
                if (array_key_exists('gauge_id', $item)) {
                    $update += $this->gaugeFields($param, $item['gauge_id'], (string) $report['inspection_date'], $obs['id'], $errors);
                }
                if (array_key_exists('remarks', $item)) {
                    $remarks = trim((string) $item['remarks']);
                    if (mb_strlen($remarks) > 500) {
                        $errors['obs-' . $obs['id']] = 'Remarks are limited to 500 characters.';
                        continue;
                    }
                    $update['remarks'] = $remarks === '' ? null : $remarks;
                }
                if ($update !== []) {
                    $db->table('inspection_observations')->where('id', $obs['id'])->update($update + ['updated_at' => $this->clock->nowUtcString(), 'updated_by' => $user['id']]);
                    $changes['observations'][] = ['observation' => (int) $obs['id'], 'parameter' => $param['name']] + $update;
                }
            }

            foreach ((array) ($payload['cells'] ?? []) as $cell) {
                $obs = $this->observationFor($reportId, (int) ($cell['observation_id'] ?? 0));
                $this->assertRoundEditable($report, $rounds[(int) $obs['round_id']] ?? null, $user);
                $param = $params[(int) $obs['template_parameter_id']];
                $no    = (int) ($cell['reading_no'] ?? 0);
                if ($no < 1 || $no > (int) $param['observation_count']) {
                    $errors["cell-{$obs['id']}-{$no}"] = 'Unknown observation number.';
                    continue;
                }
                try {
                    $value = $this->evaluator->evaluate(['lsl' => $obs['lsl'], 'usl' => $obs['usl']] + $param, isset($cell['value']) ? (string) $cell['value'] : null, (string) $report['inspection_date']);
                } catch (ValidationException $e) {
                    $errors["cell-{$obs['id']}-{$no}"] = $param['name'] . ': ' . $e->getMessage();
                    continue;
                }
                $old = $this->writeReading((int) $obs['id'], $no, $value, $user);
                $touched[(int) $obs['id']] = true;
                $changes['cells'][] = ['parameter' => $param['name'], 'round' => (int) $obs['round_id'], 'reading' => $no, 'old' => $old, 'new' => $this->displayValue($value)];
            }

            foreach ((array) ($payload['rounds'] ?? []) as $item) {
                $round = $rounds[(int) ($item['round_id'] ?? 0)] ?? throw new NotFoundException('Inspection round not found.');
                $this->assertRoundEditable($report, $round, $user);
                $update = $this->roundFields($item, $errors, (int) $round['id']);
                if ($update !== []) {
                    $db->table('inspection_rounds')->where('id', $round['id'])->update($update + ['updated_at' => $this->clock->nowUtcString(), 'updated_by' => $user['id']]);
                    $changes['rounds'][] = ['round' => (int) $round['round_no']] + $update;
                }
            }

            foreach (array_keys($touched) as $obsId) {
                $this->refreshObservation($obsId, $params);
            }
            $totals = $this->refreshTotals($reportId);

            if ($changes !== []) {
                $this->audit->log('SAVE_DRAFT', 'inspection', $reportId, null, $changes, $report['report_no'] ?? "{$report['type_code']} draft #{$reportId}");
            }

            return [
                'ok'             => $errors === [],
                'errors'         => $errors,
                'lock_version'   => (int) $db->table('inspection_reports')->select('lock_version')->where('id', $reportId)->get(1)->getRow('lock_version'),
                'overall_result' => $totals['overall_result'],
                'oos_count'      => $totals['oos_count'],
                'observations'   => $this->observationStates($reportId),
                'problems'       => $this->submissionProblems($this->report($reportId)),
                'saved_at'       => $this->clock->toPlant($this->clock->nowUtcString(), 'H:i'),
            ];
        });
    }

    // ================================================================ rounds (In-Process)

    /**
     * @param array<string, mixed> $user
     */
    public function addRound(int $reportId, int $shiftId, string $time, array $user): int
    {
        return $this->tx->run(function (Database $db) use ($reportId, $shiftId, $time, $user): int {
            $db->query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
            $report = $this->report($reportId);
            if ($report['layout'] !== 'SHIFT_GRID') {
                throw ValidationException::single('round', 'Only In-Process reports have inspection times.');
            }
            if (! $this->canEdit($report, $user)) {
                throw new AuthorizationException('You may not add inspections to this report.');
            }
            if (! in_array($shiftId, array_map('intval', array_column($this->calendar->shifts(), 'id')), true)) {
                throw ValidationException::single('shift_id', 'Choose a valid shift.');
            }
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw ValidationException::single('inspection_time', 'Enter the inspection time as HH:MM.');
            }
            $roundId = $this->createRound($reportId, $shiftId, $time . ':00', $user);
            $this->refreshTotals($reportId);
            $this->audit->log('ADD_ROUND', 'inspection', $reportId, null, ['shift_id' => $shiftId, 'time' => $time], $report['report_no'] ?? "IPR draft #{$reportId}");

            return $roundId;
        });
    }

    /**
     * Operator signature for an In-Process round ("Inspected By Operator").
     *
     * @param array<string, mixed> $user
     */
    public function signRound(int $reportId, int $roundId, array $user): void
    {
        $this->tx->run(function (Database $db) use ($reportId, $roundId, $user): void {
            $db->query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
            $report = $this->report($reportId);
            $round  = $this->roundFor($reportId, $roundId);
            $this->assertRoundEditable($report, $round, $user);

            $problems = $this->roundProblems($report, $round);
            if ($problems !== []) {
                throw new ValidationException(['round' => implode(' ', $problems)], 'This inspection cannot be signed yet: ' . implode(' ', $problems));
            }
            $now = $this->clock->nowUtcString();
            $db->table('inspection_rounds')->where('id', $roundId)->update([
                'status' => 'SIGNED', 'operator_user_id' => $user['id'], 'operator_signed_at' => $now, 'updated_at' => $now, 'updated_by' => $user['id'],
            ]);
            $this->audit->log('SIGN_ROUND', 'inspection', $reportId, ['round' => (int) $round['round_no'], 'status' => 'OPEN'],
                ['round' => (int) $round['round_no'], 'status' => 'SIGNED'], $report['report_no'] ?? "IPR draft #{$reportId}");
        });
    }

    /**
     * Quality engineer signature for an In-Process round ("Inspected By QE").
     *
     * @param array<string, mixed> $user
     */
    public function verifyRound(int $reportId, int $roundId, string $password, array $user): void
    {
        if ($this->settings->bool('workflow.reauth_on_sign', true)) {
            $this->auth->confirmPassword($password);
        }

        $this->tx->run(function (Database $db) use ($reportId, $roundId, $user): void {
            $db->query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
            $report = $this->report($reportId);
            $round  = $this->roundFor($reportId, $roundId);
            if (! ReportStatus::from($report['status'])->isEditable()) {
                throw new RecordLockedException('This report has been submitted.');
            }
            if ($round['status'] !== 'SIGNED') {
                throw ValidationException::single('round', 'Only an inspection signed by the operator can be verified.');
            }
            if ((int) $round['operator_user_id'] === (int) $user['id']) {
                throw new AuthorizationException('The quality engineer must be a different person from the operator who signed.');
            }
            $now = $this->clock->nowUtcString();
            $db->table('inspection_rounds')->where('id', $roundId)->update([
                'status' => 'VERIFIED', 'qe_user_id' => $user['id'], 'qe_verified_at' => $now, 'updated_at' => $now, 'updated_by' => $user['id'],
            ]);
            $this->audit->log('VERIFY_ROUND', 'inspection', $reportId, ['round' => (int) $round['round_no'], 'status' => 'SIGNED'],
                ['round' => (int) $round['round_no'], 'status' => 'VERIFIED'], $report['report_no'] ?? "IPR draft #{$reportId}");
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    public function reopenRound(int $reportId, int $roundId, string $reason, array $user): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('remarks', 'Give the reason for reopening.');
        }

        $this->tx->run(function (Database $db) use ($reportId, $roundId, $reason, $user): void {
            $db->query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
            $report = $this->report($reportId);
            $round  = $this->roundFor($reportId, $roundId);
            if (! ReportStatus::from($report['status'])->isEditable()) {
                throw new RecordLockedException('This report has been submitted.');
            }
            if ($round['status'] === 'OPEN') {
                return;
            }
            $db->table('inspection_rounds')->where('id', $roundId)->update([
                'status' => 'OPEN', 'qe_user_id' => null, 'qe_verified_at' => null,
                'updated_at' => $this->clock->nowUtcString(), 'updated_by' => $user['id'],
            ]);
            $this->audit->log('REOPEN_ROUND', 'inspection', $reportId, ['round' => (int) $round['round_no'], 'status' => $round['status']],
                ['round' => (int) $round['round_no'], 'status' => 'OPEN', 'reason' => mb_substr($reason, 0, 500)], $report['report_no'] ?? "IPR draft #{$reportId}");
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteRound(int $reportId, int $roundId, array $user): void
    {
        $this->tx->run(function (Database $db) use ($reportId, $roundId, $user): void {
            $db->query('SELECT id FROM inspection_reports WHERE id = ? FOR UPDATE', [$reportId]);
            $report = $this->report($reportId);
            $round  = $this->roundFor($reportId, $roundId);
            $this->assertRoundEditable($report, $round, $user);
            $filled = $db->table('inspection_readings rd')->join('inspection_observations o', 'o.id = rd.observation_id')
                ->where('o.round_id', $roundId)->where('rd.result IS NOT NULL')->countAllResults();
            if ($filled > 0) {
                throw ValidationException::single('round', 'This inspection already has readings. Clear them first.');
            }
            $db->table('inspection_rounds')->where('id', $roundId)->delete();
            $this->refreshTotals($reportId);
            $this->audit->log('DELETE_ROUND', 'inspection', $reportId, ['round' => (int) $round['round_no']], null, $report['report_no'] ?? "IPR draft #{$reportId}");
        });
    }

    // ================================================================ checks used by submission

    /**
     * Everything that prevents submission, in plain language.
     *
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    public function submissionProblems(array $report): array
    {
        $problems = [];
        if ($report['header_shift'] === 'REQUIRED' && $report['shift_id'] === null) {
            $problems[] = 'Choose the shift.';
        }
        if ($report['header_operator'] === 'REQUIRED' && $report['operator_employee_id'] === null) {
            $problems[] = 'Choose the operator.';
        }
        if ($report['header_setter'] === 'REQUIRED' && $report['setter_employee_id'] === null) {
            $problems[] = 'Choose the setter.';
        }
        if ($report['header_timing'] === 'REQUIRED' && ($report['received_at'] === null || $report['finish_at'] === null)) {
            $problems[] = 'Enter the received time and the finish time.';
        }

        $rounds = $this->db->table('inspection_rounds')->where('report_id', $report['id'])->orderBy('round_no')->get()->getResultArray();
        if ($rounds === []) {
            $problems[] = 'Add at least one inspection.';
        }
        foreach ($rounds as $round) {
            if ($report['layout'] === 'SHIFT_GRID' && $round['status'] === 'OPEN') {
                $problems[] = 'Inspection #' . $round['round_no'] . ' (' . substr((string) $round['inspection_time'], 0, 5) . ') is not signed by the operator.';
            }
            foreach ($this->roundProblems($report, $round) as $problem) {
                $problems[] = $problem;
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Missing mandatory readings, missing or invalid gauges in one round.
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $round
     *
     * @return list<string>
     */
    public function roundProblems(array $report, array $round): array
    {
        $params   = $this->templateParameters((int) $report['template_id']);
        $block    = $this->settings->bool('gauge.block_expired_on_submit', true);
        $problems = [];
        $prefix   = $report['layout'] === 'SHIFT_GRID' ? 'Inspection #' . $round['round_no'] . ': ' : '';

        $observations = $this->db->table('inspection_observations o')->select('o.*, g.gauge_code, g.status AS gauge_status')
            ->join('gauges g', 'g.id = o.gauge_id', 'left')->where('o.round_id', $round['id'])->get()->getResultArray();
        foreach ($observations as $obs) {
            $param = $params[(int) $obs['template_parameter_id']];
            if ($obs['result'] === 'INCOMPLETE') {
                $problems[] = $prefix . $param['name'] . ' is missing readings.';
            }
            if ((int) $param['gauge_required'] === 1 && $obs['gauge_id'] === null) {
                $problems[] = $prefix . $param['name'] . ' needs the Gauge ID.';
            }
            if ($obs['gauge_id'] !== null && (int) $obs['gauge_cal_valid'] === 0 && $block) {
                $problems[] = $prefix . 'Gauge ' . $obs['gauge_code'] . ' (' . $param['name'] . ') is not usable on the inspection date (calibration expired or gauge not active).';
            }
        }

        return $problems;
    }

    /**
     * Re-evaluates every stored reading against the specification snapshot of its
     * observation and refreshes observation results and totals. Called inside the
     * submit transaction as a defence in depth: the stored verdicts are recomputed
     * by the server from stored values only.
     *
     * @param array<string, mixed> $report
     *
     * @return array{overall_result: string, oos_count: int, corrected: int}
     */
    public function reevaluate(array $report): array
    {
        $params   = $this->templateParameters((int) $report['template_id']);
        $readings = $this->db->table('inspection_readings rd')
            ->select('rd.*, o.lsl, o.usl, o.template_parameter_id')
            ->join('inspection_observations o', 'o.id = rd.observation_id')
            ->where('o.report_id', $report['id'])->get()->getResultArray();

        $corrected = 0;
        foreach ($readings as $reading) {
            $param  = $params[(int) $reading['template_parameter_id']];
            $result = $this->evaluator->resultForStored($param, $reading, $reading['lsl'], $reading['usl'], (string) $report['inspection_date']);
            if ($result === null && $reading['result'] !== null) {
                // Value stored in a column that does not match the parameter type: never guess.
                throw new ValidationException(['submit' => $param['name'] . ': stored value does not match the parameter type.'], $param['name'] . ': a stored value is inconsistent. Re-enter it and submit again.');
            }
            if ($result !== $reading['result']) {
                $this->db->table('inspection_readings')->where('id', $reading['id'])->update(['result' => $result]);
                $corrected++;
            }
        }
        foreach ($this->db->table('inspection_observations')->select('id')->where('report_id', $report['id'])->get()->getResultArray() as $obs) {
            $this->refreshObservation((int) $obs['id'], $params);
        }
        if ($corrected > 0) {
            log_message('warning', 'Inspection {id}: {n} stored reading verdicts were corrected on submission.', ['id' => $report['id'], 'n' => $corrected]);
        }

        return $this->refreshTotals((int) $report['id']) + ['corrected' => $corrected];
    }

    // ================================================================ internals

    /**
     * Template parameters of a template version keyed by id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function templateParameters(int $templateId): array
    {
        static $cache = [];
        if (! isset($cache[$templateId])) {
            $rows = $this->db->table('template_parameters tp')->select('tp.*, s.sort_order AS section_sort')
                ->join('template_sections s', 's.id = tp.section_id')
                ->where('s.template_id', $templateId)
                ->orderBy('s.sort_order')->orderBy('tp.sort_order')->get()->getResultArray();
            $cache[$templateId] = array_column($rows, null, 'id');
        }

        return $cache[$templateId];
    }

    /**
     * Inserts a round with one observation per template parameter, applying pre-fills.
     *
     * @param array<string, mixed> $user
     */
    private function createRound(int $reportId, ?int $shiftId, ?string $time, array $user): int
    {
        $report = $this->report($reportId);
        $now    = $this->clock->nowUtcString();
        $next   = (int) ($this->db->table('inspection_rounds')->selectMax('round_no', 'm')->where('report_id', $reportId)->get()->getRow('m') ?? 0) + 1;

        $this->db->table('inspection_rounds')->insert([
            'report_id' => $reportId, 'round_no' => $next, 'shift_id' => $shiftId, 'inspection_time' => $time,
            'status' => 'OPEN', 'created_at' => $now, 'created_by' => $user['id'], 'updated_at' => $now,
        ]);
        $roundId = (int) $this->db->insertID();

        $params = $this->templateParameters((int) $report['template_id']);
        foreach ($params as $param) {
            $this->db->table('inspection_observations')->insert([
                'report_id'             => $reportId,
                'round_id'              => $roundId,
                'template_parameter_id' => $param['id'],
                'lsl'                   => $param['lsl'],
                'usl'                   => $param['usl'],
                'result'                => (int) $param['is_mandatory'] === 1 ? 'INCOMPLETE' : 'NOT_APPLICABLE',
                'created_at'            => $now,
                'created_by'            => $user['id'],
                'updated_at'            => $now,
            ]);
            $obsId   = (int) $this->db->insertID();
            $prefill = $this->prefillValue($param, $report);
            if ($prefill !== null) {
                $value = $this->evaluator->evaluate($param, $prefill, (string) $report['inspection_date']);
                $this->writeReading($obsId, 1, $value, $user);
                $this->refreshObservation($obsId, $params);
            }
        }

        return $roundId;
    }

    /**
     * @param array<string, mixed> $param
     * @param array<string, mixed> $report
     */
    private function prefillValue(array $param, array $report): ?string
    {
        return match ($param['prefill_source']) {
            'OPERATOR_SKILL_LEVEL' => $report['operator_skill'] !== null ? (string) $report['operator_skill'] : null,
            'MACHINE_PM_DUE_DATE'  => $report['pm_due_date'],
            default                => null,
        };
    }

    /**
     * Upserts one reading; returns the previous display value for the audit trail.
     *
     * @param array<string, string|null> $value
     * @param array<string, mixed>       $user
     */
    private function writeReading(int $observationId, int $readingNo, array $value, array $user): ?string
    {
        $now      = $this->clock->nowUtcString();
        $existing = $this->db->table('inspection_readings')->where('observation_id', $observationId)->where('reading_no', $readingNo)->get(1)->getRowArray();

        if ($existing === null) {
            if ($value['result'] === null) {
                return null;
            }
            $this->db->table('inspection_readings')->insert($value + [
                'observation_id' => $observationId, 'reading_no' => $readingNo, 'created_at' => $now, 'created_by' => $user['id'], 'updated_at' => $now,
            ]);

            return null;
        }

        $this->db->table('inspection_readings')->where('id', $existing['id'])->update($value + ['updated_at' => $now, 'updated_by' => $user['id']]);

        return $this->displayValue($existing);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function displayValue(array $value): ?string
    {
        foreach (['value_numeric', 'value_choice', 'value_text', 'value_date', 'value_time'] as $key) {
            if (($value[$key] ?? null) !== null) {
                return (string) $value[$key];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $params
     */
    private function refreshObservation(int $observationId, array $params): void
    {
        $obs      = $this->db->table('inspection_observations')->where('id', $observationId)->get(1)->getRowArray();
        $param    = $params[(int) $obs['template_parameter_id']];
        $results  = [];
        for ($n = 1; $n <= (int) $param['observation_count']; $n++) {
            $results[$n] = null;
        }
        foreach ($this->db->table('inspection_readings')->select('reading_no, result')->where('observation_id', $observationId)->get()->getResultArray() as $r) {
            $results[(int) $r['reading_no']] = $r['result'];
        }
        $result = $this->evaluator->aggregate($results, (int) $param['observation_count'], (int) $param['is_mandatory'] === 1);
        if ($result !== $obs['result']) {
            $this->db->table('inspection_observations')->where('id', $observationId)->update(['result' => $result]);
        }
    }

    /**
     * Recomputes overall_result and oos_count of a report (live while drafting).
     *
     * @return array{overall_result: string, oos_count: int}
     */
    public function refreshTotals(int $reportId): array
    {
        $results = array_column($this->db->table('inspection_observations')->select('result')->where('report_id', $reportId)->get()->getResultArray(), 'result');
        $overall = $results === [] ? 'INCOMPLETE' : $this->evaluator->overall($results);
        $oos     = $this->db->table('inspection_readings rd')->join('inspection_observations o', 'o.id = rd.observation_id')
            ->where('o.report_id', $reportId)->where('rd.result', 'FAIL')->countAllResults();

        $this->db->table('inspection_reports')->where('id', $reportId)->update(['overall_result' => $overall, 'oos_count' => $oos]);

        return ['overall_result' => $overall, 'oos_count' => $oos];
    }

    /**
     * @return array<int, array{result: string, readings: array<int, string|null>}>
     */
    private function observationStates(int $reportId): array
    {
        $out = [];
        foreach ($this->db->table('inspection_observations')->select('id, result')->where('report_id', $reportId)->get()->getResultArray() as $obs) {
            $out[(int) $obs['id']] = ['result' => $obs['result'], 'readings' => []];
        }
        $readings = $this->db->table('inspection_readings rd')->select('rd.observation_id, rd.reading_no, rd.result')
            ->join('inspection_observations o', 'o.id = rd.observation_id')->where('o.report_id', $reportId)->get()->getResultArray();
        foreach ($readings as $r) {
            $out[(int) $r['observation_id']]['readings'][(int) $r['reading_no']] = $r['result'];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function observationFor(int $reportId, int $observationId): array
    {
        return $this->db->table('inspection_observations')->where('id', $observationId)->where('report_id', $reportId)->get(1)->getRowArray()
            ?? throw new NotFoundException('Observation not found in this report.');
    }

    /**
     * @return array<string, mixed>
     */
    private function roundFor(int $reportId, int $roundId): array
    {
        return $this->db->table('inspection_rounds')->where('id', $roundId)->where('report_id', $reportId)->get(1)->getRowArray()
            ?? throw new NotFoundException('Inspection round not found.');
    }

    /**
     * @param array<string, mixed>      $report
     * @param array<string, mixed>|null $round
     * @param array<string, mixed>      $user
     */
    private function assertRoundEditable(array $report, ?array $round, array $user): void
    {
        if ($round === null) {
            throw new NotFoundException('Inspection round not found.');
        }
        if ($round['status'] !== 'OPEN') {
            throw new RecordLockedException('This inspection is signed. Ask a QA Admin to reopen it to change values.');
        }
        if (! $this->canEditRound($report, $round, $user)) {
            throw new AuthorizationException('This inspection was started by another operator.');
        }
    }

    /**
     * @param array<string, mixed>  $param
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function gaugeFields(array $param, mixed $gaugeId, string $date, int|string $obsId, array &$errors): array
    {
        if ($gaugeId === null || $gaugeId === '' || (int) $gaugeId === 0) {
            return ['gauge_id' => null, 'gauge_cal_due_date' => null, 'gauge_cal_valid' => null];
        }
        $gauge = $this->db->table('gauges g')->select('g.*, t.requires_calibration')->join('gauge_types t', 't.id = g.gauge_type_id')
            ->where('g.id', (int) $gaugeId)->get(1)->getRowArray();
        if ($gauge === null || ($param['gauge_type_id'] !== null && (int) $gauge['gauge_type_id'] !== (int) $param['gauge_type_id'])) {
            $errors['obs-' . $obsId] = $param['name'] . ': choose a gauge of the required type.';

            return [];
        }
        $availability = $this->gauges->availability($gauge, $date);

        return ['gauge_id' => (int) $gauge['id'], 'gauge_cal_due_date' => $gauge['calibration_due_date'], 'gauge_cal_valid' => $availability['usable'] ? 1 : 0];
    }

    /**
     * @param array<string, mixed>  $item
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function roundFields(array $item, array &$errors, int $roundId): array
    {
        $update = [];
        if (array_key_exists('inspection_time', $item)) {
            $time = trim((string) $item['inspection_time']);
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $errors['round-' . $roundId] = 'Enter the inspection time as HH:MM.';
            } else {
                $update['inspection_time'] = $time . ':00';
            }
        }
        if (array_key_exists('lot_status', $item)) {
            $lot = (string) $item['lot_status'];
            $update['lot_status'] = in_array($lot, ['ACCEPTED', 'REJECTED', 'REWORK', 'ON_HOLD'], true) ? $lot : null;
        }
        foreach (['accepted_qty', 'rejected_qty', 'rework_qty'] as $key) {
            if (array_key_exists($key, $item)) {
                $qty = trim((string) $item[$key]);
                if ($qty === '') {
                    $update[$key] = null;
                } elseif (! ctype_digit($qty) || strlen($qty) > 9) {
                    $errors['round-' . $roundId] = 'Quantities must be whole numbers.';
                } else {
                    $update[$key] = (int) $qty;
                }
            }
        }
        if (array_key_exists('remarks', $item)) {
            $remarks          = trim((string) $item['remarks']);
            $update['remarks'] = $remarks === '' ? null : mb_substr($remarks, 0, 500);
        }

        return $update;
    }

    /**
     * Header changes are guarded by optimistic locking (lock_version).
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $input
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function saveHeader(array $report, array $input, int $lockVersion, array $user): array
    {
        if ($lockVersion !== (int) $report['lock_version']) {
            throw new ConcurrencyException('Someone else changed the report header. Reload to see their changes.');
        }
        $errors = [];
        $update = [];
        $shifts = array_column($this->calendar->shifts(), null, 'id');

        if ($report['header_shift'] !== 'HIDDEN' && array_key_exists('shift_id', $input)) {
            $shiftId = (int) $input['shift_id'];
            if ($shiftId !== 0 && ! isset($shifts[$shiftId])) {
                $errors['shift_id'] = 'Choose a valid shift.';
            } else {
                $update['shift_id'] = $shiftId === 0 ? null : $shiftId;
            }
        }
        foreach (['operator' => 'operator_employee_id', 'setter' => 'setter_employee_id'] as $kind => $column) {
            if ($report['header_' . $kind] !== 'HIDDEN' && array_key_exists($column, $input)) {
                $id = (int) $input[$column];
                if ($id !== 0 && $this->db->table('employees')->where('id', $id)->where('is_active', 1)->countAllResults() === 0) {
                    $errors[$column] = 'Choose an active employee.';
                } else {
                    $update[$column] = $id === 0 ? null : $id;
                }
            }
        }
        if ($report['header_timing'] !== 'HIDDEN') {
            $shift = $shifts[(int) ($update['shift_id'] ?? $report['shift_id'] ?? 0)] ?? null;
            foreach (['received_at', 'finish_at'] as $column) {
                if (! array_key_exists($column, $input)) {
                    continue;
                }
                $time = trim((string) $input[$column]);
                if ($time === '') {
                    $update[$column] = null;
                } elseif (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                    $errors[$column] = 'Enter the time as HH:MM.';
                } else {
                    $update[$column] = $this->calendar->toUtc((string) $report['inspection_date'], $time, $shift);
                }
            }
            $received = $update['received_at'] ?? $report['received_at'];
            $finish   = $update['finish_at'] ?? $report['finish_at'];
            if ($received !== null && $finish !== null && $finish < $received) {
                $errors['finish_at'] = 'The finish time must be after the received time.';
            }
        }
        if (array_key_exists('remarks', $input)) {
            $remarks           = trim((string) $input['remarks']);
            $update['remarks'] = $remarks === '' ? null : mb_substr($remarks, 0, 1000);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($update === []) {
            return [];
        }

        $before = array_intersect_key($report, $update);
        $this->db->table('inspection_reports')->where('id', $report['id'])->update($update + [
            'lock_version' => (int) $report['lock_version'] + 1, 'updated_at' => $this->clock->nowUtcString(), 'updated_by' => $user['id'],
        ]);

        // A newly chosen operator pre-fills empty skill-level readings.
        if (isset($update['operator_employee_id']) && $update['operator_employee_id'] !== null) {
            $this->applyOperatorPrefill((int) $report['id'], (int) $report['template_id'], $user);
        }

        return ['before' => $before, 'after' => $update];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function applyOperatorPrefill(int $reportId, int $templateId, array $user): void
    {
        $report = $this->report($reportId);
        if ($report['operator_skill'] === null) {
            return;
        }
        $params = $this->templateParameters($templateId);
        foreach ($params as $param) {
            if ($param['prefill_source'] !== 'OPERATOR_SKILL_LEVEL') {
                continue;
            }
            $observations = $this->db->table('inspection_observations')->where('report_id', $reportId)->where('template_parameter_id', $param['id'])->get()->getResultArray();
            foreach ($observations as $obs) {
                $filled = $this->db->table('inspection_readings')->where('observation_id', $obs['id'])->where('reading_no', 1)->where('result IS NOT NULL')->countAllResults();
                if ($filled === 0) {
                    $value = $this->evaluator->evaluate(['lsl' => $obs['lsl'], 'usl' => $obs['usl']] + $param, (string) $report['operator_skill'], (string) $report['inspection_date']);
                    $this->writeReading((int) $obs['id'], 1, $value, $user);
                    $this->refreshObservation((int) $obs['id'], $params);
                }
            }
        }
    }
}

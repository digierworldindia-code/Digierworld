<?php

namespace App\Services\Gauges;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Paging;
use App\Services\Audit\AuditService;
use App\Services\Files\UploadService;
use App\Services\Support\Clock;
use App\Services\Support\TransactionRunner;
use App\Core\Database;
use App\Core\UploadedFile;

/**
 * Gauges and their calibration history.
 *
 * Calibration dates on the gauge are only changed by recording a calibration
 * (append-only history). A gauge is usable on a date when it is ACTIVE and its
 * calibration is not expired (or its type needs no calibration).
 */
class GaugeService
{
    public const STATUSES = ['ACTIVE' => 'Active', 'IN_CALIBRATION' => 'In calibration', 'ON_HOLD' => 'On hold',
        'DAMAGED' => 'Damaged', 'RETIRED' => 'Retired'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Clock $clock,
        private readonly TransactionRunner $tx,
        private readonly UploadService $uploads,
    ) {
    }

    /**
     * Calibration state of a gauge on a date.
     *
     * @param array<string, mixed> $gauge needs status, calibration_due_date, requires_calibration
     *
     * @return array{usable: bool, state: string, label: string, tone: string}
     */
    public function availability(array $gauge, string $date, ?int $warnDays = null): array
    {
        $warnDays ??= service('settings')->int('gauge.due_warning_days', 7);

        if ($gauge['status'] !== 'ACTIVE') {
            return ['usable' => false, 'state' => 'NOT_ACTIVE', 'label' => self::STATUSES[$gauge['status']] ?? $gauge['status'], 'tone' => 'fail'];
        }
        if ((int) ($gauge['requires_calibration'] ?? 1) === 0) {
            return ['usable' => true, 'state' => 'NO_CALIBRATION', 'label' => 'No calibration needed', 'tone' => 'muted'];
        }
        $due = $gauge['calibration_due_date'];
        if ($due === null) {
            return ['usable' => false, 'state' => 'NOT_CALIBRATED', 'label' => 'No calibration recorded', 'tone' => 'fail'];
        }
        if ($due < $date) {
            return ['usable' => false, 'state' => 'EXPIRED', 'label' => 'Calibration expired ' . plant_date($due), 'tone' => 'fail'];
        }
        $days = (int) (new \DateTimeImmutable($date))->diff(new \DateTimeImmutable($due))->format('%a');
        if ($days <= $warnDays) {
            return ['usable' => true, 'state' => 'DUE_SOON', 'label' => "Due in {$days} day" . ($days === 1 ? '' : 's') . ' (' . plant_date($due) . ')', 'tone' => 'pending'];
        }

        return ['usable' => true, 'state' => 'VALID', 'label' => 'Valid until ' . plant_date($due), 'tone' => 'pass'];
    }

    /**
     * @param array<string, string> $filters q, type, status, due (expired|soon)
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, Paging $paging): array
    {
        $builder = $this->db->table('gauges g')
            ->select('g.*, t.name AS type_name, t.requires_calibration, u.symbol AS unit_symbol')
            ->join('gauge_types t', 't.id = g.gauge_type_id')
            ->join('units u', 'u.id = g.unit_id', 'left');

        if (($filters['q'] ?? '') !== '') {
            $builder->groupStart()->like('g.gauge_code', $filters['q'])->orLike('g.gauge_name', $filters['q'])->orLike('g.serial_no', $filters['q'])->groupEnd();
        }
        if (($filters['type'] ?? '') !== '') {
            $builder->where('g.gauge_type_id', (int) $filters['type']);
        }
        if (isset(self::STATUSES[$filters['status'] ?? ''])) {
            $builder->where('g.status', $filters['status']);
        } elseif (($filters['status'] ?? '') === '') {
            $builder->where('g.status !=', 'RETIRED');
        }
        $today = service('productionCalendar')->current()['date'];
        if (($filters['due'] ?? '') === 'expired') {
            $builder->where('t.requires_calibration', 1)->groupStart()->where('g.calibration_due_date <', $today)->orWhere('g.calibration_due_date', null)->groupEnd();
        } elseif (($filters['due'] ?? '') === 'soon') {
            $limit = (new \DateTimeImmutable($today))->modify('+' . service('settings')->int('gauge.due_warning_days', 7) . ' days')->format('Y-m-d');
            $builder->where('t.requires_calibration', 1)->where('g.calibration_due_date >=', $today)->where('g.calibration_due_date <=', $limit);
        }

        $paging->total = $builder->countAllResults(false);
        $rows          = $builder->orderBy('g.gauge_code')->get($paging->perPage, $paging->offset())->getResultArray();
        foreach ($rows as &$row) {
            $row['availability'] = $this->availability($row, $today);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $row = $this->db->table('gauges g')
            ->select('g.*, t.name AS type_name, t.requires_calibration, t.default_calibration_frequency_days, u.symbol AS unit_symbol')
            ->join('gauge_types t', 't.id = g.gauge_type_id')
            ->join('units u', 'u.id = g.unit_id', 'left')
            ->where('g.id', $id)->get(1)->getRowArray();

        return $row ?? throw new NotFoundException('Gauge not found.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calibrations(int $gaugeId): array
    {
        return $this->db->table('gauge_calibrations c')
            ->select('c.*, u.username AS recorded_by')
            ->join('users u', 'u.id = c.created_by', 'left')
            ->where('c.gauge_id', $gaugeId)
            ->orderBy('c.calibration_date', 'DESC')->orderBy('c.id', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Reports that used the gauge on or after a date (gauge recall analysis).
     *
     * @return list<array<string, mixed>>
     */
    public function whereUsed(int $gaugeId, ?string $sinceDate, int $limit = 100): array
    {
        $builder = $this->db->table('inspection_observations o')
            ->select('r.id, r.report_no, r.revision_no, r.inspection_date, r.status, p.part_number, m.machine_code, tp.name AS parameter, o.gauge_cal_valid')
            ->join('inspection_reports r', 'r.id = o.report_id')
            ->join('parts p', 'p.id = r.part_id')
            ->join('machines m', 'm.id = r.machine_id')
            ->join('template_parameters tp', 'tp.id = o.template_parameter_id')
            ->where('o.gauge_id', $gaugeId)
            ->where('r.status !=', 'CANCELLED');
        if ($sinceDate !== null) {
            $builder->where('r.inspection_date >=', $sinceDate);
        }

        return $builder->orderBy('r.inspection_date', 'DESC')->orderBy('r.id', 'DESC')->get($limit)->getResultArray();
    }

    /**
     * Gauges of one type with their availability on a date (inspection form).
     *
     * @return list<array<string, mixed>>
     */
    public function optionsForType(int $gaugeTypeId, string $date): array
    {
        $rows = $this->db->table('gauges g')
            ->select('g.id, g.gauge_code, g.gauge_name, g.status, g.calibration_due_date, t.requires_calibration')
            ->join('gauge_types t', 't.id = g.gauge_type_id')
            ->where('g.gauge_type_id', $gaugeTypeId)
            ->whereIn('g.status', ['ACTIVE', 'IN_CALIBRATION', 'ON_HOLD', 'DAMAGED'])
            ->orderBy('g.gauge_code')->get()->getResultArray();

        $warn = service('settings')->int('gauge.due_warning_days', 7);
        foreach ($rows as &$row) {
            $row['availability'] = $this->availability($row, $date, $warn);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     */
    public function create(array $input, array $actor): int
    {
        $data = $this->validate($input, null);
        $now  = $this->clock->nowUtcString();
        $initial = $this->initialCalibration($input);

        return $this->tx->run(function (Database $db) use ($data, $initial, $now, $actor): int {
            $db->table('gauges')->insert($data + [
                'status' => 'ACTIVE', 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id'],
            ]);
            $id = (int) $db->insertID();
            $this->audit->log('CREATE', 'gauge', $id, null, $data, (string) $data['gauge_code']);
            if ($initial !== null) {
                $this->insertCalibration($id, $initial, null, $actor);
            }

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     */
    public function update(int $id, array $input, array $actor): void
    {
        $before = $this->find($id);
        $data   = $this->validate($input, $before);
        $status = (string) ($input['status'] ?? $before['status']);
        if (! isset(self::STATUSES[$status])) {
            throw ValidationException::single('status', 'Choose a valid status.');
        }
        $data['status'] = $status;

        $this->tx->run(function (Database $db) use ($id, $before, $data, $actor): void {
            $db->table('gauges')->where('id', $id)->update($data + ['updated_at' => $this->clock->nowUtcString(), 'updated_by' => $actor['id']]);
            $this->audit->log('UPDATE', 'gauge', $id, array_intersect_key($before, $data), $data, (string) $before['gauge_code']);
        });
    }

    /**
     * Records a calibration (append-only) and updates the gauge's dates and status.
     *
     * @param array<string, mixed> $input calibration_date, due_date, result, agency, certificate_no, remarks
     * @param array<string, mixed> $actor
     */
    public function recordCalibration(int $gaugeId, array $input, ?UploadedFile $certificate, array $actor): void
    {
        $this->find($gaugeId);
        $data = $this->validateCalibration($input);

        $stored = null;
        if ($certificate !== null && $certificate->isValid()) {
            $stored = $this->uploads->storeCertificate($certificate);
        }

        try {
            $this->tx->run(fn () => $this->insertCalibration($gaugeId, $data, $stored, $actor));
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->uploads->delete('certificates', $stored['name']);
            }

            throw $e;
        }
    }

    /**
     * @return array{path: string, mime: string, name: string}
     */
    public function certificate(int $calibrationId): array
    {
        $row = $this->db->table('gauge_calibrations c')->select('c.*, g.gauge_code')
            ->join('gauges g', 'g.id = c.gauge_id')->where('c.id', $calibrationId)->get(1)->getRowArray();
        $path = $row === null || $row['certificate_file'] === null ? null : $this->uploads->path('certificates', $row['certificate_file']);
        if ($path === null) {
            throw new NotFoundException('Certificate not found.');
        }
        $ext = pathinfo($row['certificate_file'], PATHINFO_EXTENSION);

        return ['path' => $path, 'mime' => (string) $row['certificate_mime'],
            'name' => preg_replace('/[^A-Za-z0-9._-]/', '_', $row['gauge_code'] . '_' . $row['calibration_date']) . '.' . $ext];
    }

    /**
     * @param array<string, mixed>                                       $data
     * @param array{name: string, mime: string, sha256: string}|null     $stored
     * @param array<string, mixed>                                       $actor
     */
    private function insertCalibration(int $gaugeId, array $data, ?array $stored, array $actor): void
    {
        $now = $this->clock->nowUtcString();
        $this->db->table('gauge_calibrations')->insert($data + [
            'gauge_id'           => $gaugeId,
            'certificate_file'   => $stored['name'] ?? null,
            'certificate_mime'   => $stored['mime'] ?? null,
            'certificate_sha256' => $stored['sha256'] ?? null,
            'created_at'         => $now,
            'created_by'         => $actor['id'],
        ]);
        $calId = (int) $this->db->insertID();

        $gauge  = $this->db->table('gauges')->where('id', $gaugeId)->get(1)->getRowArray();
        $update = ['updated_at' => $now, 'updated_by' => $actor['id']];
        if ($gauge['last_calibration_date'] === null || $data['calibration_date'] >= $gauge['last_calibration_date']) {
            $update['last_calibration_date'] = $data['calibration_date'];
            $update['calibration_due_date']  = $data['due_date'];
            if ($data['result'] === 'REJECTED') {
                $update['status'] = 'ON_HOLD';
            } elseif ($gauge['status'] !== 'RETIRED') {
                $update['status'] = 'ACTIVE';
            }
        }
        $this->db->table('gauges')->where('id', $gaugeId)->update($update);
        $this->audit->log('CALIBRATE', 'gauge', $gaugeId, [
            'calibration_due_date' => $gauge['calibration_due_date'], 'status' => $gauge['status'],
        ], ['calibration_id' => $calId] + $data + array_intersect_key($update, array_flip(['calibration_due_date', 'status'])), (string) $gauge['gauge_code']);
    }

    /**
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function validate(array $input, ?array $existing): array
    {
        $data = [
            'gauge_name'                 => trim((string) ($input['gauge_name'] ?? '')),
            'gauge_type_id'              => trim((string) ($input['gauge_type_id'] ?? '')),
            'make'                       => trim((string) ($input['make'] ?? '')),
            'serial_no'                  => trim((string) ($input['serial_no'] ?? '')),
            'measuring_range'            => trim((string) ($input['measuring_range'] ?? '')),
            'least_count'                => trim((string) ($input['least_count'] ?? '')),
            'unit_id'                    => trim((string) ($input['unit_id'] ?? '')),
            'location'                   => trim((string) ($input['location'] ?? '')),
            'calibration_frequency_days' => trim((string) ($input['calibration_frequency_days'] ?? '')),
        ];
        $rules = [
            'gauge_name'                 => 'required|max_length[120]',
            'gauge_type_id'              => 'required|is_natural_no_zero',
            'make'                       => 'permit_empty|max_length[80]',
            'serial_no'                  => 'permit_empty|max_length[60]',
            'measuring_range'            => 'permit_empty|max_length[60]',
            'least_count'                => 'permit_empty|regex_match[/^\d{1,6}(\.\d{1,6})?$/]',
            'unit_id'                    => 'permit_empty|is_natural_no_zero',
            'location'                   => 'permit_empty|max_length[80]',
            'calibration_frequency_days' => 'permit_empty|integer|greater_than[0]|less_than_equal_to[3650]',
        ];
        if ($existing === null) {
            $data['gauge_code']  = strtoupper(trim((string) ($input['gauge_code'] ?? '')));
            $rules['gauge_code'] = 'required|max_length[40]|regex_match[/^[A-Z0-9][A-Z0-9._\/-]*$/]';
        }

        $validation = service('validation', null, false);
        $validation->setRules($rules);
        if (! $validation->run($data)) {
            throw new ValidationException($validation->getErrors());
        }
        if ($existing === null && $this->db->table('gauges')->where('gauge_code', $data['gauge_code'])->countAllResults() > 0) {
            throw ValidationException::single('gauge_code', 'This Gauge ID already exists.');
        }
        if ($this->db->table('gauge_types')->where('id', (int) $data['gauge_type_id'])->countAllResults() === 0) {
            throw ValidationException::single('gauge_type_id', 'Choose a gauge type.');
        }

        foreach ($data as $key => $value) {
            if ($value === '') {
                $data[$key] = null;
            }
        }
        foreach (['gauge_type_id', 'unit_id', 'calibration_frequency_days'] as $key) {
            if ($data[$key] !== null) {
                $data[$key] = (int) $data[$key];
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>|null
     */
    private function initialCalibration(array $input): ?array
    {
        if (trim((string) ($input['calibration_date'] ?? '')) === '' && trim((string) ($input['due_date'] ?? '')) === '') {
            return null;
        }

        return $this->validateCalibration($input + ['result' => 'ACCEPTED', 'remarks' => 'Initial entry when the gauge was registered']);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function validateCalibration(array $input): array
    {
        $data = [
            'calibration_date' => trim((string) ($input['calibration_date'] ?? '')),
            'due_date'         => trim((string) ($input['due_date'] ?? '')),
            'result'           => trim((string) ($input['result'] ?? '')),
            'agency'           => trim((string) ($input['agency'] ?? '')),
            'certificate_no'   => trim((string) ($input['certificate_no'] ?? '')),
            'remarks'          => trim((string) ($input['remarks'] ?? '')),
        ];
        $validation = service('validation', null, false);
        $validation->setRules([
            'calibration_date' => 'required|valid_date[Y-m-d]',
            'due_date'         => 'required|valid_date[Y-m-d]',
            'result'           => 'required|in_list[ACCEPTED,ADJUSTED,REJECTED]',
            'agency'           => 'permit_empty|max_length[120]',
            'certificate_no'   => 'permit_empty|max_length[60]',
            'remarks'          => 'permit_empty|max_length[500]',
        ]);
        if (! $validation->run($data)) {
            throw new ValidationException($validation->getErrors());
        }
        if ($data['due_date'] <= $data['calibration_date']) {
            throw ValidationException::single('due_date', 'The due date must be after the calibration date.');
        }
        if ($data['calibration_date'] > service('productionCalendar')->current()['date']) {
            throw ValidationException::single('calibration_date', 'The calibration date cannot be in the future.');
        }
        foreach (['agency', 'certificate_no', 'remarks'] as $key) {
            if ($data[$key] === '') {
                $data[$key] = null;
            }
        }

        return $data;
    }
}

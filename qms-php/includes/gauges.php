<?php
/**
 * Gauges and their calibration history.
 *
 * The calibration dates on a gauge change only by recording a calibration
 * (append-only history). A gauge is usable on a date when it is ACTIVE and its
 * calibration has not expired (or its type needs no calibration).
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/validate.php';
require_once QMS_ROOT . '/includes/uploads.php';

const GAUGE_STATUSES = ['ACTIVE' => 'Active', 'IN_CALIBRATION' => 'In calibration', 'ON_HOLD' => 'On hold',
    'DAMAGED' => 'Damaged', 'RETIRED' => 'Retired'];

const CALIBRATION_RESULTS = ['ACCEPTED' => 'Accepted', 'ADJUSTED' => 'Adjusted, then accepted', 'REJECTED' => 'Rejected (gauge goes on hold)'];

/**
 * Calibration state of a gauge on a date (needs status, calibration_due_date, requires_calibration).
 *
 * @return array{usable: bool, state: string, label: string, tone: string}
 */
function gauge_availability(array $gauge, string $date, ?int $warnDays = null): array
{
    $warnDays ??= setting_int('gauge.due_warning_days', 7);

    if ($gauge['status'] !== 'ACTIVE') {
        return ['usable' => false, 'state' => 'NOT_ACTIVE', 'label' => GAUGE_STATUSES[$gauge['status']] ?? (string) $gauge['status'], 'tone' => 'fail'];
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
    $days = (int) (new DateTimeImmutable($date))->diff(new DateTimeImmutable($due))->format('%a');
    if ($days <= $warnDays) {
        return ['usable' => true, 'state' => 'DUE_SOON', 'label' => "Due in {$days} day" . ($days === 1 ? '' : 's') . ' (' . plant_date($due) . ')', 'tone' => 'pending'];
    }

    return ['usable' => true, 'state' => 'VALID', 'label' => 'Valid until ' . plant_date($due), 'tone' => 'pass'];
}

/** Badge HTML of an availability result. */
function gauge_badge(array $availability): string
{
    $icon = $availability['usable'] ? ($availability['state'] === 'DUE_SOON' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle') : 'bi-x-octagon-fill';

    return '<span class="qms-badge qms-badge--' . e($availability['tone']) . '">' . qms_icon($icon) . ' ' . e($availability['label']) . '</span>';
}

/**
 * Gauge list with filters q, type, status, due (expired | soon).
 *
 * @param array{page: int, per_page: int, offset: int, total: int} $paging total is filled in
 */
function gauge_list(array $filters, array &$paging): array
{
    $where  = [];
    $params = [];
    if (($filters['q'] ?? '') !== '') {
        $where[] = "(g.gauge_code LIKE ? ESCAPE '!' OR g.gauge_name LIKE ? ESCAPE '!' OR g.serial_no LIKE ? ESCAPE '!')";
        array_push($params, db_like($filters['q']), db_like($filters['q']), db_like($filters['q']));
    }
    if (ctype_digit((string) ($filters['type'] ?? ''))) {
        $where[]  = 'g.gauge_type_id = ?';
        $params[] = (int) $filters['type'];
    }
    if (isset(GAUGE_STATUSES[$filters['status'] ?? ''])) {
        $where[]  = 'g.status = ?';
        $params[] = $filters['status'];
    } elseif (($filters['status'] ?? '') === '') {
        $where[] = "g.status <> 'RETIRED'";
    }
    $today = production_current()['date'];
    if (($filters['due'] ?? '') === 'expired') {
        $where[]  = 't.requires_calibration = 1 AND (g.calibration_due_date < ? OR g.calibration_due_date IS NULL)';
        $params[] = $today;
    } elseif (($filters['due'] ?? '') === 'soon') {
        $limit    = (new DateTimeImmutable($today))->modify('+' . setting_int('gauge.due_warning_days', 7) . ' days')->format('Y-m-d');
        $where[]  = 't.requires_calibration = 1 AND g.calibration_due_date BETWEEN ? AND ?';
        array_push($params, $today, $limit);
    }

    $from = ' FROM gauges g JOIN gauge_types t ON t.id = g.gauge_type_id LEFT JOIN units u ON u.id = g.unit_id'
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
    $paging['total'] = (int) db_value('SELECT COUNT(*)' . $from, $params);
    $rows = db_all('SELECT g.*, t.name AS type_name, t.requires_calibration, u.symbol AS unit_symbol' . $from
        . ' ORDER BY g.gauge_code LIMIT ? OFFSET ?', [...$params, $paging['per_page'], $paging['offset']]);
    $warn = setting_int('gauge.due_warning_days', 7);
    foreach ($rows as &$row) {
        $row['availability'] = gauge_availability($row, $today, $warn);
    }

    return $rows;
}

function gauge_find(int $id): array
{
    return db_row('SELECT g.*, t.name AS type_name, t.requires_calibration, t.default_calibration_frequency_days, u.symbol AS unit_symbol
                     FROM gauges g JOIN gauge_types t ON t.id = g.gauge_type_id LEFT JOIN units u ON u.id = g.unit_id
                    WHERE g.id = ?', [$id]) ?? not_found('Gauge not found.');
}

function gauge_calibrations(int $gaugeId): array
{
    return db_all('SELECT c.*, u.username AS recorded_by FROM gauge_calibrations c LEFT JOIN users u ON u.id = c.created_by
                    WHERE c.gauge_id = ? ORDER BY c.calibration_date DESC, c.id DESC', [$gaugeId]);
}

/** Reports that used the gauge (gauge recall analysis), newest first. */
function gauge_where_used(int $gaugeId, ?string $sinceDate = null, int $limit = 100): array
{
    $params = [$gaugeId];
    $since  = '';
    if ($sinceDate !== null) {
        $since    = ' AND r.inspection_date >= ?';
        $params[] = $sinceDate;
    }
    $params[] = $limit;

    return db_all("SELECT r.id, r.report_no, r.revision_no, r.inspection_date, r.status, p.part_number, m.machine_code,
                          tp.name AS parameter, o.gauge_cal_valid
                     FROM inspection_observations o
                     JOIN inspection_reports r ON r.id = o.report_id
                     JOIN parts p ON p.id = r.part_id
                     JOIN machines m ON m.id = r.machine_id
                     JOIN template_parameters tp ON tp.id = o.template_parameter_id
                    WHERE o.gauge_id = ? AND r.status <> 'CANCELLED'{$since}
                    ORDER BY r.inspection_date DESC, r.id DESC LIMIT ?", $params);
}

/** Gauges of one type with their availability on a date (inspection form). */
function gauge_options_for_type(int $gaugeTypeId, string $date): array
{
    $rows = db_all("SELECT g.id, g.gauge_code, g.gauge_name, g.status, g.calibration_due_date, t.requires_calibration
                      FROM gauges g JOIN gauge_types t ON t.id = g.gauge_type_id
                     WHERE g.gauge_type_id = ? AND g.status IN ('ACTIVE', 'IN_CALIBRATION', 'ON_HOLD', 'DAMAGED')
                     ORDER BY g.gauge_code", [$gaugeTypeId]);
    $warn = setting_int('gauge.due_warning_days', 7);
    foreach ($rows as &$row) {
        $row['availability'] = gauge_availability($row, $date, $warn);
    }

    return $rows;
}

/** Registers a gauge (optionally with its current calibration); returns the id. */
function gauge_create(array $input, array $actor): int
{
    $data    = gauge_validate($input, null);
    $initial = null;
    if (trim((string) ($input['calibration_date'] ?? '')) !== '' || trim((string) ($input['due_date'] ?? '')) !== '') {
        $initial = gauge_validate_calibration(['result' => 'ACCEPTED', 'remarks' => 'Initial entry when the gauge was registered'] + $input);
    }
    $now = now_str();

    return db_transaction(static function () use ($data, $initial, $now, $actor): int {
        $id = db_insert('gauges', $data + ['status' => 'ACTIVE', 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id']]);
        audit_log('CREATE', 'gauge', $id, null, $data, (string) $data['gauge_code']);
        if ($initial !== null) {
            gauge_insert_calibration($id, $initial, null, $actor);
        }

        return $id;
    });
}

function gauge_update(int $id, array $input, array $actor): void
{
    $before = gauge_find($id);
    $data   = gauge_validate($input, $before);
    $status = (string) ($input['status'] ?? $before['status']);
    if (! isset(GAUGE_STATUSES[$status])) {
        fail_field('status', 'Choose a valid status.');
    }
    $data['status'] = $status;

    db_transaction(static function () use ($id, $before, $data, $actor): void {
        db_update('gauges', $data + ['updated_at' => now_str(), 'updated_by' => $actor['id']], 'id = ?', [$id]);
        audit_log('UPDATE', 'gauge', $id, array_intersect_key($before, $data), $data, (string) $before['gauge_code']);
    });
}

/**
 * Records a calibration (append-only) and moves the gauge's dates and status.
 *
 * @param array|null $certificate entry of $_FILES (optional)
 */
function gauge_record_calibration(int $gaugeId, array $input, ?array $certificate, array $actor): void
{
    gauge_find($gaugeId);
    $data   = gauge_validate_calibration($input);
    $stored = null;
    if ($certificate !== null && ($certificate['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $stored = upload_store_certificate(upload_accept($certificate, 'certificate', UPLOAD_CERTIFICATE_MAX_KB, UPLOAD_CERTIFICATE_MIMES));
    }

    try {
        db_transaction(static fn () => gauge_insert_calibration($gaugeId, $data, $stored, $actor));
    } catch (Throwable $e) {
        if ($stored !== null) {
            upload_delete('certificates', $stored['name']);
        }

        throw $e;
    }
}

/**
 * Stored certificate of a calibration.
 *
 * @return array{path: string, mime: string, name: string}
 */
function gauge_certificate(int $calibrationId): array
{
    $row  = db_row('SELECT c.*, g.gauge_code FROM gauge_calibrations c JOIN gauges g ON g.id = c.gauge_id WHERE c.id = ?', [$calibrationId]);
    $path = $row === null || $row['certificate_file'] === null ? null : upload_path('certificates', (string) $row['certificate_file']);
    if ($path === null) {
        not_found('Certificate not found.');
    }
    $ext = pathinfo((string) $row['certificate_file'], PATHINFO_EXTENSION);

    return ['path' => $path, 'mime' => (string) $row['certificate_mime'],
        'name' => preg_replace('/[^A-Za-z0-9._-]/', '_', $row['gauge_code'] . '_' . $row['calibration_date']) . '.' . $ext];
}

function gauge_insert_calibration(int $gaugeId, array $data, ?array $stored, array $actor): void
{
    $now   = now_str();
    $calId = db_insert('gauge_calibrations', $data + [
        'gauge_id'           => $gaugeId,
        'certificate_file'   => $stored['name'] ?? null,
        'certificate_mime'   => $stored['mime'] ?? null,
        'certificate_sha256' => $stored['sha256'] ?? null,
        'created_at'         => $now,
        'created_by'         => $actor['id'],
    ]);

    $gauge  = db_row('SELECT * FROM gauges WHERE id = ? FOR UPDATE', [$gaugeId]);
    $update = ['updated_at' => $now, 'updated_by' => $actor['id']];
    // An older calibration entered late does not overwrite newer dates.
    if ($gauge['last_calibration_date'] === null || $data['calibration_date'] >= $gauge['last_calibration_date']) {
        $update['last_calibration_date'] = $data['calibration_date'];
        $update['calibration_due_date']  = $data['due_date'];
        if ($data['result'] === 'REJECTED') {
            $update['status'] = 'ON_HOLD';
        } elseif ($gauge['status'] !== 'RETIRED') {
            $update['status'] = 'ACTIVE';
        }
    }
    db_update('gauges', $update, 'id = ?', [$gaugeId]);
    audit_log('CALIBRATE', 'gauge', $gaugeId, ['calibration_due_date' => $gauge['calibration_due_date'], 'status' => $gauge['status']],
        ['calibration_id' => $calId] + $data + array_intersect_key($update, array_flip(['calibration_due_date', 'status'])), (string) $gauge['gauge_code']);
}

/** Checks the gauge form; returns the values for the database. */
function gauge_validate(array $input, ?array $existing): array
{
    $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    $data = [];
    foreach (['gauge_name', 'gauge_type_id', 'make', 'serial_no', 'measuring_range', 'least_count', 'unit_id', 'location', 'calibration_frequency_days'] as $key) {
        $data[$key] = $text($key);
    }
    $rules = [
        'gauge_name'                 => ['label' => 'Name', 'rules' => 'required|max_length[120]'],
        'gauge_type_id'              => ['label' => 'Gauge type', 'rules' => 'required|is_natural_no_zero'],
        'make'                       => ['label' => 'Make', 'rules' => 'permit_empty|max_length[80]'],
        'serial_no'                  => ['label' => 'Serial no.', 'rules' => 'permit_empty|max_length[60]'],
        'measuring_range'            => ['label' => 'Measuring range', 'rules' => 'permit_empty|max_length[60]'],
        'least_count'                => ['label' => 'Least count', 'rules' => 'permit_empty|regex_match[/^\d{1,6}(\.\d{1,6})?$/]'],
        'unit_id'                    => ['label' => 'Unit', 'rules' => 'permit_empty|is_natural_no_zero'],
        'location'                   => ['label' => 'Location', 'rules' => 'permit_empty|max_length[80]'],
        'calibration_frequency_days' => ['label' => 'Calibration frequency', 'rules' => 'permit_empty|integer|greater_than[0]|less_than_equal_to[3650]'],
    ];
    if ($existing === null) {
        $data['gauge_code']  = strtoupper($text('gauge_code'));
        $rules['gauge_code'] = ['label' => 'Gauge ID', 'rules' => 'required|max_length[40]|regex_match[/^[A-Z0-9][A-Z0-9._\/-]*$/]'];
    }
    validate_or_fail($data, $rules);

    if ($existing === null && (int) db_value('SELECT COUNT(*) FROM gauges WHERE gauge_code = ?', [$data['gauge_code']]) > 0) {
        fail_field('gauge_code', 'This Gauge ID already exists.');
    }
    if ((int) db_value('SELECT COUNT(*) FROM gauge_types WHERE id = ?', [(int) $data['gauge_type_id']]) === 0) {
        fail_field('gauge_type_id', 'Choose a gauge type.');
    }
    if ($data['unit_id'] !== '' && (int) db_value('SELECT COUNT(*) FROM units WHERE id = ?', [(int) $data['unit_id']]) === 0) {
        fail_field('unit_id', 'Choose a valid unit.');
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

/** Checks a calibration entry; returns the values for the database. */
function gauge_validate_calibration(array $input): array
{
    $data = [];
    foreach (['calibration_date', 'due_date', 'result', 'agency', 'certificate_no', 'remarks'] as $key) {
        $data[$key] = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    }
    validate_or_fail($data, [
        'calibration_date' => ['label' => 'Calibration date', 'rules' => 'required|valid_date[Y-m-d]'],
        'due_date'         => ['label' => 'Due date', 'rules' => 'required|valid_date[Y-m-d]'],
        'result'           => ['label' => 'Result', 'rules' => 'required|in_list[ACCEPTED,ADJUSTED,REJECTED]'],
        'agency'           => ['label' => 'Agency', 'rules' => 'permit_empty|max_length[120]'],
        'certificate_no'   => ['label' => 'Certificate no.', 'rules' => 'permit_empty|max_length[60]'],
        'remarks'          => ['label' => 'Remarks', 'rules' => 'permit_empty|max_length[500]'],
    ]);
    if ($data['due_date'] <= $data['calibration_date']) {
        fail_field('due_date', 'The due date must be after the calibration date.');
    }
    if ($data['calibration_date'] > production_current()['date']) {
        fail_field('calibration_date', 'The calibration date cannot be in the future.');
    }
    foreach (['agency', 'certificate_no', 'remarks'] as $key) {
        if ($data[$key] === '') {
            $data[$key] = null;
        }
    }

    return $data;
}

/** "0.01 mm" from a DECIMAL least count. */
function gauge_least_count(array $gauge): string
{
    if ($gauge['least_count'] === null) {
        return '';
    }
    $value = (string) $gauge['least_count'];
    $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

    return $value . (($gauge['unit_symbol'] ?? '') !== '' ? ' ' . $gauge['unit_symbol'] : '');
}

<?php
/**
 * Master data: parts, machines, parameter library, employees, departments,
 * shifts, units, inspection methods and gauge types.
 *
 * One list of field definitions drives the list page, the form and the
 * validation. Codes never change after creation; rows are retired instead of
 * deleted, and every change is written to the audit trail.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/validate.php';

/**
 * Field definitions per master type.
 *
 * @return array<string, array<string, mixed>>
 */
function master_definitions(): array
{
    static $definitions = null;
    if ($definitions !== null) {
        return $definitions;
    }
    $codeRule = 'required|max_length[%d]|regex_match[/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/]';
    $timeRule = 'required|regex_match[/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/]';

    return $definitions = [
        'parts' => [
            'table' => 'parts', 'title' => 'Parts', 'singular' => 'part', 'icon' => 'bi-box-seam',
            'permission' => 'master.parts', 'code' => 'part_number', 'order' => 'part_number',
            'help' => 'Part numbers and names printed on inspection reports. Templates are mapped to parts.',
            'fields' => [
                'part_number'      => ['label' => 'Part number', 'rules' => sprintf($codeRule, 50), 'upper' => true],
                'part_name'        => ['label' => 'Part name', 'rules' => 'required|max_length[150]'],
                'drawing_number'   => ['label' => 'Drawing number', 'rules' => 'permit_empty|max_length[60]'],
                'drawing_revision' => ['label' => 'Drawing revision', 'rules' => 'permit_empty|max_length[10]'],
                'customer_name'    => ['label' => 'Customer', 'rules' => 'permit_empty|max_length[120]'],
                'material'         => ['label' => 'Material', 'rules' => 'permit_empty|max_length[80]'],
            ],
            'list' => ['part_number', 'part_name', 'drawing_number', 'customer_name'],
        ],
        'machines' => [
            'table' => 'machines', 'title' => 'Machines', 'singular' => 'machine', 'icon' => 'bi-gear-wide-connected',
            'permission' => 'master.machines', 'code' => 'machine_code', 'order' => 'machine_code',
            'help' => 'Machine numbers. The PM due date pre-fills the Setup Change "PM Due Date" check.',
            'fields' => [
                'machine_code'  => ['label' => 'Machine number', 'rules' => sprintf($codeRule, 30), 'upper' => true],
                'machine_name'  => ['label' => 'Machine name', 'rules' => 'required|max_length[120]'],
                'department_id' => ['label' => 'Department / area', 'type' => 'select', 'lookup' => 'departments', 'rules' => 'permit_empty|is_natural_no_zero'],
                'make'          => ['label' => 'Make', 'rules' => 'permit_empty|max_length[80]'],
                'model'         => ['label' => 'Model', 'rules' => 'permit_empty|max_length[80]'],
                'location'      => ['label' => 'Location', 'rules' => 'permit_empty|max_length[80]'],
                'pm_due_date'   => ['label' => 'PM due date', 'type' => 'date', 'rules' => 'permit_empty|valid_date[Y-m-d]'],
            ],
            'list' => ['machine_code', 'machine_name', 'department_id', 'pm_due_date'],
        ],
        'parameters' => [
            'table' => 'inspection_parameters', 'title' => 'Parameter library', 'singular' => 'parameter', 'icon' => 'bi-list-check',
            'permission' => 'master.library', 'code' => 'code', 'order' => 'name',
            'help' => 'Characteristics reused by templates (Appearance, Throat Diameter, Chuck Pressure…). The code links the same characteristic across parts for analysis.',
            'fields' => [
                'code'                     => ['label' => 'Code', 'rules' => sprintf($codeRule, 40), 'upper' => true],
                'name'                     => ['label' => 'Name', 'rules' => 'required|max_length[150]'],
                'default_observation_type' => ['label' => 'Default observation type', 'type' => 'select', 'options' => OBSERVATION_TYPES,
                    'rules' => 'required|in_list[' . implode(',', array_keys(OBSERVATION_TYPES)) . ']'],
                'default_unit_id'          => ['label' => 'Default unit', 'type' => 'select', 'lookup' => 'units', 'rules' => 'permit_empty|is_natural_no_zero'],
                'default_method_id'        => ['label' => 'Default inspection method', 'type' => 'select', 'lookup' => 'methods', 'rules' => 'permit_empty|is_natural_no_zero'],
                'default_gauge_type_id'    => ['label' => 'Default gauge / instrument', 'type' => 'select', 'lookup' => 'gauge-types', 'rules' => 'permit_empty|is_natural_no_zero'],
                'description'              => ['label' => 'Description', 'type' => 'textarea', 'rules' => 'permit_empty|max_length[255]'],
            ],
            'list' => ['code', 'name', 'default_observation_type', 'default_unit_id'],
        ],
        'employees' => [
            'table' => 'employees', 'title' => 'Employees', 'singular' => 'employee', 'icon' => 'bi-person-badge',
            'permission' => 'master.employees', 'code' => 'employee_code', 'order' => 'full_name',
            'help' => 'Operators, setters, engineers and approvers. Link an employee to a login under Users.',
            'fields' => [
                'employee_code' => ['label' => 'Employee ID', 'rules' => sprintf($codeRule, 30), 'upper' => true],
                'full_name'     => ['label' => 'Full name', 'rules' => 'required|max_length[120]'],
                'department_id' => ['label' => 'Department', 'type' => 'select', 'lookup' => 'departments', 'rules' => 'permit_empty|is_natural_no_zero'],
                'designation'   => ['label' => 'Designation', 'rules' => 'permit_empty|max_length[80]'],
                'skill_level'   => ['label' => 'Skill level (skill matrix)', 'type' => 'select',
                    'options' => ['0' => 'L0 – trainee', '1' => 'L1', '2' => 'L2', '3' => 'L3', '4' => 'L4 – can train others'], 'rules' => 'permit_empty|in_list[0,1,2,3,4]'],
                'phone'         => ['label' => 'Phone', 'rules' => 'permit_empty|max_length[20]|regex_match[/^[0-9 +()-]+$/]'],
                'email'         => ['label' => 'E-mail', 'rules' => 'permit_empty|max_length[150]|valid_email'],
            ],
            'list' => ['employee_code', 'full_name', 'department_id', 'designation', 'skill_level'],
        ],
        'departments' => [
            'table' => 'departments', 'title' => 'Departments', 'singular' => 'department', 'icon' => 'bi-diagram-3',
            'permission' => 'master.employees', 'code' => 'code', 'order' => 'code',
            'help' => 'Production areas and functions.',
            'fields' => [
                'code' => ['label' => 'Code', 'rules' => sprintf($codeRule, 20), 'upper' => true],
                'name' => ['label' => 'Name', 'rules' => 'required|max_length[100]'],
            ],
            'list' => ['code', 'name'],
        ],
        'shifts' => [
            'table' => 'shifts', 'title' => 'Shifts', 'singular' => 'shift', 'icon' => 'bi-clock-history',
            'permission' => 'master.shifts', 'code' => 'code', 'order' => 'sort_order',
            'help' => 'Shift timings. A shift ending before it starts (22:00–06:00) crosses midnight; its hours after midnight belong to the production date it started on.',
            'fields' => [
                'code'       => ['label' => 'Code', 'rules' => 'required|max_length[5]|alpha_numeric', 'upper' => true],
                'name'       => ['label' => 'Name', 'rules' => 'required|max_length[30]'],
                'start_time' => ['label' => 'Start time', 'type' => 'time', 'rules' => $timeRule],
                'end_time'   => ['label' => 'End time', 'type' => 'time', 'rules' => $timeRule . '|differs[start_time]'],
                'sort_order' => ['label' => 'Display order', 'type' => 'number', 'rules' => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[99]'],
            ],
            'list' => ['code', 'name', 'start_time', 'end_time'],
        ],
        'units' => [
            'table' => 'units', 'title' => 'Units', 'singular' => 'unit', 'icon' => 'bi-rulers',
            'permission' => 'master.library', 'code' => 'code', 'order' => 'code',
            'help' => 'Units of measure shown next to specifications and readings.',
            'fields' => [
                'code'   => ['label' => 'Code', 'rules' => sprintf($codeRule, 20), 'upper' => true],
                'symbol' => ['label' => 'Symbol', 'rules' => 'required|max_length[20]'],
                'name'   => ['label' => 'Name', 'rules' => 'required|max_length[60]'],
            ],
            'list' => ['code', 'symbol', 'name'],
        ],
        'methods' => [
            'table' => 'inspection_methods', 'title' => 'Inspection methods', 'singular' => 'inspection method', 'icon' => 'bi-eye',
            'permission' => 'master.library', 'code' => 'code', 'order' => 'sort_order',
            'help' => 'How a characteristic is checked. The short label is the column caption on printed Product Parameter sheets (GO/NO GO, VISUAL, TPG, DVC).',
            'fields' => [
                'code'        => ['label' => 'Code', 'rules' => sprintf($codeRule, 20), 'upper' => true],
                'name'        => ['label' => 'Name', 'rules' => 'required|max_length[80]'],
                'short_label' => ['label' => 'Short label (print column)', 'rules' => 'required|max_length[12]'],
                'sort_order'  => ['label' => 'Display order', 'type' => 'number', 'rules' => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[999]'],
            ],
            'list' => ['code', 'name', 'short_label'],
        ],
        'gauge-types' => [
            'table' => 'gauge_types', 'title' => 'Gauge types', 'singular' => 'gauge type', 'icon' => 'bi-speedometer',
            'permission' => 'master.library', 'code' => 'code', 'order' => 'name',
            'help' => 'Instrument categories. Templates require a gauge type; inspectors then pick a specific Gauge ID of that type.',
            'fields' => [
                'code'                               => ['label' => 'Code', 'rules' => sprintf($codeRule, 30), 'upper' => true],
                'name'                               => ['label' => 'Name', 'rules' => 'required|max_length[100]'],
                'requires_calibration'               => ['label' => 'Requires calibration', 'type' => 'bool', 'rules' => 'permit_empty|in_list[0,1]'],
                'default_calibration_frequency_days' => ['label' => 'Default calibration frequency (days)', 'type' => 'number', 'rules' => 'permit_empty|integer|greater_than[0]|less_than_equal_to[3650]'],
            ],
            'list' => ['code', 'name', 'requires_calibration', 'default_calibration_frequency_days'],
        ],
    ];
}

/** Permissions that allow editing at least one master type. */
const MASTER_EDIT_PERMISSIONS = ['master.machines', 'master.parts', 'master.employees', 'master.shifts', 'master.library'];

/** One definition, or a 404 for an unknown type. */
function master_definition(string $type): array
{
    return master_definitions()[$type] ?? not_found('Unknown master data type.');
}

/**
 * How select lists read their options: name => [table, label SQL, order column].
 * The SQL parts are fixed text written here, never user input.
 */
const MASTER_LOOKUPS = [
    'departments'  => ['departments', "CONCAT(code, ' · ', name)", 'code'],
    'units'        => ['units', "CONCAT(symbol, ' (', name, ')')", 'code'],
    'methods'      => ['inspection_methods', 'name', 'sort_order'],
    'gauge-types'  => ['gauge_types', 'name', 'name'],
    'parts'        => ['parts', "CONCAT(part_number, ' · ', part_name)", 'part_number'],
    'machines'     => ['machines', "CONCAT(machine_code, ' · ', machine_name)", 'machine_code'],
    'employees'    => ['employees', "CONCAT(full_name, ' (', employee_code, ')')", 'full_name'],
    'shifts'       => ['shifts', "CONCAT('Shift ', code)", 'sort_order'],
    'report-types' => ['report_types', 'name', 'id'],
];

/**
 * Active rows of a lookup list as id => label (for select boxes).
 *
 * @return array<string, string>
 */
function master_lookup(string $lookup, bool $activeOnly = true): array
{
    [$table, $label, $order] = MASTER_LOOKUPS[$lookup] ?? throw new InvalidArgumentException("Unknown lookup {$lookup}");
    $rows = db_all('SELECT id, ' . $label . ' AS label FROM ' . db_name($table)
        . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY ' . db_name($order));

    return array_column($rows, 'label', 'id');
}

/** @return array<string, int> type => number of active rows */
function master_counts(): array
{
    $out = [];
    foreach (master_definitions() as $type => $def) {
        $out[$type] = (int) db_value('SELECT COUNT(*) FROM ' . db_name($def['table']) . ' WHERE is_active = 1');
    }

    return $out;
}

/**
 * Rows for the list page. Searches the text columns of the list; $status is active, retired or all.
 *
 * @param array{page: int, per_page: int, offset: int, total: int} $paging total is filled in
 */
function master_list(string $type, string $q, string $status, array &$paging): array
{
    $def    = master_definition($type);
    $where  = [];
    $params = [];
    if ($q !== '') {
        $search = [];
        foreach ($def['list'] as $column) {
            if (($def['fields'][$column]['type'] ?? 'text') === 'text') {
                $search[] = db_name($column) . " LIKE ? ESCAPE '!'";
                $params[] = db_like($q);
            }
        }
        if ($search !== []) {
            $where[] = '(' . implode(' OR ', $search) . ')';
        }
    }
    if ($status === 'active' || $status === 'retired') {
        $where[]  = 'is_active = ?';
        $params[] = $status === 'active' ? 1 : 0;
    }
    $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $table    = db_name($def['table']);

    $paging['total'] = (int) db_value('SELECT COUNT(*) FROM ' . $table . $sqlWhere, $params);

    return db_all('SELECT * FROM ' . $table . $sqlWhere . ' ORDER BY ' . db_name($def['order']) . ', id LIMIT ? OFFSET ?',
        [...$params, $paging['per_page'], $paging['offset']]);
}

function master_find(string $type, int $id): array
{
    $def = master_definition($type);

    return db_row('SELECT * FROM ' . db_name($def['table']) . ' WHERE id = ?', [$id])
        ?? not_found(ucfirst($def['singular']) . ' not found.');
}

/**
 * Options of every select field: field => [value => label].
 *
 * @return array<string, array<string, string>>
 */
function master_select_options(string $type, bool $activeOnly = true): array
{
    $out = [];
    foreach (master_definition($type)['fields'] as $name => $field) {
        if (($field['type'] ?? '') === 'select') {
            $out[$name] = isset($field['lookup']) ? master_lookup($field['lookup'], $activeOnly) : $field['options'];
        }
    }

    return $out;
}

/** Creates a row; returns the new id. */
function master_create(string $type, array $input, array $actor): int
{
    $def    = master_definition($type);
    $values = master_validate($def, $input, null);
    $now    = now_str();

    return db_transaction(static function () use ($def, $values, $now, $actor): int {
        $id = db_insert($def['table'], $values + ['is_active' => 1, 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id']]);
        audit_log('CREATE', $def['table'], $id, null, $values, (string) $values[$def['code']]);

        return $id;
    });
}

/** Saves a row. The code is never changed. */
function master_update(string $type, int $id, array $input, array $actor): void
{
    $def    = master_definition($type);
    $before = master_find($type, $id);
    $values = master_validate($def, $input, $before);
    unset($values[$def['code']]);

    db_transaction(static function () use ($def, $id, $before, $values, $actor): void {
        db_update($def['table'], $values + ['updated_at' => now_str(), 'updated_by' => $actor['id']], 'id = ?', [$id]);
        audit_log('UPDATE', $def['table'], $id, array_intersect_key($before, $values), $values, (string) $before[$def['code']]);
    });
}

/** Retires an active row or re-activates a retired one; returns true when it is active now. */
function master_toggle(string $type, int $id, array $actor): bool
{
    $def    = master_definition($type);
    $before = master_find($type, $id);
    $active = (int) $before['is_active'] === 1 ? 0 : 1;

    db_transaction(static function () use ($def, $id, $before, $active, $actor): void {
        db_update($def['table'], ['is_active' => $active, 'updated_at' => now_str(), 'updated_by' => $actor['id']], 'id = ?', [$id]);
        audit_log($active === 1 ? 'ACTIVATE' : 'RETIRE', $def['table'], $id, ['is_active' => (int) $before['is_active']], ['is_active' => $active], (string) $before[$def['code']]);
    });

    return $active === 1;
}

/**
 * Validates the posted values of a definition and converts them for the database
 * (empty → NULL, numbers → int, HH:MM → HH:MM:00).
 */
function master_validate(array $def, array $input, ?array $existing): array
{
    $data  = [];
    $rules = [];
    foreach ($def['fields'] as $name => $field) {
        if ($existing !== null && $name === $def['code']) {
            continue;
        }
        $type        = $field['type'] ?? 'text';
        $raw         = $input[$name] ?? null;
        $data[$name] = $type === 'bool'
            ? ($raw === '1' || $raw === 1 || $raw === true ? '1' : '0')
            : (is_string($raw) ? trim($raw) : '');
        if (! empty($field['upper'])) {
            $data[$name] = strtoupper($data[$name]);
        }
        $rules[$name] = ['label' => $field['label'], 'rules' => $field['rules']];
    }
    validate_or_fail($data, $rules);

    if ($existing === null) {
        $taken = (int) db_value('SELECT COUNT(*) FROM ' . db_name($def['table']) . ' WHERE ' . db_name($def['code']) . ' = ?', [$data[$def['code']]]);
        if ($taken > 0) {
            fail_field($def['code'], 'This code already exists.');
        }
    }

    foreach ($def['fields'] as $name => $field) {
        if (! array_key_exists($name, $data)) {
            continue;
        }
        $type = $field['type'] ?? 'text';
        if ($data[$name] === '' && $type !== 'bool') {
            $data[$name] = null;
        } elseif ($type === 'select' && isset($field['lookup'])) {
            [$table] = MASTER_LOOKUPS[$field['lookup']];
            if ((int) db_value('SELECT COUNT(*) FROM ' . db_name($table) . ' WHERE id = ?', [(int) $data[$name]]) === 0) {
                fail_field($name, 'Choose a valid ' . strtolower($field['label']) . '.');
            }
            $data[$name] = (int) $data[$name];
        } elseif ($type === 'number' || $type === 'bool') {
            $data[$name] = (int) $data[$name];
        } elseif ($type === 'time' && strlen((string) $data[$name]) === 5) {
            $data[$name] .= ':00';
        }
    }

    return $data;
}

/** Display text of one list cell. */
function master_cell(array $def, array $options, string $column, array $row): string
{
    $field = $def['fields'][$column];
    $value = $row[$column] ?? null;
    if ($value === null || $value === '') {
        return '<span class="text-muted">—</span>';
    }

    return match ($field['type'] ?? 'text') {
        'select' => e($options[$column][(string) $value] ?? (string) $value),
        'bool'   => (int) $value === 1 ? 'Yes' : 'No',
        'date'   => e(plant_date((string) $value)),
        'time'   => e(substr((string) $value, 0, 5)),
        default  => e((string) $value),
    };
}

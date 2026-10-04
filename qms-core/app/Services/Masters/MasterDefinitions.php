<?php

namespace App\Services\Masters;

use App\Enums\ObservationType;

/**
 * Field definitions of the simple master tables handled by the generic engine.
 * Codes (the 'code' field) are immutable after creation; rows are retired, never deleted.
 */
final class MasterDefinitions
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $codeRule = 'required|max_length[%d]|regex_match[/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/]';

        return [
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
                    'default_observation_type' => ['label' => 'Default observation type', 'type' => 'select', 'options' => ObservationType::options(), 'rules' => 'required|in_list[' . implode(',', array_keys(ObservationType::options())) . ']'],
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
                    'skill_level'   => ['label' => 'Skill level (skill matrix)', 'type' => 'select', 'options' => ['0' => 'L0 – trainee', '1' => 'L1', '2' => 'L2', '3' => 'L3', '4' => 'L4 – can train others'], 'rules' => 'permit_empty|in_list[0,1,2,3,4]'],
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
                    'start_time' => ['label' => 'Start time', 'type' => 'time', 'rules' => 'required|regex_match[/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/]'],
                    'end_time'   => ['label' => 'End time', 'type' => 'time', 'rules' => 'required|regex_match[/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/]|differs[start_time]'],
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

    /**
     * How select lookups read their options: slug => [table, label SQL, order].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function lookups(): array
    {
        return [
            'departments' => ['departments', "CONCAT(code, ' · ', name)", 'code'],
            'units'       => ['units', "CONCAT(symbol, ' (', name, ')')", 'code'],
            'methods'     => ['inspection_methods', 'name', 'sort_order'],
            'gauge-types' => ['gauge_types', 'name', 'name'],
            'parts'        => ['parts', "CONCAT(part_number, ' · ', part_name)", 'part_number'],
            'machines'     => ['machines', "CONCAT(machine_code, ' · ', machine_name)", 'machine_code'],
            'employees'    => ['employees', "CONCAT(full_name, ' (', employee_code, ')')", 'full_name'],
            'shifts'       => ['shifts', "CONCAT('Shift ', code)", 'sort_order'],
            'report-types' => ['report_types', 'name', 'id'],
        ];
    }
}

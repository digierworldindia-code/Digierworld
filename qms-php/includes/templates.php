<?php
/**
 * Dynamic inspection templates: versions, sections, parameters, part / machine
 * mapping and publishing.
 *
 * Published and retired versions are controlled documents and cannot change
 * (the database triggers enforce this too); changes go into a new version.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/decimal.php';
require_once QMS_ROOT . '/includes/validate.php';

const TEMPLATE_STATUS_TONES = ['DRAFT' => 'muted', 'PUBLISHED' => 'pass', 'RETIRED' => 'muted'];
const TEMPLATE_HEADER_FIELDS = ['report_type_id', 'template_code', 'name', 'format_doc_no', 'format_rev_no', 'format_made_date',
    'format_rev_date', 'planned_rounds_per_shift', 'notes'];
const TEMPLATE_PARAM_FIELDS = ['id', 'section_id', 'parameter_id', 'name', 'specification_text', 'observation_type', 'unit_id', 'nominal',
    'lsl', 'usl', 'decimal_places', 'date_rule', 'inspection_method_id', 'gauge_type_id', 'gauge_required', 'observation_count',
    'is_mandatory', 'prefill_source', 'help_text'];

// ---------------------------------------------------------------- queries

/** Template list with filters type, status, q. */
function template_list(array $filters): array
{
    $where  = [];
    $params = [];
    if (ctype_digit((string) ($filters['type'] ?? ''))) {
        $where[]  = 't.report_type_id = ?';
        $params[] = (int) $filters['type'];
    }
    if (in_array($filters['status'] ?? '', ['DRAFT', 'PUBLISHED', 'RETIRED'], true)) {
        $where[]  = 't.status = ?';
        $params[] = $filters['status'];
    } elseif (($filters['status'] ?? '') === '') {
        $where[] = "t.status <> 'RETIRED'";
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = "(t.template_code LIKE ? ESCAPE '!' OR t.name LIKE ? ESCAPE '!')";
        array_push($params, db_like($filters['q']), db_like($filters['q']));
    }

    return db_all("SELECT t.*, rt.code AS type_code, rt.name AS type_name,
              (SELECT COUNT(*) FROM template_parameters tp JOIN template_sections s ON s.id = tp.section_id WHERE s.template_id = t.id) AS parameter_count,
              (SELECT GROUP_CONCAT(p.part_number ORDER BY p.part_number SEPARATOR ', ') FROM template_part_map pm JOIN parts p ON p.id = pm.part_id WHERE pm.template_id = t.id) AS part_list,
              (SELECT GROUP_CONCAT(m.machine_code ORDER BY m.machine_code SEPARATOR ', ') FROM template_machine_map mm JOIN machines m ON m.id = mm.machine_id WHERE mm.template_id = t.id) AS machine_list
         FROM inspection_templates t JOIN report_types rt ON rt.id = t.report_type_id"
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY t.template_code, t.version DESC', $params);
}

function template_find(int $id): array
{
    return db_row('SELECT t.*, rt.code AS type_code, rt.name AS type_name, rt.layout, u.username AS published_by_name
                     FROM inspection_templates t
                     JOIN report_types rt ON rt.id = t.report_type_id
                     LEFT JOIN users u ON u.id = t.published_by
                    WHERE t.id = ?', [$id]) ?? not_found('Template not found.');
}

/**
 * Sections with their parameters (and display names of unit, method, gauge type).
 */
function template_structure(int $templateId): array
{
    $sections = db_all('SELECT * FROM template_sections WHERE template_id = ? ORDER BY sort_order, id', [$templateId]);
    if ($sections === []) {
        return [];
    }
    $ids    = array_column($sections, 'id');
    $params = db_all('SELECT tp.*, ip.code AS parameter_code, u.symbol AS unit_symbol, m.name AS method_name, m.short_label AS method_label,
                             gt.name AS gauge_type_name
                        FROM template_parameters tp
                        JOIN inspection_parameters ip ON ip.id = tp.parameter_id
                        LEFT JOIN units u ON u.id = tp.unit_id
                        LEFT JOIN inspection_methods m ON m.id = tp.inspection_method_id
                        LEFT JOIN gauge_types gt ON gt.id = tp.gauge_type_id
                       WHERE tp.section_id IN (' . db_in($ids) . ')
                       ORDER BY tp.sort_order, tp.id', $ids);

    $bySection = [];
    foreach ($params as $param) {
        $bySection[$param['section_id']][] = $param;
    }
    foreach ($sections as &$section) {
        $section['parameters'] = $bySection[$section['id']] ?? [];
    }

    return $sections;
}

/** @return array{parts: list<int>, machines: list<int>} */
function template_mapping(int $templateId): array
{
    return [
        'parts'    => array_map('intval', db_column('SELECT part_id FROM template_part_map WHERE template_id = ? ORDER BY part_id', [$templateId])),
        'machines' => array_map('intval', db_column('SELECT machine_id FROM template_machine_map WHERE template_id = ? ORDER BY machine_id', [$templateId])),
    ];
}

function template_usage(int $templateId): int
{
    return (int) db_value('SELECT COUNT(*) FROM inspection_reports WHERE template_id = ?', [$templateId]);
}

function template_versions(string $code): array
{
    return db_all('SELECT id, version, status, published_at, retired_at FROM inspection_templates WHERE template_code = ? ORDER BY version DESC', [$code]);
}

function template_report_types(): array
{
    return db_all('SELECT * FROM report_types WHERE is_active = 1 ORDER BY id');
}

function template_ref(array $template): string
{
    return $template['template_code'] . ' v' . $template['version'];
}

/** Specification text for the template table: "6.40 – 6.60 mm", "OK", "record". */
function template_spec_text(array $p): string
{
    $unit = $p['unit_symbol'] !== null ? ' ' . $p['unit_symbol'] : '';
    $dp   = (int) $p['decimal_places'];

    return match ($p['observation_type']) {
        'NUMERIC', 'PERCENTAGE' => match (true) {
            $p['lsl'] !== null && $p['usl'] !== null => qms_decimal($p['lsl'], $dp) . ' – ' . qms_decimal($p['usl'], $dp) . $unit,
            $p['lsl'] !== null                       => '≥ ' . qms_decimal($p['lsl'], $dp) . $unit,
            $p['usl'] !== null                       => '≤ ' . qms_decimal($p['usl'], $dp) . $unit,
            default                                  => 'record only' . $unit,
        },
        'OK_NOT_OK', 'VISUAL' => 'OK',
        'GO_NO_GO'            => 'GO',
        'DATE'                => $p['date_rule'] === 'ON_OR_AFTER_INSPECTION_DATE' ? 'not before inspection date' : 'date',
        default               => 'record',
    };
}

// ---------------------------------------------------------------- header

function template_create(array $input, array $actor): int
{
    $data = template_validate_header($input, null);
    if ((int) db_value('SELECT COUNT(*) FROM inspection_templates WHERE template_code = ?', [$data['template_code']]) > 0) {
        fail_field('template_code', 'This template code exists already. Open it and use "New version" instead.');
    }
    $now = now_str();

    return db_transaction(static function () use ($data, $now, $actor): int {
        $id = db_insert('inspection_templates', $data + ['version' => 1, 'status' => 'DRAFT',
            'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id']]);
        audit_log('CREATE', 'template', $id, null, $data, $data['template_code'] . ' v1');

        return $id;
    });
}

function template_update_header(int $id, array $input, array $actor): void
{
    $template = template_draft($id);
    $data     = template_validate_header($input, $template);
    unset($data['template_code'], $data['report_type_id']);

    db_transaction(static function () use ($id, $template, $data, $actor): void {
        db_update('inspection_templates', $data + ['updated_at' => now_str(), 'updated_by' => $actor['id']], 'id = ?', [$id]);
        audit_log('UPDATE', 'template', $id, array_intersect_key($template, $data), $data, template_ref($template));
    });
}

function template_delete(int $id, array $actor): void
{
    $template = template_draft($id);
    if ((int) db_value('SELECT COUNT(*) FROM inspection_templates WHERE cloned_from_id = ?', [$id]) > 0) {
        fail_field('template', 'Another version was created from this draft; it cannot be deleted.');
    }

    db_transaction(static function () use ($id, $template): void {
        db_exec('DELETE FROM inspection_templates WHERE id = ?', [$id]); // sections, parameters and mapping cascade
        audit_log('DELETE', 'template', $id, ['template_code' => $template['template_code'], 'version' => $template['version']], null, template_ref($template));
    });
}

// ---------------------------------------------------------------- sections

function template_section_add(int $templateId, string $title, string $description, array $actor): int
{
    $template              = template_draft($templateId);
    [$title, $description] = template_validate_section($templateId, $title, $description, null);
    $sort                  = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM template_sections WHERE template_id = ?', [$templateId]) + 10;

    return db_transaction(static function () use ($templateId, $template, $title, $description, $sort, $actor): int {
        $now = now_str();
        $id  = db_insert('template_sections', ['template_id' => $templateId, 'title' => $title, 'description' => $description,
            'sort_order' => $sort, 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now]);
        audit_log('CREATE', 'template_section', $id, null, ['title' => $title], template_ref($template));

        return $id;
    });
}

function template_section_update(int $templateId, int $sectionId, string $title, string $description, array $actor): void
{
    $template              = template_draft($templateId);
    $section               = template_section($templateId, $sectionId);
    [$title, $description] = template_validate_section($templateId, $title, $description, $sectionId);

    db_transaction(static function () use ($sectionId, $section, $template, $title, $description, $actor): void {
        db_update('template_sections', ['title' => $title, 'description' => $description, 'updated_at' => now_str(), 'updated_by' => $actor['id']],
            'id = ?', [$sectionId]);
        audit_log('UPDATE', 'template_section', $sectionId, ['title' => $section['title'], 'description' => $section['description']],
            ['title' => $title, 'description' => $description], template_ref($template));
    });
}

function template_section_delete(int $templateId, int $sectionId, array $actor): void
{
    $template = template_draft($templateId);
    $section  = template_section($templateId, $sectionId);

    db_transaction(static function () use ($sectionId, $section, $template): void {
        db_exec('DELETE FROM template_sections WHERE id = ?', [$sectionId]);
        audit_log('DELETE', 'template_section', $sectionId, ['title' => $section['title']], null, template_ref($template));
    });
}

function template_section_move(int $templateId, int $sectionId, string $direction): void
{
    template_draft($templateId);
    template_section($templateId, $sectionId);
    $ids = array_map('intval', db_column('SELECT id FROM template_sections WHERE template_id = ? ORDER BY sort_order, id', [$templateId]));
    db_transaction(static fn () => template_reorder('template_sections', template_moved($ids, $sectionId, $direction)));
}

// ---------------------------------------------------------------- parameters

/** Creates (id 0) or updates a parameter; returns its id. */
function template_param_save(int $templateId, array $input, array $actor): int
{
    $template = template_draft($templateId);
    $paramId  = ctype_digit((string) ($input['id'] ?? '')) ? (int) $input['id'] : 0;
    $existing = $paramId > 0 ? template_param($templateId, $paramId) : null;
    $data     = template_validate_param($templateId, $input);
    $now      = now_str();

    return db_transaction(static function () use ($existing, $paramId, $data, $template, $now, $actor): int {
        $nextSort = static fn (): int => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM template_parameters WHERE section_id = ?', [$data['section_id']]) + 10;
        if ($existing === null) {
            $data['sort_order'] = $nextSort();
            $paramId            = db_insert('template_parameters', $data + ['created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now]);
            audit_log('CREATE', 'template_parameter', $paramId, null, $data, template_ref($template));
        } else {
            if ((int) $existing['section_id'] !== $data['section_id']) {
                $data['sort_order'] = $nextSort();
            }
            db_update('template_parameters', $data + ['updated_at' => $now, 'updated_by' => $actor['id']], 'id = ?', [$paramId]);
            audit_log('UPDATE', 'template_parameter', $paramId, array_intersect_key($existing, $data), $data, template_ref($template));
        }

        return $paramId;
    });
}

function template_param_delete(int $templateId, int $paramId, array $actor): void
{
    $template = template_draft($templateId);
    $param    = template_param($templateId, $paramId);

    db_transaction(static function () use ($paramId, $param, $template): void {
        db_exec('DELETE FROM template_parameters WHERE id = ?', [$paramId]);
        audit_log('DELETE', 'template_parameter', $paramId, ['name' => $param['name']], null, template_ref($template));
    });
}

function template_param_move(int $templateId, int $paramId, string $direction): void
{
    template_draft($templateId);
    $param = template_param($templateId, $paramId);
    $ids   = array_map('intval', db_column('SELECT id FROM template_parameters WHERE section_id = ? ORDER BY sort_order, id', [$param['section_id']]));
    db_transaction(static fn () => template_reorder('template_parameters', template_moved($ids, $paramId, $direction)));
}

// ---------------------------------------------------------------- mapping and life cycle

/**
 * Part / machine mapping. Allowed on drafts and published versions (it does not change history).
 *
 * @param list<string|int> $partIds
 * @param list<string|int> $machineIds
 */
function template_save_mapping(int $templateId, array $partIds, array $machineIds, array $actor): void
{
    $template = template_find($templateId);
    if ($template['status'] === 'RETIRED') {
        fail('A retired template cannot be mapped.', 423);
    }
    $partIds    = template_existing_ids('parts', $partIds);
    $machineIds = template_existing_ids('machines', $machineIds);
    if ($template['status'] === 'PUBLISHED') {
        template_assert_no_conflicts($template, $partIds, $machineIds);
    }
    $before = template_mapping($templateId);

    db_transaction(static function () use ($templateId, $template, $partIds, $machineIds, $before, $actor): void {
        $now = now_str();
        db_exec('DELETE FROM template_part_map WHERE template_id = ?', [$templateId]);
        db_exec('DELETE FROM template_machine_map WHERE template_id = ?', [$templateId]);
        foreach ($partIds as $id) {
            db_insert('template_part_map', ['template_id' => $templateId, 'part_id' => $id, 'created_at' => $now, 'created_by' => $actor['id']]);
        }
        foreach ($machineIds as $id) {
            db_insert('template_machine_map', ['template_id' => $templateId, 'machine_id' => $id, 'created_at' => $now, 'created_by' => $actor['id']]);
        }
        audit_log('MAPPING', 'template', $templateId, $before, ['parts' => $partIds, 'machines' => $machineIds], template_ref($template));
    });
}

/**
 * Publishes a draft: checks it, checks mapping conflicts and retires the
 * previously published version of the same code – in one transaction.
 */
function template_publish(int $templateId, array $actor): void
{
    $template  = template_draft($templateId);
    $structure = template_structure($templateId);
    $problems  = [];
    if ($structure === []) {
        $problems[] = 'Add at least one section.';
    }
    foreach ($structure as $section) {
        if ($section['parameters'] === []) {
            $problems[] = "Section \"{$section['title']}\" has no parameters.";
        }
    }
    if ($problems !== []) {
        throw new ValidationError(['template' => implode(' ', $problems)], implode(' ', $problems));
    }
    $mapping = template_mapping($templateId);
    template_assert_no_conflicts($template, $mapping['parts'], $mapping['machines']);

    db_transaction(static function () use ($templateId, $template, $actor): void {
        $now      = now_str();
        $previous = db_row("SELECT * FROM inspection_templates WHERE template_code = ? AND status = 'PUBLISHED' FOR UPDATE", [$template['template_code']]);
        if ($previous !== null) {
            db_update('inspection_templates', ['status' => 'RETIRED', 'retired_at' => $now, 'updated_at' => $now, 'updated_by' => $actor['id']], 'id = ?', [$previous['id']]);
            audit_log('RETIRE', 'template', (int) $previous['id'], ['status' => 'PUBLISHED'], ['status' => 'RETIRED', 'replaced_by_version' => $template['version']], template_ref($previous));
        }
        db_update('inspection_templates', ['status' => 'PUBLISHED', 'published_at' => $now, 'published_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id']],
            'id = ?', [$templateId]);
        audit_log('PUBLISH', 'template', $templateId, ['status' => 'DRAFT'], ['status' => 'PUBLISHED'], template_ref($template));
    });
}

function template_retire(int $templateId, array $actor): void
{
    $template = template_find($templateId);
    if ($template['status'] !== 'PUBLISHED') {
        fail('Only a published template can be retired.', 423);
    }

    db_transaction(static function () use ($templateId, $template, $actor): void {
        $now = now_str();
        db_update('inspection_templates', ['status' => 'RETIRED', 'retired_at' => $now, 'updated_at' => $now, 'updated_by' => $actor['id']], 'id = ?', [$templateId]);
        audit_log('RETIRE', 'template', $templateId, ['status' => 'PUBLISHED'], ['status' => 'RETIRED'], template_ref($template));
    });
}

/** Copies any version (header, sections, parameters, mapping) into a new DRAFT version; returns its id. */
function template_new_version(int $templateId, array $actor): int
{
    $source = template_find($templateId);
    $draft  = db_row("SELECT version FROM inspection_templates WHERE template_code = ? AND status = 'DRAFT'", [$source['template_code']]);
    if ($draft !== null) {
        fail_field('template', "Version {$draft['version']} of this template is already a draft. Edit that one.");
    }

    return db_transaction(static function () use ($source, $actor): int {
        $now     = now_str();
        $version = (int) db_value('SELECT MAX(version) FROM inspection_templates WHERE template_code = ? FOR UPDATE', [$source['template_code']]) + 1;
        $header  = array_intersect_key($source, array_flip(['report_type_id', 'template_code', 'name', 'format_doc_no', 'format_rev_no',
            'format_made_date', 'format_rev_date', 'planned_rounds_per_shift', 'notes']));
        $newId   = db_insert('inspection_templates', $header + ['version' => $version, 'status' => 'DRAFT', 'cloned_from_id' => $source['id'],
            'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id']]);

        foreach (template_structure((int) $source['id']) as $section) {
            $sectionId = db_insert('template_sections', ['template_id' => $newId, 'title' => $section['title'], 'description' => $section['description'],
                'sort_order' => $section['sort_order'], 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now]);
            foreach ($section['parameters'] as $param) {
                $copy = array_intersect_key($param, array_flip(['parameter_id', 'name', 'specification_text', 'observation_type', 'unit_id',
                    'nominal', 'lsl', 'usl', 'decimal_places', 'date_rule', 'inspection_method_id', 'gauge_type_id', 'gauge_required',
                    'observation_count', 'is_mandatory', 'prefill_source', 'help_text', 'sort_order']));
                db_insert('template_parameters', $copy + ['section_id' => $sectionId, 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now]);
            }
        }
        $mapping = template_mapping((int) $source['id']);
        foreach ($mapping['parts'] as $partId) {
            db_insert('template_part_map', ['template_id' => $newId, 'part_id' => $partId, 'created_at' => $now, 'created_by' => $actor['id']]);
        }
        foreach ($mapping['machines'] as $machineId) {
            db_insert('template_machine_map', ['template_id' => $newId, 'machine_id' => $machineId, 'created_at' => $now, 'created_by' => $actor['id']]);
        }
        audit_log('NEW_VERSION', 'template', $newId, ['from_version' => $source['version']], ['version' => $version], $source['template_code'] . ' v' . $version);

        return $newId;
    });
}

// ---------------------------------------------------------------- which template applies

/**
 * The published template for report type + part + machine, or null.
 *
 * A template applies to its mapped parts (none = all parts) and mapped machines
 * (none = all machines). The most specific match wins: part + machine (3) >
 * part only (2) > machine only (1) > all (0). Two matches with the same
 * specificity are a setup error (also blocked when publishing).
 */
function template_resolve(int $reportTypeId, int $partId, int $machineId): ?array
{
    $candidates = template_candidates($reportTypeId, $partId, $machineId);
    if ($candidates === []) {
        return null;
    }
    if (isset($candidates[1]) && (int) $candidates[1]['specificity'] === (int) $candidates[0]['specificity']) {
        fail_field('template', sprintf('Two published templates apply equally (%s and %s). Ask the QA Admin to fix the template mapping.',
            $candidates[0]['template_code'], $candidates[1]['template_code']));
    }

    return $candidates[0];
}

/** Matching published templates, best first (at most 2). */
function template_candidates(int $reportTypeId, int $partId, int $machineId): array
{
    return db_all("SELECT t.*,
                          (EXISTS (SELECT 1 FROM template_part_map pm WHERE pm.template_id = t.id)) * 2
                        + (EXISTS (SELECT 1 FROM template_machine_map mm WHERE mm.template_id = t.id)) AS specificity
                     FROM inspection_templates t
                    WHERE t.report_type_id = ? AND t.status = 'PUBLISHED'
                      AND (NOT EXISTS (SELECT 1 FROM template_part_map pm WHERE pm.template_id = t.id)
                           OR EXISTS (SELECT 1 FROM template_part_map pm WHERE pm.template_id = t.id AND pm.part_id = ?))
                      AND (NOT EXISTS (SELECT 1 FROM template_machine_map mm WHERE mm.template_id = t.id)
                           OR EXISTS (SELECT 1 FROM template_machine_map mm WHERE mm.template_id = t.id AND mm.machine_id = ?))
                    ORDER BY specificity DESC, t.id DESC
                    LIMIT 2", [$reportTypeId, $partId, $machineId]);
}

/** Active machines on which a published template of the type applies to the part. */
function template_machines_for(int $reportTypeId, int $partId): array
{
    $out = [];
    foreach (db_all('SELECT * FROM machines WHERE is_active = 1 ORDER BY machine_code') as $machine) {
        $candidates = template_candidates($reportTypeId, $partId, (int) $machine['id']);
        if ($candidates !== []) {
            $machine['template_code'] = $candidates[0]['template_code'];
            $machine['ambiguous']     = isset($candidates[1]) && (int) $candidates[1]['specificity'] === (int) $candidates[0]['specificity'];
            $out[]                    = $machine;
        }
    }

    return $out;
}

// ---------------------------------------------------------------- internal helpers

/** The template, but only while it is a draft (otherwise 423 Locked). */
function template_draft(int $id): array
{
    $template = template_find($id);
    if ($template['status'] !== 'DRAFT') {
        fail('Published and retired templates cannot be changed. Create a new version.', 423);
    }

    return $template;
}

function template_section(int $templateId, int $sectionId): array
{
    return db_row('SELECT * FROM template_sections WHERE id = ? AND template_id = ?', [$sectionId, $templateId])
        ?? not_found('Section not found in this template.');
}

function template_param(int $templateId, int $paramId): array
{
    return db_row('SELECT tp.* FROM template_parameters tp JOIN template_sections s ON s.id = tp.section_id WHERE tp.id = ? AND s.template_id = ?',
        [$paramId, $templateId]) ?? not_found('Parameter not found in this template.');
}

/** @param list<int> $ids */
function template_moved(array $ids, int $id, string $direction): array
{
    $index = array_search($id, $ids, true);
    if ($index !== false) {
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swap >= 0 && $swap < count($ids)) {
            [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
        }
    }

    return $ids;
}

/** @param list<int> $orderedIds */
function template_reorder(string $table, array $orderedIds): void
{
    foreach ($orderedIds as $i => $id) {
        db_update($table, ['sort_order' => ($i + 1) * 10], 'id = ?', [$id]);
    }
}

/** Ids from the form that exist in the table. */
function template_existing_ids(string $table, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, static fn ($v): bool => is_string($v) || is_int($v))))));
    if ($ids === []) {
        return [];
    }

    return array_map('intval', db_column('SELECT id FROM ' . db_name($table) . ' WHERE id IN (' . db_in($ids) . ') ORDER BY id', $ids));
}

/**
 * Two published templates of the same report type must never apply equally to
 * the same part + machine (see template_resolve()).
 */
function template_assert_no_conflicts(array $template, array $parts, array $machines): void
{
    $specificity = ($parts !== [] ? 2 : 0) + ($machines !== [] ? 1 : 0);
    $others      = db_all("SELECT * FROM inspection_templates WHERE report_type_id = ? AND status = 'PUBLISHED' AND template_code <> ?",
        [$template['report_type_id'], $template['template_code']]);

    foreach ($others as $other) {
        $map             = template_mapping((int) $other['id']);
        $spec            = ($map['parts'] !== [] ? 2 : 0) + ($map['machines'] !== [] ? 1 : 0);
        $partsOverlap    = $parts === [] || $map['parts'] === [] || array_intersect($parts, $map['parts']) !== [];
        $machinesOverlap = $machines === [] || $map['machines'] === [] || array_intersect($machines, $map['machines']) !== [];
        if ($spec === $specificity && $partsOverlap && $machinesOverlap) {
            fail_field('mapping', sprintf('Published template %s v%d already applies to the same parts/machines with the same specificity. Narrow the mapping of one of them.',
                $other['template_code'], $other['version']));
        }
    }
}

function template_validate_header(array $input, ?array $existing): array
{
    $s    = static fn (string $k, string $default = ''): string => is_string($input[$k] ?? null) ? trim($input[$k]) : $default;
    $data = [
        'report_type_id'           => (int) ($existing['report_type_id'] ?? (ctype_digit($s('report_type_id')) ? $s('report_type_id') : 0)),
        'template_code'            => strtoupper((string) ($existing['template_code'] ?? $s('template_code'))),
        'name'                     => $s('name'),
        'format_doc_no'            => $s('format_doc_no'),
        'format_rev_no'            => $s('format_rev_no', '00'),
        'format_made_date'         => $s('format_made_date'),
        'format_rev_date'          => $s('format_rev_date'),
        'planned_rounds_per_shift' => $s('planned_rounds_per_shift'),
        'notes'                    => $s('notes'),
    ];
    $errors = [];
    $type   = db_row('SELECT * FROM report_types WHERE id = ? AND is_active = 1', [$data['report_type_id']]);
    if ($type === null) {
        $errors['report_type_id'] = 'Choose a report type.';
    }
    if (! preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $data['template_code'])) {
        $errors['template_code'] = 'Code: 2-40 capital letters, digits, dash or underscore.';
    }
    if ($data['name'] === '' || mb_strlen($data['name']) > 150) {
        $errors['name'] = 'Enter a name (max 150 characters).';
    }
    if ($data['format_doc_no'] === '' || mb_strlen($data['format_doc_no']) > 40) {
        $errors['format_doc_no'] = 'Enter the controlled document number of the format (e.g. QA/F/01).';
    }
    if ($data['format_rev_no'] === '' || mb_strlen($data['format_rev_no']) > 10) {
        $errors['format_rev_no'] = 'Enter the revision number (max 10 characters).';
    }
    if (! validate_date($data['format_made_date'])) {
        $errors['format_made_date'] = 'Enter the make date of the format.';
    }
    if ($data['format_rev_date'] !== '' && (! validate_date($data['format_rev_date']) || $data['format_rev_date'] < $data['format_made_date'])) {
        $errors['format_rev_date'] = 'The revision date must be a date on or after the make date.';
    }
    if ($type !== null && $type['layout'] === 'SHIFT_GRID'
        && (! ctype_digit($data['planned_rounds_per_shift']) || (int) $data['planned_rounds_per_shift'] < 1 || (int) $data['planned_rounds_per_shift'] > 24)) {
        $errors['planned_rounds_per_shift'] = 'Enter the planned inspections per shift (1-24).';
    }
    if (mb_strlen($data['notes']) > 2000) {
        $errors['notes'] = 'Notes are limited to 2000 characters.';
    }
    if ($errors !== []) {
        throw new ValidationError($errors);
    }

    $data['format_rev_date']          = $data['format_rev_date'] === '' ? null : $data['format_rev_date'];
    $data['planned_rounds_per_shift'] = $type['layout'] === 'SHIFT_GRID' ? (int) $data['planned_rounds_per_shift'] : null;
    $data['notes']                    = $data['notes'] === '' ? null : $data['notes'];

    return $data;
}

/** @return array{0: string, 1: string|null} */
function template_validate_section(int $templateId, string $title, string $description, ?int $sectionId): array
{
    $title       = trim($title);
    $description = trim($description);
    if ($title === '' || mb_strlen($title) > 120) {
        fail_field('title', 'Enter a section title (max 120 characters).');
    }
    if (mb_strlen($description) > 255) {
        fail_field('description', 'The description is limited to 255 characters.');
    }
    $duplicate = (int) db_value('SELECT COUNT(*) FROM template_sections WHERE template_id = ? AND title = ? AND id <> ?',
        [$templateId, $title, $sectionId ?? 0]);
    if ($duplicate > 0) {
        fail_field('title', 'A section with this title exists already.');
    }

    return [$title, $description === '' ? null : $description];
}

/** Mirrors the database CHECK constraints with readable messages. */
function template_validate_param(int $templateId, array $input): array
{
    $errors = [];
    $s      = static fn (string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';

    $sectionId = ctype_digit($s('section_id')) ? (int) $s('section_id') : 0;
    if ((int) db_value('SELECT COUNT(*) FROM template_sections WHERE id = ? AND template_id = ?', [$sectionId, $templateId]) === 0) {
        $errors['section_id'] = 'Choose a section.';
    }
    $library = ctype_digit($s('parameter_id')) ? db_row('SELECT * FROM inspection_parameters WHERE id = ?', [(int) $s('parameter_id')]) : null;
    if ($library === null) {
        $errors['parameter_id'] = 'Choose a parameter from the library.';
    }
    $type = isset(OBSERVATION_TYPES[$s('observation_type')]) ? $s('observation_type') : null;
    if ($type === null) {
        $errors['observation_type'] = 'Choose an observation type.';
    }
    $name = $s('name') !== '' ? $s('name') : (string) ($library['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 150) {
        $errors['name'] = 'Enter the parameter name (max 150 characters).';
    }
    if (mb_strlen($s('specification_text')) > 150) {
        $errors['specification_text'] = 'The specification text is limited to 150 characters.';
    }
    if (mb_strlen($s('help_text')) > 255) {
        $errors['help_text'] = 'The help text is limited to 255 characters.';
    }

    $usesLimits = $type !== null && obs_uses_limits($type);
    $decimals   = ctype_digit($s('decimal_places')) ? (int) $s('decimal_places') : -1;
    $limits     = ['nominal' => null, 'lsl' => null, 'usl' => null];
    if ($usesLimits) {
        if ($decimals < 0 || $decimals > 6) {
            $errors['decimal_places'] = 'Decimals must be 0 to 6.';
        }
        foreach (array_keys($limits) as $key) {
            $value = $s($key);
            if ($value === '') {
                continue;
            }
            if (! dec_is($value)) {
                $errors[$key] = 'Enter a number such as 6.40.';
            } elseif ($decimals >= 0 && dec_places($value) > $decimals) {
                $errors[$key] = "Use at most {$decimals} decimals (or increase the decimals).";
            } elseif ($type === 'PERCENTAGE' && (dec_compare($value, '0') < 0 || dec_compare($value, '100') > 0)) {
                $errors[$key] = 'A percentage must be between 0 and 100.';
            } else {
                $limits[$key] = $value;
            }
        }
        if ($limits['lsl'] !== null && $limits['usl'] !== null && dec_compare($limits['lsl'], $limits['usl']) > 0) {
            $errors['usl'] = 'USL must be greater than or equal to LSL.';
        }
        if ($limits['nominal'] !== null && (($limits['lsl'] !== null && dec_compare($limits['nominal'], $limits['lsl']) < 0)
                || ($limits['usl'] !== null && dec_compare($limits['nominal'], $limits['usl']) > 0))) {
            $errors['nominal'] = 'The nominal must be within LSL and USL.';
        }
    }

    $dateRule = $s('date_rule') === 'ON_OR_AFTER_INSPECTION_DATE' && $type === 'DATE' ? 'ON_OR_AFTER_INSPECTION_DATE' : 'NONE';

    $optionalId = static function (string $table, string $field) use ($s, &$errors): ?int {
        $value = $s($field);
        if ($value === '') {
            return null;
        }
        if (! ctype_digit($value) || (int) db_value('SELECT COUNT(*) FROM ' . db_name($table) . ' WHERE id = ?', [(int) $value]) === 0) {
            $errors[$field] = 'Choose a valid option.';

            return null;
        }

        return (int) $value;
    };
    $unitId        = $optionalId('units', 'unit_id');
    $methodId      = $optionalId('inspection_methods', 'inspection_method_id');
    $gaugeTypeId   = $optionalId('gauge_types', 'gauge_type_id');
    $gaugeRequired = $s('gauge_required') === '1' ? 1 : 0;
    if ($gaugeRequired === 1 && $gaugeTypeId === null && ! isset($errors['gauge_type_id'])) {
        $errors['gauge_type_id'] = 'Choose the gauge type when a Gauge ID is required.';
    }

    $count = ctype_digit($s('observation_count')) ? (int) $s('observation_count') : 0;
    if ($count < 1 || $count > 10) {
        $errors['observation_count'] = 'Observations per round: 1 to 10.';
    }

    $prefill = in_array($s('prefill_source'), ['NONE', 'OPERATOR_SKILL_LEVEL', 'MACHINE_PM_DUE_DATE'], true) ? $s('prefill_source') : 'NONE';
    if (($prefill === 'OPERATOR_SKILL_LEVEL' && $type !== 'NUMERIC') || ($prefill === 'MACHINE_PM_DUE_DATE' && $type !== 'DATE')) {
        $errors['prefill_source'] = 'Operator skill level pre-fill needs a Numeric type; PM due date pre-fill needs a Date type.';
    }

    if ($errors !== []) {
        throw new ValidationError($errors);
    }

    return [
        'section_id'           => $sectionId,
        'parameter_id'         => (int) $library['id'],
        'name'                 => $name,
        'specification_text'   => $s('specification_text') === '' ? null : $s('specification_text'),
        'observation_type'     => $type,
        'unit_id'              => $unitId,
        'nominal'              => $limits['nominal'],
        'lsl'                  => $limits['lsl'],
        'usl'                  => $limits['usl'],
        'decimal_places'       => $usesLimits ? $decimals : 0,
        'date_rule'            => $dateRule,
        'inspection_method_id' => $methodId,
        'gauge_type_id'        => $gaugeTypeId,
        'gauge_required'       => $gaugeRequired,
        'observation_count'    => $count,
        'is_mandatory'         => $s('is_mandatory') === '1' ? 1 : 0,
        'prefill_source'       => $prefill,
        'help_text'            => $s('help_text') === '' ? null : $s('help_text'),
    ];
}

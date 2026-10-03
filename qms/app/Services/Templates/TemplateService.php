<?php

namespace App\Services\Templates;

use App\Enums\ObservationType;
use App\Exceptions\NotFoundException;
use App\Exceptions\RecordLockedException;
use App\Exceptions\ValidationException;
use App\Services\Audit\AuditService;
use App\Services\Inspections\DecimalComparator;
use App\Services\Support\Clock;
use App\Services\Support\TransactionRunner;
use CodeIgniter\Database\BaseConnection;

/**
 * Dynamic inspection templates: versions, sections, parameters, mapping,
 * publishing. Published and retired versions are controlled documents and
 * immutable (also enforced by database triggers); changes go into a new version.
 */
class TemplateService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit,
        private readonly Clock $clock,
        private readonly TransactionRunner $tx,
    ) {
    }

    // ------------------------------------------------------------------ queries

    /**
     * @param array<string, string> $filters type, status, q
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $filters): array
    {
        $builder = $this->db->table('inspection_templates t')
            ->select("t.*, rt.code AS type_code, rt.name AS type_name,
                (SELECT COUNT(*) FROM template_parameters tp JOIN template_sections s ON s.id = tp.section_id WHERE s.template_id = t.id) AS parameter_count,
                (SELECT GROUP_CONCAT(p.part_number ORDER BY p.part_number SEPARATOR ', ') FROM template_part_map pm JOIN parts p ON p.id = pm.part_id WHERE pm.template_id = t.id) AS part_list,
                (SELECT GROUP_CONCAT(m.machine_code ORDER BY m.machine_code SEPARATOR ', ') FROM template_machine_map mm JOIN machines m ON m.id = mm.machine_id WHERE mm.template_id = t.id) AS machine_list", false)
            ->join('report_types rt', 'rt.id = t.report_type_id');

        if (($filters['type'] ?? '') !== '') {
            $builder->where('t.report_type_id', (int) $filters['type']);
        }
        if (in_array($filters['status'] ?? '', ['DRAFT', 'PUBLISHED', 'RETIRED'], true)) {
            $builder->where('t.status', $filters['status']);
        } elseif (($filters['status'] ?? '') === '') {
            $builder->where('t.status !=', 'RETIRED');
        }
        if (($filters['q'] ?? '') !== '') {
            $builder->groupStart()->like('t.template_code', $filters['q'])->orLike('t.name', $filters['q'])->groupEnd();
        }

        return $builder->orderBy('t.template_code')->orderBy('t.version', 'DESC')->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $row = $this->db->table('inspection_templates t')
            ->select('t.*, rt.code AS type_code, rt.name AS type_name, rt.layout, u.username AS published_by_name')
            ->join('report_types rt', 'rt.id = t.report_type_id')
            ->join('users u', 'u.id = t.published_by', 'left')
            ->where('t.id', $id)->get(1)->getRowArray();

        return $row ?? throw new NotFoundException('Template not found.');
    }

    /**
     * Sections with their parameters (display names for unit, method, gauge type).
     *
     * @return list<array<string, mixed>>
     */
    public function structure(int $templateId): array
    {
        $sections = $this->db->table('template_sections')->where('template_id', $templateId)
            ->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
        if ($sections === []) {
            return [];
        }

        $params = $this->db->table('template_parameters tp')
            ->select('tp.*, ip.code AS parameter_code, u.symbol AS unit_symbol, m.name AS method_name, m.short_label AS method_label,
                      gt.name AS gauge_type_name')
            ->join('inspection_parameters ip', 'ip.id = tp.parameter_id')
            ->join('units u', 'u.id = tp.unit_id', 'left')
            ->join('inspection_methods m', 'm.id = tp.inspection_method_id', 'left')
            ->join('gauge_types gt', 'gt.id = tp.gauge_type_id', 'left')
            ->whereIn('tp.section_id', array_column($sections, 'id'))
            ->orderBy('tp.sort_order')->orderBy('tp.id')
            ->get()->getResultArray();

        $bySection = [];
        foreach ($params as $param) {
            $bySection[$param['section_id']][] = $param;
        }
        foreach ($sections as &$section) {
            $section['parameters'] = $bySection[$section['id']] ?? [];
        }

        return $sections;
    }

    /**
     * @return array{parts: list<int>, machines: list<int>}
     */
    public function mapping(int $templateId): array
    {
        return [
            'parts'    => array_map('intval', array_column($this->db->table('template_part_map')->select('part_id')->where('template_id', $templateId)->get()->getResultArray(), 'part_id')),
            'machines' => array_map('intval', array_column($this->db->table('template_machine_map')->select('machine_id')->where('template_id', $templateId)->get()->getResultArray(), 'machine_id')),
        ];
    }

    public function usage(int $templateId): int
    {
        return $this->db->table('inspection_reports')->where('template_id', $templateId)->countAllResults();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(string $code): array
    {
        return $this->db->table('inspection_templates')->select('id, version, status, published_at, retired_at')
            ->where('template_code', $code)->orderBy('version', 'DESC')->get()->getResultArray();
    }

    // ------------------------------------------------------------------ header

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     */
    public function create(array $input, array $actor): int
    {
        $data = $this->validateHeader($input, null);
        if ($this->db->table('inspection_templates')->where('template_code', $data['template_code'])->countAllResults() > 0) {
            throw ValidationException::single('template_code', 'This template code exists already. Open it and use "New version" instead.');
        }
        $now = $this->clock->nowUtcString();

        return $this->tx->run(function (BaseConnection $db) use ($data, $now, $actor): int {
            $db->table('inspection_templates')->insert($data + [
                'version' => 1, 'status' => 'DRAFT',
                'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id'],
            ]);
            $id = (int) $db->insertID();
            $this->audit->log('CREATE', 'template', $id, null, $data, $data['template_code'] . ' v1');

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     */
    public function updateHeader(int $id, array $input, array $actor): void
    {
        $template = $this->draft($id);
        $data     = $this->validateHeader($input, $template);
        unset($data['template_code'], $data['report_type_id']);

        $this->tx->run(function (BaseConnection $db) use ($id, $template, $data, $actor): void {
            $db->table('inspection_templates')->where('id', $id)->update($data + ['updated_at' => $this->clock->nowUtcString(), 'updated_by' => $actor['id']]);
            $this->audit->log('UPDATE', 'template', $id, array_intersect_key($template, $data), $data, $this->ref($template));
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function delete(int $id, array $actor): void
    {
        $template = $this->draft($id);
        if ($this->db->table('inspection_templates')->where('cloned_from_id', $id)->countAllResults() > 0) {
            throw ValidationException::single('template', 'Another version was created from this draft; it cannot be deleted.');
        }

        $this->tx->run(function (BaseConnection $db) use ($id, $template): void {
            $db->table('inspection_templates')->where('id', $id)->delete(); // sections, parameters, mapping cascade
            $this->audit->log('DELETE', 'template', $id, ['template_code' => $template['template_code'], 'version' => $template['version']], null, $this->ref($template));
        });
    }

    // ------------------------------------------------------------------ sections

    /**
     * @param array<string, mixed> $actor
     */
    public function addSection(int $templateId, string $title, string $description, array $actor): int
    {
        $template = $this->draft($templateId);
        [$title, $description] = $this->validateSection($templateId, $title, $description, null);
        $sort = (int) ($this->db->table('template_sections')->selectMax('sort_order', 'm')->where('template_id', $templateId)->get()->getRow('m') ?? 0) + 10;

        return $this->tx->run(function (BaseConnection $db) use ($templateId, $template, $title, $description, $sort, $actor): int {
            $now = $this->clock->nowUtcString();
            $db->table('template_sections')->insert([
                'template_id' => $templateId, 'title' => $title, 'description' => $description, 'sort_order' => $sort,
                'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now,
            ]);
            $id = (int) $db->insertID();
            $this->audit->log('CREATE', 'template_section', $id, null, ['title' => $title], $this->ref($template));

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function updateSection(int $templateId, int $sectionId, string $title, string $description, array $actor): void
    {
        $template = $this->draft($templateId);
        $section  = $this->section($templateId, $sectionId);
        [$title, $description] = $this->validateSection($templateId, $title, $description, $sectionId);

        $this->tx->run(function (BaseConnection $db) use ($sectionId, $section, $template, $title, $description, $actor): void {
            $db->table('template_sections')->where('id', $sectionId)->update([
                'title' => $title, 'description' => $description, 'updated_at' => $this->clock->nowUtcString(), 'updated_by' => $actor['id'],
            ]);
            $this->audit->log('UPDATE', 'template_section', $sectionId, ['title' => $section['title'], 'description' => $section['description']],
                ['title' => $title, 'description' => $description], $this->ref($template));
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function deleteSection(int $templateId, int $sectionId, array $actor): void
    {
        $template = $this->draft($templateId);
        $section  = $this->section($templateId, $sectionId);

        $this->tx->run(function (BaseConnection $db) use ($sectionId, $section, $template): void {
            $db->table('template_sections')->where('id', $sectionId)->delete();
            $this->audit->log('DELETE', 'template_section', $sectionId, ['title' => $section['title']], null, $this->ref($template));
        });
    }

    public function moveSection(int $templateId, int $sectionId, string $direction): void
    {
        $this->draft($templateId);
        $this->section($templateId, $sectionId);
        $ids = array_map('intval', array_column($this->db->table('template_sections')->select('id')->where('template_id', $templateId)
            ->orderBy('sort_order')->orderBy('id')->get()->getResultArray(), 'id'));
        $this->tx->run(fn () => $this->reorder('template_sections', $this->moved($ids, $sectionId, $direction)));
    }

    // ------------------------------------------------------------------ parameters

    /**
     * Creates (id = 0) or updates a parameter.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     */
    public function saveParameter(int $templateId, array $input, array $actor): int
    {
        $template = $this->draft($templateId);
        $paramId  = (int) ($input['id'] ?? 0);
        $existing = null;
        if ($paramId > 0) {
            $existing = $this->db->table('template_parameters tp')->select('tp.*')
                ->join('template_sections s', 's.id = tp.section_id')
                ->where('tp.id', $paramId)->where('s.template_id', $templateId)->get(1)->getRowArray()
                ?? throw new NotFoundException('Parameter not found in this template.');
        }
        $data = $this->validateParameter($templateId, $input);
        $now  = $this->clock->nowUtcString();

        return $this->tx->run(function (BaseConnection $db) use ($existing, $paramId, $data, $template, $now, $actor): int {
            if ($existing === null) {
                $data['sort_order'] = (int) ($db->table('template_parameters')->selectMax('sort_order', 'm')->where('section_id', $data['section_id'])->get()->getRow('m') ?? 0) + 10;
                $db->table('template_parameters')->insert($data + ['created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now]);
                $paramId = (int) $db->insertID();
                $this->audit->log('CREATE', 'template_parameter', $paramId, null, $data, $this->ref($template));
            } else {
                if ((int) $existing['section_id'] !== (int) $data['section_id']) {
                    $data['sort_order'] = (int) ($db->table('template_parameters')->selectMax('sort_order', 'm')->where('section_id', $data['section_id'])->get()->getRow('m') ?? 0) + 10;
                }
                $db->table('template_parameters')->where('id', $paramId)->update($data + ['updated_at' => $now, 'updated_by' => $actor['id']]);
                $this->audit->log('UPDATE', 'template_parameter', $paramId, array_intersect_key($existing, $data), $data, $this->ref($template));
            }

            return $paramId;
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function deleteParameter(int $templateId, int $paramId, array $actor): void
    {
        $template = $this->draft($templateId);
        $param    = $this->parameter($templateId, $paramId);

        $this->tx->run(function (BaseConnection $db) use ($paramId, $param, $template): void {
            $db->table('template_parameters')->where('id', $paramId)->delete();
            $this->audit->log('DELETE', 'template_parameter', $paramId, ['name' => $param['name']], null, $this->ref($template));
        });
    }

    public function moveParameter(int $templateId, int $paramId, string $direction): void
    {
        $this->draft($templateId);
        $param = $this->parameter($templateId, $paramId);
        $ids   = array_map('intval', array_column($this->db->table('template_parameters')->select('id')->where('section_id', $param['section_id'])
            ->orderBy('sort_order')->orderBy('id')->get()->getResultArray(), 'id'));
        $this->tx->run(fn () => $this->reorder('template_parameters', $this->moved($ids, $paramId, $direction)));
    }

    // ------------------------------------------------------------------ mapping & lifecycle

    /**
     * Mapping may change on drafts and on published versions (it does not alter history).
     *
     * @param list<int>             $partIds
     * @param list<int>             $machineIds
     * @param array<string, mixed>  $actor
     */
    public function saveMapping(int $templateId, array $partIds, array $machineIds, array $actor): void
    {
        $template = $this->find($templateId);
        if ($template['status'] === 'RETIRED') {
            throw new RecordLockedException('A retired template cannot be mapped.');
        }
        $partIds    = $this->existingIds('parts', $partIds);
        $machineIds = $this->existingIds('machines', $machineIds);

        if ($template['status'] === 'PUBLISHED') {
            $this->assertNoConflicts($template, $partIds, $machineIds);
        }
        $before = $this->mapping($templateId);

        $this->tx->run(function (BaseConnection $db) use ($templateId, $template, $partIds, $machineIds, $before, $actor): void {
            $now = $this->clock->nowUtcString();
            $db->table('template_part_map')->where('template_id', $templateId)->delete();
            $db->table('template_machine_map')->where('template_id', $templateId)->delete();
            if ($partIds !== []) {
                $db->table('template_part_map')->insertBatch(array_map(static fn (int $id): array => ['template_id' => $templateId, 'part_id' => $id, 'created_at' => $now, 'created_by' => $actor['id']], $partIds));
            }
            if ($machineIds !== []) {
                $db->table('template_machine_map')->insertBatch(array_map(static fn (int $id): array => ['template_id' => $templateId, 'machine_id' => $id, 'created_at' => $now, 'created_by' => $actor['id']], $machineIds));
            }
            $this->audit->log('MAPPING', 'template', $templateId, $before, ['parts' => $partIds, 'machines' => $machineIds], $this->ref($template));
        });
    }

    /**
     * Publishes a draft: validates it, checks mapping conflicts, retires the
     * previously published version of the same code, all in one transaction.
     *
     * @param array<string, mixed> $actor
     */
    public function publish(int $templateId, array $actor): void
    {
        $template  = $this->draft($templateId);
        $structure = $this->structure($templateId);
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
            throw new ValidationException(['template' => implode(' ', $problems)], implode(' ', $problems));
        }

        $mapping = $this->mapping($templateId);
        $this->assertNoConflicts($template, $mapping['parts'], $mapping['machines']);

        $this->tx->run(function (BaseConnection $db) use ($templateId, $template, $actor): void {
            $now      = $this->clock->nowUtcString();
            $previous = $db->table('inspection_templates')->where('template_code', $template['template_code'])
                ->where('status', 'PUBLISHED')->get(1)->getRowArray();
            if ($previous !== null) {
                $db->table('inspection_templates')->where('id', $previous['id'])->update(['status' => 'RETIRED', 'retired_at' => $now, 'updated_at' => $now, 'updated_by' => $actor['id']]);
                $this->audit->log('RETIRE', 'template', (int) $previous['id'], ['status' => 'PUBLISHED'], ['status' => 'RETIRED', 'replaced_by_version' => $template['version']], $this->ref($previous));
            }
            $db->table('inspection_templates')->where('id', $templateId)->update([
                'status' => 'PUBLISHED', 'published_at' => $now, 'published_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id'],
            ]);
            $this->audit->log('PUBLISH', 'template', $templateId, ['status' => 'DRAFT'], ['status' => 'PUBLISHED'], $this->ref($template));
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function retire(int $templateId, array $actor): void
    {
        $template = $this->find($templateId);
        if ($template['status'] !== 'PUBLISHED') {
            throw new RecordLockedException('Only a published template can be retired.');
        }

        $this->tx->run(function (BaseConnection $db) use ($templateId, $template, $actor): void {
            $now = $this->clock->nowUtcString();
            $db->table('inspection_templates')->where('id', $templateId)->update(['status' => 'RETIRED', 'retired_at' => $now, 'updated_at' => $now, 'updated_by' => $actor['id']]);
            $this->audit->log('RETIRE', 'template', $templateId, ['status' => 'PUBLISHED'], ['status' => 'RETIRED'], $this->ref($template));
        });
    }

    /**
     * Copies any version (header, sections, parameters, mapping) into a new DRAFT version.
     *
     * @param array<string, mixed> $actor
     */
    public function newVersion(int $templateId, array $actor): int
    {
        $source = $this->find($templateId);
        $draft  = $this->db->table('inspection_templates')->where('template_code', $source['template_code'])->where('status', 'DRAFT')->get(1)->getRowArray();
        if ($draft !== null) {
            throw ValidationException::single('template', "Version {$draft['version']} of this template is already a draft. Edit that one.");
        }

        return $this->tx->run(function (BaseConnection $db) use ($source, $actor): int {
            $now     = $this->clock->nowUtcString();
            $version = (int) $db->table('inspection_templates')->selectMax('version', 'v')->where('template_code', $source['template_code'])->get()->getRow('v') + 1;
            $header  = array_intersect_key($source, array_flip(['report_type_id', 'template_code', 'name', 'format_doc_no', 'format_rev_no',
                'format_made_date', 'format_rev_date', 'planned_rounds_per_shift', 'notes']));
            $db->table('inspection_templates')->insert($header + [
                'version' => $version, 'status' => 'DRAFT', 'cloned_from_id' => $source['id'],
                'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id'],
            ]);
            $newId = (int) $db->insertID();

            foreach ($this->structure((int) $source['id']) as $section) {
                $db->table('template_sections')->insert([
                    'template_id' => $newId, 'title' => $section['title'], 'description' => $section['description'],
                    'sort_order' => $section['sort_order'], 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now,
                ]);
                $sectionId = (int) $db->insertID();
                foreach ($section['parameters'] as $param) {
                    $copy = array_intersect_key($param, array_flip(['parameter_id', 'name', 'specification_text', 'observation_type', 'unit_id',
                        'nominal', 'lsl', 'usl', 'decimal_places', 'date_rule', 'inspection_method_id', 'gauge_type_id', 'gauge_required',
                        'observation_count', 'is_mandatory', 'prefill_source', 'help_text', 'sort_order']));
                    $db->table('template_parameters')->insert($copy + ['section_id' => $sectionId, 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now]);
                }
            }
            $mapping = $this->mapping((int) $source['id']);
            foreach ($mapping['parts'] as $partId) {
                $db->table('template_part_map')->insert(['template_id' => $newId, 'part_id' => $partId, 'created_at' => $now, 'created_by' => $actor['id']]);
            }
            foreach ($mapping['machines'] as $machineId) {
                $db->table('template_machine_map')->insert(['template_id' => $newId, 'machine_id' => $machineId, 'created_at' => $now, 'created_by' => $actor['id']]);
            }
            $this->audit->log('NEW_VERSION', 'template', $newId, ['from_version' => $source['version']], ['version' => $version], $source['template_code'] . ' v' . $version);

            return $newId;
        });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function draft(int $id): array
    {
        $template = $this->find($id);
        if ($template['status'] !== 'DRAFT') {
            throw new RecordLockedException('Published and retired templates cannot be changed. Create a new version.');
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    private function section(int $templateId, int $sectionId): array
    {
        return $this->db->table('template_sections')->where('id', $sectionId)->where('template_id', $templateId)->get(1)->getRowArray()
            ?? throw new NotFoundException('Section not found in this template.');
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(int $templateId, int $paramId): array
    {
        return $this->db->table('template_parameters tp')->select('tp.*')
            ->join('template_sections s', 's.id = tp.section_id')
            ->where('tp.id', $paramId)->where('s.template_id', $templateId)->get(1)->getRowArray()
            ?? throw new NotFoundException('Parameter not found in this template.');
    }

    /**
     * @param array<string, mixed> $template
     */
    private function ref(array $template): string
    {
        return $template['template_code'] . ' v' . $template['version'];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function moved(array $ids, int $id, string $direction): array
    {
        $index = array_search($id, $ids, true);
        $swap  = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index !== false && $swap >= 0 && $swap < count($ids)) {
            [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
        }

        return $ids;
    }

    /**
     * @param list<int> $orderedIds
     */
    private function reorder(string $table, array $orderedIds): void
    {
        foreach ($orderedIds as $i => $id) {
            $this->db->table($table)->where('id', $id)->update(['sort_order' => ($i + 1) * 10]);
        }
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<int>
     */
    private function existingIds(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        return array_map('intval', array_column($this->db->table($table)->select('id')->whereIn('id', $ids)->get()->getResultArray(), 'id'));
    }

    /**
     * Two published templates of the same report type must never apply equally to
     * the same part + machine (see TemplateResolver).
     *
     * @param array<string, mixed> $template
     * @param list<int>            $parts
     * @param list<int>            $machines
     */
    private function assertNoConflicts(array $template, array $parts, array $machines): void
    {
        $specificity = ($parts !== [] ? 2 : 0) + ($machines !== [] ? 1 : 0);
        $others      = $this->db->table('inspection_templates')
            ->where('report_type_id', $template['report_type_id'])
            ->where('status', 'PUBLISHED')
            ->where('template_code !=', $template['template_code'])
            ->get()->getResultArray();

        foreach ($others as $other) {
            $map   = $this->mapping((int) $other['id']);
            $spec  = ($map['parts'] !== [] ? 2 : 0) + ($map['machines'] !== [] ? 1 : 0);
            $parts_overlap    = $parts === [] || $map['parts'] === [] || array_intersect($parts, $map['parts']) !== [];
            $machines_overlap = $machines === [] || $map['machines'] === [] || array_intersect($machines, $map['machines']) !== [];
            if ($spec === $specificity && $parts_overlap && $machines_overlap) {
                throw ValidationException::single('mapping', sprintf(
                    'Published template %s v%d already applies to the same parts/machines with the same specificity. Narrow the mapping of one of them.',
                    $other['template_code'],
                    $other['version'],
                ));
            }
        }
    }

    /**
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function validateHeader(array $input, ?array $existing): array
    {
        $data = [
            'report_type_id'           => (int) ($existing['report_type_id'] ?? ($input['report_type_id'] ?? 0)),
            'template_code'            => strtoupper(trim((string) ($existing['template_code'] ?? ($input['template_code'] ?? '')))),
            'name'                     => trim((string) ($input['name'] ?? '')),
            'format_doc_no'            => trim((string) ($input['format_doc_no'] ?? '')),
            'format_rev_no'            => trim((string) ($input['format_rev_no'] ?? '00')),
            'format_made_date'         => trim((string) ($input['format_made_date'] ?? '')),
            'format_rev_date'          => trim((string) ($input['format_rev_date'] ?? '')),
            'planned_rounds_per_shift' => trim((string) ($input['planned_rounds_per_shift'] ?? '')),
            'notes'                    => trim((string) ($input['notes'] ?? '')),
        ];
        $errors = [];
        $type   = $this->db->table('report_types')->where('id', $data['report_type_id'])->where('is_active', 1)->get(1)->getRowArray();
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
        if (! $this->validDate($data['format_made_date'])) {
            $errors['format_made_date'] = 'Enter the make date of the format.';
        }
        if ($data['format_rev_date'] !== '' && (! $this->validDate($data['format_rev_date']) || $data['format_rev_date'] < $data['format_made_date'])) {
            $errors['format_rev_date'] = 'The revision date must be a date on or after the make date.';
        }
        if ($type !== null && $type['layout'] === 'SHIFT_GRID') {
            if (! ctype_digit($data['planned_rounds_per_shift']) || (int) $data['planned_rounds_per_shift'] < 1 || (int) $data['planned_rounds_per_shift'] > 24) {
                $errors['planned_rounds_per_shift'] = 'Enter the planned inspections per shift (1-24).';
            }
        }
        if (mb_strlen($data['notes']) > 2000) {
            $errors['notes'] = 'Notes are limited to 2000 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $data['format_rev_date']          = $data['format_rev_date'] === '' ? null : $data['format_rev_date'];
        $data['planned_rounds_per_shift'] = $type['layout'] === 'SHIFT_GRID' ? (int) $data['planned_rounds_per_shift'] : null;
        $data['notes']                    = $data['notes'] === '' ? null : $data['notes'];

        return $data;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function validateSection(int $templateId, string $title, string $description, ?int $sectionId): array
    {
        $title       = trim($title);
        $description = trim($description);
        if ($title === '' || mb_strlen($title) > 120) {
            throw ValidationException::single('title', 'Enter a section title (max 120 characters).');
        }
        if (mb_strlen($description) > 255) {
            throw ValidationException::single('description', 'The description is limited to 255 characters.');
        }
        $dup = $this->db->table('template_sections')->where('template_id', $templateId)->where('title', $title);
        if ($sectionId !== null) {
            $dup->where('id !=', $sectionId);
        }
        if ($dup->countAllResults() > 0) {
            throw ValidationException::single('title', 'A section with this title exists already.');
        }

        return [$title, $description === '' ? null : $description];
    }

    /**
     * Mirrors the database CHECK constraints with readable messages.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function validateParameter(int $templateId, array $input): array
    {
        $errors = [];
        $s      = static fn (string $k): string => trim((string) ($input[$k] ?? ''));

        $sectionId = (int) $s('section_id');
        if ($this->db->table('template_sections')->where('id', $sectionId)->where('template_id', $templateId)->countAllResults() === 0) {
            $errors['section_id'] = 'Choose a section.';
        }
        $library = $this->db->table('inspection_parameters')->where('id', (int) $s('parameter_id'))->get(1)->getRowArray();
        if ($library === null) {
            $errors['parameter_id'] = 'Choose a parameter from the library.';
        }
        $type = ObservationType::tryFrom($s('observation_type'));
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

        $decimals = ctype_digit($s('decimal_places')) ? (int) $s('decimal_places') : -1;
        $limits   = ['nominal' => null, 'lsl' => null, 'usl' => null];
        if ($type !== null && $type->usesLimits()) {
            if ($decimals < 0 || $decimals > 6) {
                $errors['decimal_places'] = 'Decimals must be 0 to 6.';
            }
            foreach (array_keys($limits) as $key) {
                $value = $s($key);
                if ($value === '') {
                    continue;
                }
                if (! DecimalComparator::isDecimal($value)) {
                    $errors[$key] = 'Enter a number such as 6.40.';
                } elseif ($decimals >= 0 && DecimalComparator::decimals($value) > $decimals) {
                    $errors[$key] = "Use at most {$decimals} decimals (or increase the decimals).";
                } elseif ($type === ObservationType::Percentage && (DecimalComparator::compare($value, '0') < 0 || DecimalComparator::compare($value, '100') > 0)) {
                    $errors[$key] = 'A percentage must be between 0 and 100.';
                } else {
                    $limits[$key] = $value;
                }
            }
            if ($limits['lsl'] !== null && $limits['usl'] !== null && DecimalComparator::compare($limits['lsl'], $limits['usl']) > 0) {
                $errors['usl'] = 'USL must be greater than or equal to LSL.';
            }
            if ($limits['nominal'] !== null && (($limits['lsl'] !== null && DecimalComparator::compare($limits['nominal'], $limits['lsl']) < 0)
                    || ($limits['usl'] !== null && DecimalComparator::compare($limits['nominal'], $limits['usl']) > 0))) {
                $errors['nominal'] = 'The nominal must be within LSL and USL.';
            }
        }

        $dateRule = $s('date_rule') === 'ON_OR_AFTER_INSPECTION_DATE' && $type === ObservationType::Date ? 'ON_OR_AFTER_INSPECTION_DATE' : 'NONE';

        $unitId   = $this->optionalId('units', $s('unit_id'), 'unit_id', $errors);
        $methodId = $this->optionalId('inspection_methods', $s('inspection_method_id'), 'inspection_method_id', $errors);
        $gaugeId  = $this->optionalId('gauge_types', $s('gauge_type_id'), 'gauge_type_id', $errors);
        $gaugeRequired = $s('gauge_required') === '1' ? 1 : 0;
        if ($gaugeRequired === 1 && $gaugeId === null) {
            $errors['gauge_type_id'] = 'Choose the gauge type when a Gauge ID is required.';
        }

        $count = ctype_digit($s('observation_count')) ? (int) $s('observation_count') : 0;
        if ($count < 1 || $count > 10) {
            $errors['observation_count'] = 'Observations per round: 1 to 10.';
        }

        $prefill = $s('prefill_source');
        if (! in_array($prefill, ['NONE', 'OPERATOR_SKILL_LEVEL', 'MACHINE_PM_DUE_DATE'], true)) {
            $prefill = 'NONE';
        }
        if (($prefill === 'OPERATOR_SKILL_LEVEL' && $type !== ObservationType::Numeric) || ($prefill === 'MACHINE_PM_DUE_DATE' && $type !== ObservationType::Date)) {
            $errors['prefill_source'] = 'Operator skill level pre-fill needs a Numeric type; PM due date pre-fill needs a Date type.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'section_id'           => $sectionId,
            'parameter_id'         => (int) $library['id'],
            'name'                 => $name,
            'specification_text'   => $s('specification_text') === '' ? null : $s('specification_text'),
            'observation_type'     => $type->value,
            'unit_id'              => $unitId,
            'nominal'              => $limits['nominal'],
            'lsl'                  => $limits['lsl'],
            'usl'                  => $limits['usl'],
            'decimal_places'       => $type->usesLimits() ? $decimals : 0,
            'date_rule'            => $dateRule,
            'inspection_method_id' => $methodId,
            'gauge_type_id'        => $gaugeId,
            'gauge_required'       => $gaugeRequired,
            'observation_count'    => $count,
            'is_mandatory'         => $s('is_mandatory') === '1' ? 1 : 0,
            'prefill_source'       => $prefill,
            'help_text'            => $s('help_text') === '' ? null : $s('help_text'),
        ];
    }

    /**
     * @param array<string, string> $errors
     */
    private function optionalId(string $table, string $value, string $field, array &$errors): ?int
    {
        if ($value === '') {
            return null;
        }
        if (! ctype_digit($value) || $this->db->table($table)->where('id', (int) $value)->countAllResults() === 0) {
            $errors[$field] = 'Choose a valid option.';

            return null;
        }

        return (int) $value;
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}

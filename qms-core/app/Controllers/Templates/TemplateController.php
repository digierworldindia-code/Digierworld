<?php

namespace App\Controllers\Templates;

use App\Controllers\BaseController;
use App\Enums\ObservationType;
use Throwable;

class TemplateController extends BaseController
{
    private const HEADER = ['report_type_id', 'template_code', 'name', 'format_doc_no', 'format_rev_no', 'format_made_date', 'format_rev_date', 'planned_rounds_per_shift', 'notes'];
    private const PARAM  = ['id', 'section_id', 'parameter_id', 'name', 'specification_text', 'observation_type', 'unit_id', 'nominal', 'lsl', 'usl',
        'decimal_places', 'date_rule', 'inspection_method_id', 'gauge_type_id', 'gauge_required', 'observation_count', 'is_mandatory', 'prefill_source', 'help_text'];

    public function index(): string
    {
        $filters = array_map('strval', (array) $this->request->getGet(['type', 'status', 'q']));

        return $this->render('templates/index', [
            'title'       => 'Inspection templates',
            'templates'   => service('templates')->list($filters),
            'reportTypes' => db_connect()->table('report_types')->where('is_active', 1)->orderBy('id')->get()->getResultArray(),
            'filters'     => $filters,
        ]);
    }

    public function new(): string
    {
        return $this->render('templates/new', [
            'title'       => 'New template',
            'reportTypes' => db_connect()->table('report_types')->where('is_active', 1)->orderBy('id')->get()->getResultArray(),
            'errors'      => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function create()
    {
        try {
            $id = service('templates')->create((array) $this->request->getPost(self::HEADER), $this->currentUser());
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }

        return redirect()->to(site_url('templates/' . $id))->with('success', 'Template created. Add sections and parameters, map it to parts / machines, then publish it.');
    }

    public function show(int $id): string
    {
        $service  = service('templates');
        $template = $service->find($id);
        $masters  = service('masterData');
        $db       = db_connect();

        return $this->render('templates/show', [
            'title'      => $template['template_code'] . ' v' . $template['version'],
            'template'   => $template,
            'structure'  => $service->structure($id),
            'mapping'    => $service->mapping($id),
            'usage'      => $service->usage($id),
            'versions'   => $service->versions($template['template_code']),
            'editable'   => $template['status'] === 'DRAFT' && can('template.manage'),
            'library'    => $db->table('inspection_parameters')->select('id, code, name, default_observation_type, default_unit_id, default_method_id, default_gauge_type_id')
                ->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
            'units'      => $masters->lookup('units'),
            'methods'    => $masters->lookup('methods'),
            'gaugeTypes' => $masters->lookup('gauge-types'),
            'parts'      => $db->table('parts')->select('id, part_number, part_name, is_active')->orderBy('part_number')->get()->getResultArray(),
            'machines'   => $db->table('machines')->select('id, machine_code, machine_name, is_active')->orderBy('machine_code')->get()->getResultArray(),
            'types'      => ObservationType::options(),
            'errors'     => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function preview(int $id): string
    {
        $service = service('templates');

        return $this->render('templates/preview', [
            'title'     => 'Preview',
            'template'  => $service->find($id),
            'structure' => $service->structure($id),
        ]);
    }

    public function update(int $id)
    {
        return $this->perform(fn () => service('templates')->updateHeader($id, (array) $this->request->getPost(self::HEADER), $this->currentUser()),
            site_url('templates/' . $id), 'Template header saved.');
    }

    public function delete(int $id)
    {
        return $this->perform(fn () => service('templates')->delete($id, $this->currentUser()), site_url('templates'), 'Draft template deleted.');
    }

    public function addSection(int $id)
    {
        return $this->perform(fn () => service('templates')->addSection($id, (string) $this->request->getPost('title'), (string) $this->request->getPost('description'), $this->currentUser()),
            site_url('templates/' . $id), 'Section added.');
    }

    public function updateSection(int $id, int $sectionId)
    {
        return $this->perform(fn () => service('templates')->updateSection($id, $sectionId, (string) $this->request->getPost('title'), (string) $this->request->getPost('description'), $this->currentUser()),
            site_url('templates/' . $id . '#s' . $sectionId), 'Section saved.');
    }

    public function deleteSection(int $id, int $sectionId)
    {
        return $this->perform(fn () => service('templates')->deleteSection($id, $sectionId, $this->currentUser()), site_url('templates/' . $id), 'Section deleted.');
    }

    public function moveSection(int $id, int $sectionId)
    {
        return $this->perform(fn () => service('templates')->moveSection($id, $sectionId, (string) $this->request->getPost('direction')),
            site_url('templates/' . $id . '#s' . $sectionId), 'Section moved.');
    }

    public function saveParameter(int $id)
    {
        return $this->perform(fn () => service('templates')->saveParameter($id, (array) $this->request->getPost(self::PARAM), $this->currentUser()),
            fn (int $paramId) => site_url('templates/' . $id . '#p' . $paramId), 'Parameter saved.');
    }

    public function deleteParameter(int $id, int $paramId)
    {
        return $this->perform(fn () => service('templates')->deleteParameter($id, $paramId, $this->currentUser()), site_url('templates/' . $id), 'Parameter deleted.');
    }

    public function moveParameter(int $id, int $paramId)
    {
        return $this->perform(fn () => service('templates')->moveParameter($id, $paramId, (string) $this->request->getPost('direction')),
            site_url('templates/' . $id . '#p' . $paramId), 'Parameter moved.');
    }

    public function saveMapping(int $id)
    {
        return $this->perform(fn () => service('templates')->saveMapping(
            $id,
            array_values((array) ($this->request->getPost('parts') ?? [])),
            array_values((array) ($this->request->getPost('machines') ?? [])),
            $this->currentUser(),
        ), site_url('templates/' . $id . '#mapping'), 'Mapping saved.');
    }

    public function publish(int $id)
    {
        return $this->perform(fn () => service('templates')->publish($id, $this->currentUser()), site_url('templates/' . $id),
            'Template published. New inspections for the mapped parts / machines now use this version.');
    }

    public function retire(int $id)
    {
        return $this->perform(fn () => service('templates')->retire($id, $this->currentUser()), site_url('templates/' . $id), 'Template retired.');
    }

    public function newVersion(int $id)
    {
        return $this->perform(fn () => service('templates')->newVersion($id, $this->currentUser()), fn (int $newId) => site_url('templates/' . $newId),
            'New draft version created. Change it, then publish to replace the current version.');
    }
}

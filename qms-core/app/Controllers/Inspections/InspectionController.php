<?php

namespace App\Controllers\Inspections;

use App\Controllers\BaseController;
use App\Exceptions\ValidationException;
use App\Libraries\Paging;
use App\Libraries\Uuid;
use Throwable;

/**
 * Starting, entering and viewing inspection reports. Business rules live in
 * InspectionService / WorkflowService.
 */
class InspectionController extends BaseController
{
    private const FILTERS = ['from', 'to', 'type', 'part', 'machine', 'shift', 'status', 'result', 'q', 'superseded'];

    /** Operator landing page: start tiles, returned reports, drafts, open day sheets. */
    public function home(): string
    {
        $user    = $this->currentUser();
        $options = service('inspections')->wizardOptions();

        return $this->render('inspections/home', [
            'title'       => 'Home',
            'lists'       => service('inspectionList')->home($user),
            'reportTypes' => $options['reportTypes'],
            'current'     => $options['current'],
        ]);
    }

    public function index(): string
    {
        $filters = array_map('strval', array_filter((array) $this->request->getGet(self::FILTERS), static fn ($v): bool => $v !== null));
        $paging  = Paging::fromRequest($this->request, 30);
        $masters = service('masterData');

        return $this->render('inspections/index', [
            'title'    => 'Inspections',
            'rows'     => service('inspectionList')->search($filters, $paging, $this->currentUser()),
            'filters'  => $filters,
            'paging'   => $paging,
            'types'    => $masters->lookup('report-types'),
            'parts'    => $masters->lookup('parts'),
            'machines' => $masters->lookup('machines'),
            'shifts'   => $masters->lookup('shifts'),
        ]);
    }

    public function new(): string
    {
        $options = service('inspections')->wizardOptions();

        return $this->render('inspections/new', [
            'title'        => 'New inspection',
            'options'      => $options,
            'selectedType' => (int) ($this->request->getGet('type') ?? old('report_type_id') ?? 0),
            'clientUuid'   => Uuid::v4(),
            'errors'       => session()->getFlashdata('errors') ?? [],
        ]);
    }

    /** JSON: machines on which the part can be inspected for the report type. */
    public function options()
    {
        try {
            $machines = service('inspections')->machineOptions((int) $this->request->getGet('type'), (int) $this->request->getGet('part'));
        } catch (Throwable $e) {
            return $this->jsonError($e);
        }

        return $this->response->setJSON(['ok' => true, 'machines' => $machines]);
    }

    public function start()
    {
        $input = (array) $this->request->getPost(['client_uuid', 'report_type_id', 'part_id', 'machine_id', 'inspection_date', 'shift_id']);

        try {
            $id = service('inspections')->start($input, $this->currentUser());
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }

        return redirect()->to(site_url("inspections/{$id}/edit"));
    }

    public function show(int $id): string
    {
        $user     = $this->currentUser();
        $data     = service('inspections')->load($id, $user);
        $workflow = service('workflow');

        return $this->render('inspections/show', $data + [
            'title'     => $this->title($data['report']),
            'actions'   => $workflow->available($data['report'], $user),
            'steps'     => $workflow->steps($data['report'], $data['approvals']),
            'syncState' => service('sheetQueue')->reportState($id),
            'idemKey'   => Uuid::v4(),
            'errors'    => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function edit(int $id)
    {
        $user    = $this->currentUser();
        $service = service('inspections');
        $data    = $service->load($id, $user);
        if (! $data['canEdit']) {
            return redirect()->to(site_url("inspections/{$id}"));
        }
        $workflow = service('workflow');

        return $this->render('inspections/edit', $data + [
            'title'        => $this->title($data['report']),
            'actions'      => $workflow->available($data['report'], $user),
            'steps'        => $workflow->steps($data['report'], $data['approvals']),
            'problems'     => $service->submissionProblems($data['report']),
            'autosave'     => max(5, (int) qms_setting('inspection.autosave_seconds', 20)),
            'blockExpired' => (bool) qms_setting('gauge.block_expired_on_submit', true),
            'idemKey'      => Uuid::v4(),
            'user'         => $user,
            'errors'       => session()->getFlashdata('errors') ?? [],
        ]);
    }

    /** JSON autosave of changed cells, gauges, remarks, round details and header. */
    public function saveDraft(int $id)
    {
        try {
            try {
                $payload = $this->request->getJSON(true);
            } catch (Throwable) {
                $payload = null;
            }
            if (! is_array($payload)) {
                throw ValidationException::single('payload', 'The request could not be read. Reload the page.');
            }
            $result = service('inspections')->saveDraft($id, $payload, $this->currentUser());
        } catch (Throwable $e) {
            return $this->jsonError($e);
        }

        return $this->response->setJSON($result);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function title(array $report): string
    {
        if ($report['report_no'] !== null) {
            return $report['report_no'] . ((int) $report['revision_no'] > 0 ? ' Rev ' . $report['revision_no'] : '');
        }

        return $report['type_name'] . ' (draft)';
    }
}

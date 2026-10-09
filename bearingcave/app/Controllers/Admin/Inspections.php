<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CompanyModel;
use App\Models\InspectionRequestModel;
use App\Services\InspectionService;

class Inspections extends AdminController
{
    public function index(): string
    {
        $m = model(InspectionRequestModel::class)->select('inspection_requests.*, companies.legal_name')->join('companies', 'companies.id = inspection_requests.company_id');
        if ($s = $this->request->getGet('status')) {
            $m->where('inspection_requests.status', $s);
        }
        $m->orderBy('inspection_requests.id', 'DESC');

        return $this->page('inspections', ['title' => 'Inspection requests', 'rows' => $m->paginate(25), 'pager' => $m->pager, 'statuses' => InspectionService::STATUSES]);
    }

    private function req(int $id): array
    {
        return model(InspectionRequestModel::class)->find($id) ?? $this->notFound();
    }

    public function show(int $id): string
    {
        $r = $this->req($id);

        return $this->page('inspection', ['title' => 'Inspection ' . $r['request_number'], 'r' => $r, 'company' => model(CompanyModel::class)->find($r['company_id']),
            'report' => service('inspections')->report($id), 'documents' => service('documents')->forEntity('inspection_request', $id),
            'statuses' => InspectionService::STATUSES, 'scopes' => InspectionService::SCOPES, 'inspectors' => $this->staffOptions(['inspection_manager'])]);
    }

    public function quote(int $id)
    {
        $r = $this->req($id);
        if ($v = $this->invalid(['quoted_fee' => 'required|decimal|greater_than[0]', 'quote_valid_until' => 'permit_empty|valid_date[Y-m-d]'])) {
            return $v;
        }

        return $this->attempt(fn () => service('inspections')->quote($r, (float) $this->request->getPost('quoted_fee'), $this->request->getPost('quote_valid_until') ?: null, $this->request->getPost('fee_basis') ?: null, $this->userId()), 'Quotation sent to the requester.');
    }

    public function assign(int $id)
    {
        $r = $this->req($id);

        return $this->attempt(fn () => service('inspections')->assign($r, (int) $this->request->getPost('inspector_user_id') ?: null, $this->request->getPost('third_party_agency') ?: null, $this->request->getPost('scheduled_on') ?: null), 'Inspector assigned.');
    }

    public function start(int $id)
    {
        $r = $this->req($id);

        return $this->attempt(fn () => service('inspections')->start($r), 'Inspection marked in progress.');
    }

    public function report(int $id)
    {
        $r = $this->req($id);

        return $this->attempt(fn () => service('inspections')->uploadReport($r, $this->request->getPost(), $this->request->getFile('report'), $this->userId()), 'Report uploaded and shared with the parties.');
    }

    public function complete(int $id)
    {
        $r = $this->req($id);

        return $this->attempt(fn () => service('inspections')->complete($r), 'Inspection completed.');
    }

    public function cancel(int $id)
    {
        $r = $this->req($id);

        return $this->attempt(fn () => service('inspections')->cancel($r, (string) ($this->request->getPost('reason') ?: 'Cancelled by BearingCave')), 'Request cancelled.');
    }
}

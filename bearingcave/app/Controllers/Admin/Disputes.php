<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CompanyModel;
use App\Models\DisputeModel;
use App\Models\OrderModel;
use App\Services\DisputeService;

class Disputes extends AdminController
{
    public function index(): string
    {
        $m = model(DisputeModel::class)->select('disputes.*, a.legal_name AS raised_by_name, b.legal_name AS against_name')
            ->join('companies a', 'a.id = disputes.raised_by_company_id')->join('companies b', 'b.id = disputes.against_company_id');
        $st = $this->request->getGet('status') ?? 'open';
        if ($st === 'open') {
            $m->whereIn('disputes.status', ['open', 'awaiting_response', 'under_review']);
        } elseif ($st !== '') {
            $m->where('disputes.status', $st);
        }
        $m->orderBy('disputes.id', 'DESC');

        return $this->page('disputes', ['title' => 'Disputes', 'rows' => $m->paginate(25), 'pager' => $m->pager, 'statuses' => DisputeService::STATUSES, 'categories' => DisputeService::CATEGORIES, 'status' => $st]);
    }

    public function show(int $id): string
    {
        $d = model(DisputeModel::class)->find($id) ?? $this->notFound();

        return $this->page('dispute', ['title' => 'Dispute ' . $d['dispute_number'], 'd' => $d, 'order' => model(OrderModel::class)->find($d['order_id']),
            'raisedBy' => model(CompanyModel::class)->find($d['raised_by_company_id']), 'against' => model(CompanyModel::class)->find($d['against_company_id']),
            'messages' => service('disputes')->messages($id, true), 'documents' => service('documents')->forEntity('dispute', $id),
            'statuses' => DisputeService::STATUSES, 'categories' => DisputeService::CATEGORIES, 'agents' => $this->staffOptions(['support_executive'])]);
    }

    public function assign(int $id)
    {
        $d = model(DisputeModel::class)->find($id) ?? $this->notFound();
        service('disputes')->assign($d, (int) $this->request->getPost('assigned_to') ?: null);

        return redirect()->back()->with('success', 'Dispute assigned.');
    }

    public function reply(int $id)
    {
        $d = model(DisputeModel::class)->find($id) ?? $this->notFound();

        return $this->attempt(fn () => service('disputes')->message($d, $this->userId(), 'admin', (string) $this->request->getPost('message'), $this->request->getPost('internal') === '1'), 'Message added.');
    }

    public function resolve(int $id)
    {
        $d = model(DisputeModel::class)->find($id) ?? $this->notFound();

        return $this->attempt(fn () => service('disputes')->resolve($d, (string) $this->request->getPost('status'), (string) $this->request->getPost('resolution_notes'), $this->userId()), 'Dispute updated and parties notified.');
    }
}

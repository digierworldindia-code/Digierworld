<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Models\DisputeModel;
use App\Services\DisputeService;

class Disputes extends AreaController
{
    public function index(): string
    {
        $m = model(DisputeModel::class)->groupStart()->where('raised_by_company_id', $this->cid())->orWhere('against_company_id', $this->cid())->groupEnd()->orderBy('id', 'DESC');

        return $this->page('account/disputes', ['title' => 'Disputes', 'disputes' => $m->paginate(20), 'pager' => $m->pager, 'statuses' => DisputeService::STATUSES, 'categories' => DisputeService::CATEGORIES]);
    }

    public function new(int $orderId)
    {
        $order = service('orders')->forParty($orderId, $this->cid(), $this->area());
        if (! $order) {
            $this->notFound();
        }

        return $this->page('account/dispute_new', ['title' => 'Raise a dispute', 'order' => $order, 'categories' => DisputeService::CATEGORIES, 'warranty' => policy('legal.warranty_terms')]);
    }

    public function create(int $orderId)
    {
        if ($r = $this->invalid(['category' => 'required', 'subject' => 'required|max_length[191]', 'description' => 'required|min_length[20]', 'desired_resolution' => 'permit_empty|max_length[2000]'])) {
            return $r;
        }
        $d = null;
        $res = $this->attempt(function () use ($orderId, &$d) {
            $d = service('disputes')->create($this->company(), $this->userId(), $orderId, $this->request->getPost(['category', 'subject', 'description', 'desired_resolution']));
            $file = $this->request->getFile('evidence');
            if ($file && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                service('documents')->store($file, ['company_id' => $this->cid(), 'entity_type' => 'dispute', 'entity_id' => (int) $d['id'], 'doc_type' => 'dispute_evidence', 'title' => 'Evidence: ' . $file->getClientName()], ['pdf', 'jpg', 'jpeg', 'png']);
            }
        }, 'Dispute raised. The other party and BearingCave support have been notified.');

        return $d ? redirect()->to(site_url($this->area() . '/disputes/' . $d['id']))->with('success', 'Dispute ' . $d['dispute_number'] . ' raised. The other party and BearingCave support have been notified.') : $res;
    }

    public function show(int $id): string
    {
        $d = service('disputes')->forParty($id, $this->cid());
        if (! $d) {
            $this->notFound();
        }
        $order = model(\App\Models\OrderModel::class)->find($d['order_id']);

        return $this->page('account/dispute', [
            'title' => 'Dispute ' . $d['dispute_number'], 'd' => $d, 'order' => $order,
            'messages' => service('disputes')->messages($id, false), 'documents' => service('documents')->forEntity('dispute', $id),
            'statuses' => DisputeService::STATUSES, 'categories' => DisputeService::CATEGORIES,
            'mySide' => $this->area(),
        ]);
    }

    public function reply(int $id)
    {
        $d = service('disputes')->forParty($id, $this->cid());
        if (! $d) {
            $this->notFound();
        }

        return $this->attempt(fn () => service('disputes')->message($d, $this->userId(), $this->area(), (string) $this->request->getPost('message')), 'Message added.');
    }

    public function upload(int $id)
    {
        $d = service('disputes')->forParty($id, $this->cid());
        if (! $d) {
            $this->notFound();
        }

        return $this->attempt(fn () => service('documents')->store($this->request->getFile('file'), ['company_id' => $this->cid(), 'entity_type' => 'dispute', 'entity_id' => $id, 'doc_type' => 'dispute_evidence', 'title' => (string) ($this->request->getPost('title') ?: 'Supporting document')], ['pdf', 'jpg', 'jpeg', 'png']), 'Document added to the dispute.');
    }
}

<?php

namespace App\Controllers\Admin;

use App\Services\NetworkService;
use CodeIgniter\HTTP\RedirectResponse;

/** Dealership applications sent from the public website. */
class Applications extends AdminController
{
    public function index(): string
    {
        $status  = $this->oneOf('status', ['NEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED']);
        $builder = db_connect()->table('dealer_applications a')
            ->select('a.id, a.business_name, a.owner_name, a.mobile, a.email, a.city, a.state, a.status, a.spam_score, a.created_at, d.code dealer_code')
            ->join('dealers d', 'd.id = a.created_dealer_id', 'left')
            ->orderBy('a.created_at', 'DESC');
        if ($status) {
            $builder->where('a.status', $status);
        }

        return $this->render('admin/applications/index', 'Dealer applications', 'admin/applications', [
            'list' => $this->paginate($builder), 'f' => compact('status'),
        ]);
    }

    public function show(string $id): string
    {
        $db = db_connect();
        $a  = $db->table('dealer_applications a')->select('a.*, u.full_name reviewed_by_name, d.code dealer_code, d.id dealer_id')
            ->join('users u', 'u.id = a.reviewed_by', 'left')->join('dealers d', 'd.id = a.created_dealer_id', 'left')
            ->where('a.id', $id)->get()->getRowArray() ?? $this->notFound();

        return $this->render('admin/applications/show', $a['business_name'], 'admin/applications', ['a' => $a]);
    }

    public function review(string $id): RedirectResponse
    {
        $decision  = (string) $this->request->getPost('decision');
        $overrides = [];
        foreach (['city', 'state', 'pincode'] as $field) {
            $value = $this->post($field, 80);
            if ($value !== '') {
                $overrides[$field] = $value;
            }
        }
        $overrides['public_listed'] = $this->request->getPost('public_listed') === '1';
        $overrides['is_showroom']   = $this->request->getPost('is_showroom') === '1';

        return $this->act(
            fn () => NetworkService::instance()->reviewApplication($id, $decision, $this->post('notes'), $overrides),
            static fn ($r) => $r['dealer_code'] !== null
                ? "Approved. Dealer {$r['dealer_code']} created — create their login under Users."
                : 'Application ' . strtolower(humanise($r['status'])) . '.',
            site_url('admin/applications/' . $id),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

class Dashboard extends AdminController
{
    public function index(): string
    {
        $db = db_connect();

        return $this->page('dashboard', [
            'title' => 'Admin dashboard', 'kpi' => service('reports')->kpis(),
            'queue' => $db->table('verification_applications a')->select('a.*, c.legal_name, c.company_type, c.is_sample')->join('companies c', 'c.id = a.company_id')
                ->whereIn('a.status', ['submitted', 'in_review'])->orderBy('a.submitted_at')->limit(6)->get()->getResultArray(),
            'rfqs' => $db->table('rfqs')->whereIn('status', ['submitted', 'under_review'])->orderBy('id')->limit(6)->get()->getResultArray(),
            'products' => $db->table('products')->where('status', 'pending_review')->where('deleted_at', null)->orderBy('updated_at')->limit(6)->get()->getResultArray(),
            'security' => $db->table('audit_logs')->where('severity', 'security')->orderBy('id', 'DESC')->limit(6)->get()->getResultArray(),
            'pendingDecisions' => $db->table('platform_settings')->where('approval_status', 'pending_approval')->countAllResults(),
        ]);
    }
}

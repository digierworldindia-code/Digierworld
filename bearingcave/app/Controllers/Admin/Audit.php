<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

class Audit extends AdminController
{
    public function index(): string
    {
        $b = db_connect()->table('audit_logs l')->select('l.*, ai.secret AS email')->join('auth_identities ai', "ai.user_id = l.user_id AND ai.type = 'email_password'", 'left');
        if ($s = $this->request->getGet('severity')) {
            $b->where('l.severity', $s);
        }
        if ($e = trim((string) $this->request->getGet('event'))) {
            $b->like('l.event', $e, 'after');
        }
        if ($c = (int) $this->request->getGet('company_id')) {
            $b->where('l.company_id', $c);
        }
        $page  = max(1, (int) $this->request->getGet('page'));
        $total = (clone $b)->countAllResults(false);

        return $this->page('audit', ['title' => 'Audit logs', 'rows' => $b->orderBy('l.id', 'DESC')->limit(50, ($page - 1) * 50)->get()->getResultArray(), 'page' => $page, 'pages' => (int) ceil(max(1, $total) / 50)]);
    }
}

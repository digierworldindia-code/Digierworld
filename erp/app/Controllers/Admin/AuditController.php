<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/** Read-only audit trail with filters, detail diff and hash-chain verification. */
class AuditController extends BaseController
{
    public function index()
    {
        [$page, $perPage, $offset] = $this->paging(50);
        $f = [
            'user'   => (int) $this->request->getGet('user'),
            'action' => trim((string) $this->request->getGet('action')),
            'entity' => trim((string) $this->request->getGet('entity')),
            'id'     => (int) $this->request->getGet('id'),
            'from'   => ymd((string) $this->request->getGet('from')),
            'to'     => ymd((string) $this->request->getGet('to')),
        ];
        $b = $this->db->table('audit_logs a')->select('a.id, a.occurred_at, a.user_id, a.actor_type, a.action, a.entity_type, a.entity_id, a.summary, a.ip_address, p.full_name')
            ->join('user_profiles p', 'p.user_id = a.user_id', 'left');
        if ($f['user'] > 0) {
            $b->where('a.user_id', $f['user']);
        }
        if ($f['action'] !== '') {
            $b->like('a.action', $f['action'], 'after');
        }
        if ($f['entity'] !== '') {
            $b->where('a.entity_type', $f['entity']);
        }
        if ($f['id'] > 0) {
            $b->where('a.entity_id', $f['id']);
        }
        if ($f['from']) {
            $b->where('a.occurred_at >=', $f['from'] . ' 00:00:00');
        }
        if ($f['to']) {
            $b->where('a.occurred_at <=', $f['to'] . ' 23:59:59');
        }
        $total = (clone $b)->countAllResults();
        $rows  = $b->orderBy('a.id', 'DESC')->limit($perPage, $offset)->get()->getResultArray();

        return $this->render('admin/audit/index', [
            'pageTitle' => 'Activity / audit log', 'breadcrumbs' => [['Audit log']],
            'rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'f' => $f,
            'users' => $this->db->table('user_profiles')->select('user_id, full_name')->orderBy('full_name')->get()->getResultArray(),
            'entities' => array_column($this->db->query('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type')->getResultArray(), 'entity_type'),
        ]);
    }

    public function show($id)
    {
        $row = $this->db->table('audit_logs a')->select('a.*, p.full_name')->join('user_profiles p', 'p.user_id = a.user_id', 'left')
            ->where('a.id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $before = $row['before_data'] ? json_decode($row['before_data'], true) : null;
        $after  = $row['after_data'] ? json_decode($row['after_data'], true) : null;
        $keys   = array_unique(array_merge(array_keys((array) $before), array_keys((array) $after)));

        return $this->render('admin/audit/show', [
            'pageTitle' => 'Audit entry #' . $row['id'], 'breadcrumbs' => [['Audit log', 'admin/audit'], ['#' . $row['id']]],
            'row' => $row, 'before' => $before, 'after' => $after, 'keys' => $keys,
            'meta' => $row['meta'] ? json_decode($row['meta'], true) : null,
        ]);
    }

    public function verify()
    {
        [$ok, $broken, $checked] = service('audit')->verify();
        service('audit')->log('audit.verified', null, null, null, null, $ok ? "Chain verified ({$checked} rows)" : "Chain BROKEN at #{$broken}");

        return redirect()->to(site_url('admin/audit'))->with(
            $ok ? 'success' : 'error',
            $ok ? "Audit chain intact: {$checked} entries verified (SHA-256 hash chain)." : "Audit chain verification FAILED at entry #{$broken}. Records may have been altered outside the application — investigate immediately."
        );
    }
}

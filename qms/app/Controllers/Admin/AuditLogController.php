<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Paging;

/**
 * Read-only audit trail viewer. Nothing in the application can modify the log.
 */
class AuditLogController extends BaseController
{
    public function index(): string
    {
        $f      = array_map('strval', (array) $this->request->getGet(['from', 'to', 'user', 'module', 'action', 'q', 'record']));
        $paging = Paging::fromRequest($this->request, 50);
        $clock  = service('clock');
        $db     = db_connect();

        $builder = $db->table('audit_logs a')
            ->select('a.*, e.employee_code, e.full_name')
            ->join('employees e', 'e.id = a.employee_id', 'left');

        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$key] ?? '')) {
                $day = $key === 'to' ? date('Y-m-d', strtotime($f[$key] . ' +1 day')) : $f[$key];
                $builder->where("a.created_at {$op}", $clock->plantToUtc($day . ' 00:00:00'));
            }
        }
        if (($f['user'] ?? '') !== '') {
            $builder->like('a.username', $f['user'], 'after');
        }
        foreach (['module', 'action'] as $key) {
            if (($f[$key] ?? '') !== '') {
                $builder->where("a.{$key}", strtoupper($key) === 'ACTION' ? strtoupper($f[$key]) : $f[$key]);
            }
        }
        if (($f['q'] ?? '') !== '') {
            $builder->like('a.record_ref', $f['q']);
        }
        if (ctype_digit($f['record'] ?? '')) {
            $builder->where('a.record_id', (int) $f['record']);
        }

        $paging->total = $builder->countAllResults(false);
        $rows          = $builder->orderBy('a.id', 'DESC')->get($paging->perPage, $paging->offset())->getResultArray();

        return $this->render('admin/audit/index', [
            'title'   => 'Audit trail',
            'rows'    => $rows,
            'filters' => $f,
            'paging'  => $paging,
            'modules' => array_column($db->table('audit_logs')->select('module')->distinct()->orderBy('module')->get()->getResultArray(), 'module'),
            'actions' => array_column($db->table('audit_logs')->select('action')->distinct()->orderBy('action')->get()->getResultArray(), 'action'),
        ]);
    }
}

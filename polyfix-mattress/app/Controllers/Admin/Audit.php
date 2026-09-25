<?php

namespace App\Controllers\Admin;

use App\Libraries\Audit as AuditLibrary;
use App\Libraries\AuditChain;

/**
 * The audit trail: append-only, hash-chained, and readable by anyone with
 * audit:read. Nobody can edit or delete a row — the application's database
 * user holds SELECT and INSERT on this table and nothing else.
 */
class Audit extends AdminController
{
    public function index(): string
    {
        $db     = db_connect();
        $action = $this->filter('action', 60);
        $entity = $this->filter('entity', 60);
        $id     = $this->filter('id', 64);
        $user   = $this->filter('user', 36);
        $from   = $this->filter('from', 10);
        $to     = $this->filter('to', 10);

        $builder = $db->table('audit_logs')->select('id, occurred_at, user_email, role_key, action, entity, entity_id, ip, previous_value, new_value, reason, request_id')
            ->orderBy('id', 'DESC');
        if ($action) {
            $builder->where('action', $action);
        }
        if ($entity) {
            $builder->where('entity', $entity);
        }
        if ($id) {
            $builder->where('entity_id', $id);
        }
        if ($user && is_uuid($user)) {
            $builder->where('user_id', $user);
        }
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $builder->where('occurred_at >=', $from . ' 00:00:00');
        }
        if ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $builder->where('occurred_at <=', $to . ' 23:59:59.999999');
        }

        return $this->render('admin/audit/index', 'Audit trail', 'admin/audit', [
            'list'     => $this->paginate($builder, 50),
            'actions'  => array_column($db->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->getResultArray(), 'action'),
            'entities' => array_column($db->query('SELECT DISTINCT entity FROM audit_logs ORDER BY entity')->getResultArray(), 'entity'),
            'f'        => compact('action', 'entity', 'id', 'user', 'from', 'to'),
        ]);
    }

    /**
     * Re-walks the hash chain. Problems mean a row was altered outside the
     * application — for example by restoring a doctored dump.
     */
    public function verify(): string
    {
        $result = (new AuditChain(db_connect()))->verify();
        AuditLibrary::instance()->record('AUDIT_CHAIN_VERIFIED', 'audit_logs', null, null, [
            'entriesChecked' => $result['rows'], 'problemsFound' => count($result['problems']),
        ]);
        if ($result['problems'] !== []) {
            log_message('error', 'security.AUDIT_CHAIN_BROKEN problems={n}', ['n' => count($result['problems'])]);
        }

        return $this->render('admin/audit/verify', 'Audit chain', 'admin/audit', ['result' => $result]);
    }
}

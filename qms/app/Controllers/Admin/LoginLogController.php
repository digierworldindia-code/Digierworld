<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Paging;

class LoginLogController extends BaseController
{
    private const EVENTS = ['LOGIN_SUCCESS', 'LOGIN_FAILED', 'LOGOUT', 'ACCOUNT_LOCKED', 'ACCOUNT_UNLOCKED',
        'PASSWORD_CHANGED', 'PASSWORD_RESET', 'SESSION_TIMEOUT', 'SESSION_REVOKED'];

    public function index(): string
    {
        $f       = array_map('strval', (array) $this->request->getGet(['from', 'to', 'user', 'event', 'ip']));
        $paging  = Paging::fromRequest($this->request, 50);
        $clock   = service('clock');
        $builder = db_connect()->table('login_logs');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'] ?? '')) {
            $builder->where('created_at >=', $clock->plantToUtc($f['from'] . ' 00:00:00'));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'] ?? '')) {
            $builder->where('created_at <', $clock->plantToUtc(date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'));
        }
        if (($f['user'] ?? '') !== '') {
            $builder->like('username_attempted', $f['user'], 'after');
        }
        if (in_array($f['event'] ?? '', self::EVENTS, true)) {
            $builder->where('event', $f['event']);
        }
        if (($f['ip'] ?? '') !== '') {
            $builder->like('ip_address', $f['ip'], 'after');
        }

        $paging->total = $builder->countAllResults(false);

        return $this->render('admin/login_logs/index', [
            'title'   => 'Login history',
            'rows'    => $builder->orderBy('id', 'DESC')->get($paging->perPage, $paging->offset())->getResultArray(),
            'filters' => $f,
            'events'  => self::EVENTS,
            'paging'  => $paging,
        ]);
    }
}

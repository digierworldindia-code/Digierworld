<?php

namespace App\Controllers\Hr;

use App\Controllers\BaseController;

/** Shared HR helpers: employee loading and master-data pickers. */
abstract class HrBase extends BaseController
{
    /** @return array<string, mixed> employee row or 404 */
    protected function employee(int $id): array
    {
        $emp = service('employees')->find($id);
        if ($emp === null) {
            $this->notFound();
        }

        return $emp;
    }

    /** @return array<int, string> */
    protected function departments(): array
    {
        return array_column($this->db->table('departments')->where('is_active', 1)->orderBy('name')->get()->getResultArray(), 'name', 'id');
    }

    /** @return list<array<string, mixed>> */
    protected function designations(): array
    {
        return $this->db->table('designations')->where('is_active', 1)->orderBy('name')->get()->getResultArray();
    }

    /** @return array<int, string> user id => name, for login linking and interviewer pickers */
    protected function users(): array
    {
        return array_column($this->db->table('user_profiles p')->select('p.user_id, p.full_name')->join('users u', 'u.id = p.user_id')
            ->where('u.deleted_at', null)->where('u.active', 1)->where('p.is_service_account', 0)->orderBy('p.full_name')->get()->getResultArray(), 'full_name', 'user_id');
    }

    /** "YYYY-MM" from the query string, defaulting to the current month. */
    protected function monthParam(string $key = 'month'): string
    {
        $m = (string) $this->request->getGet($key);

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
    }
}

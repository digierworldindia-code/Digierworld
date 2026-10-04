<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Paging;
use Throwable;

class UserController extends BaseController
{
    public function index(): string
    {
        $filters = array_map('strval', (array) $this->request->getGet(['q', 'role_id', 'status']));
        $paging  = Paging::fromRequest($this->request, 30);
        $service = service('userAdmin');

        return $this->render('admin/users/index', [
            'title'   => 'Users',
            'users'   => $service->list($filters, $paging),
            'roles'   => $service->roles(),
            'filters' => $filters,
            'paging'  => $paging,
        ]);
    }

    public function new(): string
    {
        $service = service('userAdmin');

        return $this->render('admin/users/form', [
            'title'     => 'New user',
            'user'      => null,
            'roles'     => $service->roles(),
            'employees' => $service->linkableEmployees(),
            'errors'    => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function create()
    {
        try {
            $result = service('userAdmin')->create($this->request->getPost(['username', 'email', 'role_id', 'employee_id']), $this->currentUser());
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }

        return redirect()->to(site_url("admin/users/{$result['id']}/edit"))
            ->with('success', 'User created. One-time password (give it to the user, shown only now): ' . $result['password']);
    }

    public function edit(int $id): string
    {
        $service = service('userAdmin');
        $user    = $service->find($id);

        return $this->render('admin/users/form', [
            'title'     => 'Edit user ' . $user['username'],
            'user'      => $user,
            'roles'     => $service->roles(),
            'employees' => $service->linkableEmployees($user['employee_id'] === null ? null : (int) $user['employee_id']),
            'errors'    => session()->getFlashdata('errors') ?? [],
            'locked'    => $user['locked_until'] !== null && $user['locked_until'] > service('clock')->nowUtcString(),
        ]);
    }

    public function update(int $id)
    {
        return $this->perform(
            fn () => service('userAdmin')->update($id, $this->request->getPost(['email', 'role_id', 'employee_id']), $this->currentUser()),
            site_url("admin/users/{$id}/edit"),
            'User saved.',
        );
    }

    public function resetPassword(int $id)
    {
        try {
            $password = service('userAdmin')->resetPassword($id, $this->currentUser());
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }

        return redirect()->to(site_url("admin/users/{$id}/edit"))
            ->with('success', 'Password reset. One-time password (shown only now): ' . $password . ' — the user must change it at next login.');
    }

    public function unlock(int $id)
    {
        return $this->perform(fn () => service('userAdmin')->unlock($id, $this->currentUser()), site_url("admin/users/{$id}/edit"), 'Account unlocked.');
    }

    public function toggleStatus(int $id)
    {
        try {
            $status = service('userAdmin')->toggleStatus($id, $this->currentUser());
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }

        return redirect()->to(site_url("admin/users/{$id}/edit"))
            ->with('success', $status === 'ACTIVE' ? 'Account enabled.' : 'Account disabled. Open sessions were ended.');
    }
}

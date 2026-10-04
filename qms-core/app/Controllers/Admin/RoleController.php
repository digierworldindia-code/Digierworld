<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use Throwable;

class RoleController extends BaseController
{
    public function index(): string
    {
        return $this->render('admin/roles/index', [
            'title'  => 'Roles & permissions',
            'roles'  => service('roleAdmin')->list(),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function create()
    {
        try {
            $id = service('roleAdmin')->create(
                (string) $this->request->getPost('code'),
                (string) $this->request->getPost('name'),
                (string) $this->request->getPost('description'),
                $this->currentUser(),
            );
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }

        return redirect()->to(site_url("admin/roles/{$id}"))->with('success', 'Role created. Now choose its permissions.');
    }

    public function edit(int $id): string
    {
        $service = service('roleAdmin');
        $role    = $service->find($id);

        return $this->render('admin/roles/edit', [
            'title'    => 'Role ' . $role['name'],
            'role'     => $role,
            'matrix'   => $service->matrix($id),
            'readOnly' => (int) $role['is_super_admin'] === 1 || (int) $this->currentUser()['role_id'] === $id,
        ]);
    }

    public function update(int $id)
    {
        $codes = array_values(array_filter((array) $this->request->getPost('permissions'), 'is_string'));

        return $this->perform(
            fn () => service('roleAdmin')->update(
                $id,
                (string) $this->request->getPost('name'),
                (string) $this->request->getPost('description'),
                $codes,
                $this->currentUser(),
            ),
            site_url("admin/roles/{$id}"),
            'Role saved. The new permissions apply on the users\' next click.',
        );
    }

    public function delete(int $id)
    {
        return $this->perform(fn () => service('roleAdmin')->delete($id, $this->currentUser()), site_url('admin/roles'), 'Role deleted.');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Libraries\CompanyPermissions;
use App\Models\CompanyMemberModel;

class Team extends AreaController
{
    public function index(): string
    {
        return $this->page('account/team', [
            'title' => 'Team members', 'members' => service('companies')->members($this->cid()),
            'permissions' => CompanyPermissions::ALL, 'canManage' => service('companyContext')->can('company.members'),
        ]);
    }

    public function store()
    {
        if ($r = $this->invalid(['email' => 'required|valid_email|is_unique[auth_identities.secret]', 'password' => 'required|strong_password[]', 'member_role' => 'required|in_list[admin,employee]', 'job_title' => 'permit_empty|max_length[100]'],
            ['email' => ['is_unique' => 'This email already has a BearingCave account.']])) {
            return $r;
        }

        return $this->attempt(fn () => service('companies')->addMember(
            $this->company(),
            (string) $this->request->getPost('email'),
            (string) $this->request->getPost('password'),
            (string) $this->request->getPost('member_role'),
            (array) $this->request->getPost('permissions'),
            $this->request->getPost('job_title') ?: null,
        ), 'Team member added. Share the temporary password securely and ask them to change it after first sign-in.');
    }

    public function update(int $memberId)
    {
        $member = $this->owned(model(CompanyMemberModel::class), $memberId);
        if ($member['member_role'] === 'owner') {
            return redirect()->back()->with('error', 'The company owner cannot be changed here.');
        }
        if ((int) $member['user_id'] === $this->userId()) {
            return redirect()->back()->with('error', 'You cannot change your own access.');
        }
        $role   = $this->request->getPost('member_role');
        $status = $this->request->getPost('status');
        model(CompanyMemberModel::class)->update($memberId, [
            'member_role' => in_array($role, ['admin', 'employee'], true) ? $role : $member['member_role'],
            'status'      => in_array($status, ['active', 'disabled'], true) ? $status : $member['status'],
        ]);
        service('companies')->setPermissions($memberId, (array) $this->request->getPost('permissions'));
        service('audit')->log('company.member_updated', ['entity_type' => 'company_member', 'entity_id' => $memberId, 'company_id' => $this->cid()]);

        return redirect()->back()->with('success', 'Team member updated.');
    }
}

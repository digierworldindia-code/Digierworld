<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\BuyerProfileModel;
use App\Models\CompanyMemberModel;
use App\Models\CompanyMemberPermissionModel;
use App\Models\CompanyModel;
use App\Models\SupplierProfileModel;
use CodeIgniter\Shield\Entities\User;

/**
 * Company accounts: registration (user + company + membership in one
 * transaction), team members and anonymous public aliases.
 */
class CompanyService
{
    public function newAlias(string $prefix): string
    {
        $m = model(CompanyModel::class);
        do {
            $alias = 'BC-' . $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while ($m->withDeleted()->where('public_alias', $alias)->countAllResults() > 0);

        return $alias;
    }

    public function uniqueSlug(string $name): string
    {
        $m    = model(CompanyModel::class);
        $base = make_slug($name, 170);
        $slug = $base;
        $i    = 2;
        while ($m->withDeleted()->where('slug', $slug)->countAllResults() > 0) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * Registers a business account.
     *
     * @return array{user:User,company:array}
     */
    public function register(string $type, array $data): array
    {
        if (! in_array($type, ['buyer', 'supplier'], true)) {
            throw new BusinessRuleException('Unknown account type.');
        }
        $blocked = (array) policy('platform.blocked_countries', []);
        if (in_array($data['country_code'], $blocked, true)) {
            throw new BusinessRuleException('BearingCave cannot currently onboard businesses from the selected country.');
        }

        $users = auth()->getProvider();
        $db    = db_connect();
        $db->transBegin();

        try {
            $user = new User([
                'username' => null,
                'email'    => strtolower(trim($data['email'])),
                'password' => $data['password'],
            ]);
            $users->save($user);
            $user = $users->findById($users->getInsertID());
            $user->addGroup($type);

            $companyId = (int) model(CompanyModel::class)->insert([
                'uuid'                => uuid4(),
                'company_type'        => $type,
                'legal_name'          => trim($data['legal_name']),
                'public_alias'        => $this->newAlias($type === 'supplier' ? 'S' : 'B'),
                'slug'                => $this->uniqueSlug($data['legal_name']),
                'country_code'        => $data['country_code'],
                'city'                => $data['city'] ?? null,
                'phone'               => $data['phone'] ?? null,
                'email'               => strtolower(trim($data['email'])),
                'verification_status' => 'unverified',
                'status'              => 'active',
            ]);
            model(CompanyMemberModel::class)->insert([
                'company_id' => $companyId, 'user_id' => $user->id, 'member_role' => 'owner',
                'job_title' => $data['job_title'] ?? null, 'status' => 'active',
            ]);
            if ($type === 'supplier') {
                model(SupplierProfileModel::class)->insert(['company_id' => $companyId, 'supplier_type' => $data['supplier_type'] ?? 'manufacturer']);
            } else {
                model(BuyerProfileModel::class)->insert(['company_id' => $companyId, 'buyer_category' => $data['buyer_category'] ?? null]);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $company = model(CompanyModel::class)->find($companyId);
        service('notifications')->notifyUser((int) $user->id, 'account.created', 'Welcome to BearingCave', 'Your ' . $type . ' account for ' . $company['legal_name'] . ' is ready. Complete your company profile and verification to unlock more features.', $type . '/dashboard');
        service('notifications')->notifyStaff(['verification_officer'], 'account.created', 'New ' . $type . ' registered', $company['legal_name'], 'admin/companies/' . $companyId);
        service('audit')->log('account.registered', ['entity_type' => 'company', 'entity_id' => $companyId, 'company_id' => $companyId, 'user_id' => (int) $user->id, 'description' => $type]);

        return ['user' => $user, 'company' => $company];
    }

    /**
     * Adds an employee to a company (creates the login if needed).
     *
     * @param list<string> $permissions
     */
    public function addMember(array $company, string $email, string $password, string $role, array $permissions, ?string $jobTitle): array
    {
        if (! in_array($role, ['admin', 'employee'], true)) {
            throw new BusinessRuleException('Invalid team role.');
        }
        $users = auth()->getProvider();
        if ($users->findByCredentials(['email' => strtolower($email)])) {
            throw new BusinessRuleException('A BearingCave account with this email already exists. Ask them to use a different work email.');
        }
        $db = db_connect();
        $db->transBegin();

        try {
            $user = new User(['username' => null, 'email' => strtolower(trim($email)), 'password' => $password]);
            $users->save($user);
            $user = $users->findById($users->getInsertID());
            $user->addGroup($company['company_type']);
            $memberId = (int) model(CompanyMemberModel::class)->insert(['company_id' => $company['id'], 'user_id' => $user->id, 'member_role' => $role, 'job_title' => $jobTitle, 'status' => 'active']);
            $this->setPermissions($memberId, $permissions);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
        service('audit')->log('company.member_added', ['entity_type' => 'company', 'entity_id' => (int) $company['id'], 'company_id' => (int) $company['id'], 'description' => $email . ' as ' . $role]);

        return model(CompanyMemberModel::class)->find($memberId);
    }

    public function setPermissions(int $memberId, array $permissions): void
    {
        $m = model(CompanyMemberPermissionModel::class);
        $m->where('company_member_id', $memberId)->delete();
        foreach (array_intersect($permissions, \App\Libraries\CompanyPermissions::keys()) as $p) {
            $m->insert(['company_member_id' => $memberId, 'permission_key' => $p]);
        }
        service('companyContext')->flush();
    }

    public function members(int $companyId): array
    {
        $rows = db_connect()->table('company_members cm')->select('cm.*, ai.secret AS email, u.active, u.last_active')
            ->join('users u', 'u.id = cm.user_id')
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left')
            ->where('cm.company_id', $companyId)->orderBy('cm.id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['permissions'] = array_column(model(CompanyMemberPermissionModel::class)->where('company_member_id', $r['id'])->findAll(), 'permission_key');
        }

        return $rows;
    }
}

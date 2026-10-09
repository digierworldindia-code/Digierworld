<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\BuyerProfileModel;
use App\Models\CompanyModel;

class Companies extends AdminController
{
    public function index(): string
    {
        $type = $this->request->getGet('type') === 'buyer' ? 'buyer' : 'supplier';
        $m    = model(CompanyModel::class)->where('company_type', $type);
        if ($q = trim((string) $this->request->getGet('q'))) {
            $m->groupStart()->like('legal_name', $q)->orLike('trade_name', $q)->orLike('registration_number', $q)->orLike('public_alias', $q)->groupEnd();
        }
        foreach (['verification_status', 'status', 'country_code'] as $f) {
            if ($v = $this->request->getGet($f)) {
                $m->where($f, $v);
            }
        }
        $m->orderBy('id', 'DESC');

        return $this->page('companies', ['title' => $type === 'buyer' ? 'Buyers' : 'Suppliers', 'type' => $type, 'rows' => $m->paginate(25), 'pager' => $m->pager]);
    }

    public function show(int $id): string
    {
        $c = model(CompanyModel::class)->find($id);
        if (! $c) {
            $this->notFound();
        }
        $db  = db_connect();
        $ent = service('entitlements');

        return $this->page('company', [
            'title' => $c['legal_name'], 'c' => $c, 'plan' => $ent->plan($c),
            'profile' => $c['company_type'] === 'supplier' ? $db->table('supplier_profiles')->where('company_id', $id)->get()->getRowArray() : $db->table('buyer_profiles')->where('company_id', $id)->get()->getRowArray(),
            'members' => service('companies')->members($id),
            'application' => service('verification')->current($id),
            'subscriptions' => $db->table('subscriptions s')->select('s.*, mp.name AS plan_name')->join('membership_plans mp', 'mp.id = s.plan_id')->where('s.company_id', $id)->orderBy('s.id', 'DESC')->get()->getResultArray(),
            'counts' => [
                'products' => $db->table('products')->where('company_id', $id)->where('deleted_at', null)->countAllResults(),
                'orders' => $db->table('orders')->groupStart()->where('buyer_company_id', $id)->orWhere('supplier_company_id', $id)->groupEnd()->countAllResults(),
                'rfqs' => $db->table('rfqs')->where('buyer_company_id', $id)->countAllResults(),
            ],
            'attributes' => $db->table('supplier_master_attributes a')->select('a.*, f.source_heading, f.field_group')->join('supplier_master_fields f', 'f.field_key = a.field_key', 'left')->where('a.company_id', $id)->orderBy('f.sort_order')->get()->getResultArray(),
            'managers' => $this->staffOptions(['account_manager']),
            'perf' => service('performance')->latestSupplier($id),
            'audit' => $db->table('audit_logs')->where('company_id', $id)->orderBy('id', 'DESC')->limit(15)->get()->getResultArray(),
        ]);
    }

    public function suspend(int $id)
    {
        $c = model(CompanyModel::class)->find($id) ?? $this->notFound();
        if ($r = $this->invalid(['reason' => 'required|max_length[1000]'])) {
            return $r;
        }
        service('verification')->suspend($c, $this->userId(), (string) $this->request->getPost('reason'));

        return redirect()->back()->with('success', 'Company suspended. Its listings are hidden and premium features paused.');
    }

    public function reactivate(int $id)
    {
        $c = model(CompanyModel::class)->find($id) ?? $this->notFound();
        service('verification')->reactivate($c, $this->userId());

        return redirect()->back()->with('success', 'Company reactivated.');
    }

    public function assignManager(int $id)
    {
        $c   = model(CompanyModel::class)->find($id) ?? $this->notFound();
        $uid = (int) $this->request->getPost('account_manager_user_id') ?: null;
        if ($uid && ! isset($this->staffOptions(['account_manager'])[$uid])) {
            return redirect()->back()->with('error', 'Choose an active account manager.');
        }
        model(CompanyModel::class)->update($id, ['account_manager_user_id' => $uid]);
        if ($uid) {
            service('notifications')->notifyUser($uid, 'account_manager.assigned', 'You were assigned to ' . $c['legal_name'], '', 'admin/companies/' . $id, false);
        }
        service('audit')->log('company.account_manager', ['entity_type' => 'company', 'entity_id' => $id, 'company_id' => $id, 'description' => 'Manager #' . $uid]);

        return redirect()->back()->with('success', 'Account manager updated.');
    }

    /**
     * Grants or revokes a verified buyer's access to restricted inventory.
     */
    public function buyerAccess(int $id)
    {
        $c = model(CompanyModel::class)->find($id) ?? $this->notFound();
        if ($c['company_type'] !== 'buyer') {
            return redirect()->back()->with('error', 'Only buyers have restricted-inventory access.');
        }
        $grant = $this->request->getPost('grant') === '1';
        if ($grant && ! service('entitlements')->isVerifiedBuyer($c)) {
            return redirect()->back()->with('error', 'Only verified buyers can be granted restricted inventory access.');
        }
        model(BuyerProfileModel::class)->where('company_id', $id)->set(['restricted_inventory_access' => $grant ? 1 : 0])->update();
        service('audit')->log('buyer.restricted_access', ['entity_type' => 'company', 'entity_id' => $id, 'company_id' => $id, 'description' => $grant ? 'granted' : 'revoked', 'severity' => 'warning']);
        if ($grant) {
            service('notifications')->notifyCompany($id, 'buyer.restricted_access', 'Restricted inventory access granted', 'You can now see listings restricted to approved verified buyers.', 'marketplace');
        }

        return redirect()->back()->with('success', 'Restricted inventory access ' . ($grant ? 'granted' : 'revoked') . '.');
    }

    public function recomputeScores(int $id)
    {
        $c = model(CompanyModel::class)->find($id) ?? $this->notFound();
        $c['company_type'] === 'supplier' ? service('performance')->computeSupplier($id) : service('performance')->computeBuyer($id);

        return redirect()->back()->with('success', 'Scores recalculated from current data.');
    }
}

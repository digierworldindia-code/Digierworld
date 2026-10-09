<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\MembershipPlanModel;
use App\Models\PlanEntitlementModel;
use App\Models\SubscriptionModel;
use App\Services\EntitlementService;

/**
 * Membership plans & entitlements. Changing an entitlement here changes
 * effective access immediately for every company on that plan (TEST 7).
 */
class Memberships extends AdminController
{
    public function index(): string
    {
        $plans = db_connect()->table('membership_plans p')->select('p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.status = \'active\') AS active_subs', false)->orderBy('sort_order')->get()->getResultArray();

        return $this->page('memberships', ['title' => 'Membership plans', 'plans' => $plans]);
    }

    public function edit(int $id): string
    {
        $plan = model(MembershipPlanModel::class)->find($id) ?? $this->notFound();

        return $this->page('membership_edit', ['title' => 'Edit plan: ' . $plan['name'], 'plan' => $plan,
            'ents' => array_column(model(PlanEntitlementModel::class)->where('plan_id', $id)->findAll(), null, 'feature_key'),
            'features' => array_filter(EntitlementService::FEATURES, static fn ($f) => $f[1] === $plan['audience'])]);
    }

    public function update(int $id)
    {
        $plan = model(MembershipPlanModel::class)->find($id) ?? $this->notFound();
        if ($r = $this->invalid(['name' => 'required|max_length[100]', 'annual_fee' => 'required|decimal|greater_than_equal_to[0]', 'currency' => 'required|exact_length[3]', 'duration_months' => 'required|is_natural_no_zero|less_than_equal_to[60]', 'description' => 'permit_empty|max_length[2000]'])) {
            return $r;
        }
        $db = db_connect();
        $db->transBegin();

        try {
            $planData = ['name' => $this->request->getPost('name'), 'annual_fee' => $this->request->getPost('annual_fee'), 'currency' => $this->request->getPost('currency'),
                'duration_months' => (int) $this->request->getPost('duration_months'), 'description' => $this->request->getPost('description'), 'is_active' => $this->request->getPost('is_active') === '0' ? 0 : 1,
                'approval_status' => $this->request->getPost('approval_status') === 'approved' ? 'approved' : 'pending_approval'];
            model(MembershipPlanModel::class)->update($id, $planData);
            $m = model(PlanEntitlementModel::class);
            $ents = (array) $this->request->getPost('ent');
            $limits = (array) $this->request->getPost('limit');
            foreach (EntitlementService::FEATURES as $key => [, $aud]) {
                if ($aud !== $plan['audience']) {
                    continue;
                }
                $row = ['is_enabled' => ($ents[$key] ?? '0') === '1' ? 1 : 0, 'limit_value' => isset($limits[$key]) && ctype_digit((string) $limits[$key]) ? (int) $limits[$key] : null];
                $existing = $m->where('plan_id', $id)->where('feature_key', $key)->first();
                $existing ? $m->update($existing['id'], $row) : $m->insert($row + ['plan_id' => $id, 'feature_key' => $key]);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
        service('entitlements')->flush();
        // Ranking depends on plan boost.
        foreach (db_connect()->table('subscriptions')->select('company_id')->where('plan_id', $id)->where('status', 'active')->get()->getResultArray() as $s) {
            service('ranking')->refresh((int) $s['company_id']);
        }
        service('audit')->log('membership.plan_updated', ['entity_type' => 'membership_plan', 'entity_id' => $id, 'old_values' => $plan, 'new_values' => $planData + ['entitlements' => $ents], 'severity' => 'warning']);

        return redirect()->back()->with('success', 'Plan saved. Entitlement changes apply immediately to all companies on this plan.');
    }

    public function subscriptions(): string
    {
        $m = model(SubscriptionModel::class)->select('subscriptions.*, companies.legal_name, companies.is_sample, membership_plans.name AS plan_name')
            ->join('companies', 'companies.id = subscriptions.company_id')->join('membership_plans', 'membership_plans.id = subscriptions.plan_id');
        if ($s = $this->request->getGet('status')) {
            $m->where('subscriptions.status', $s);
        }
        if ($this->request->getGet('expiring')) {
            $m->where('subscriptions.status', 'active')->where('subscriptions.ends_on <=', date('Y-m-d', strtotime('+30 days')));
        }
        $m->orderBy('subscriptions.id', 'DESC');

        return $this->page('subscriptions', ['title' => 'Supplier subscriptions', 'rows' => $m->paginate(30), 'pager' => $m->pager]);
    }

    public function runDaily()
    {
        $out = service('membership')->expireDue();
        $rem = service('membership')->sendRenewalReminders();

        return redirect()->back()->with('success', "{$out} subscription(s) expired, {$rem} reminder(s) sent.");
    }

    public function cancelSubscription(int $id)
    {
        $s = model(SubscriptionModel::class)->find($id) ?? $this->notFound();
        if (! in_array($s['status'], ['pending_payment', 'active'], true)) {
            return redirect()->back()->with('error', 'Only pending or active subscriptions can be cancelled.');
        }
        model(SubscriptionModel::class)->update($id, ['status' => 'cancelled', 'notes' => trim(($s['notes'] ?? '') . "\nCancelled: " . $this->request->getPost('reason'))]);
        if ($s['invoice_id']) {
            db_connect()->table('invoices')->where('id', $s['invoice_id'])->where('status', 'issued')->update(['status' => 'void']);
        }
        service('entitlements')->flush();
        service('ranking')->refresh((int) $s['company_id']);
        service('audit')->log('subscription.cancelled', ['entity_type' => 'subscription', 'entity_id' => $id, 'company_id' => (int) $s['company_id'], 'severity' => 'warning']);

        return redirect()->back()->with('success', 'Subscription cancelled.');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Models\MembershipPlanModel;
use App\Models\PlanEntitlementModel;
use App\Models\SubscriptionModel;
use App\Services\EntitlementService;

class Membership extends BaseController
{
    public function index(): string
    {
        $c     = $this->company();
        $plans = model(MembershipPlanModel::class)->where('audience', 'supplier')->where('is_active', 1)->orderBy('sort_order')->findAll();
        $ents  = [];
        foreach ($plans as $p) {
            $ents[$p['id']] = array_column(model(PlanEntitlementModel::class)->where('plan_id', $p['id'])->findAll(), null, 'feature_key');
        }

        return view('supplier/membership', [
            'title' => 'Membership', 'area' => 'supplier', 'company' => $c, 'plans' => $plans, 'ents' => $ents,
            'features' => array_filter(EntitlementService::FEATURES, static fn ($f) => $f[1] === 'supplier'),
            'current' => service('entitlements')->plan($c), 'active' => service('membership')->activeSubscription((int) $c['id']),
            'pending' => service('membership')->pendingSubscription((int) $c['id']),
            'history' => model(SubscriptionModel::class)->select('subscriptions.*, membership_plans.name AS plan_name')->join('membership_plans', 'membership_plans.id = subscriptions.plan_id')->where('company_id', $c['id'])->orderBy('subscriptions.id', 'DESC')->findAll(),
        ]);
    }

    public function subscribe()
    {
        $plan = service('membership')->verifiedPlan();
        if (! $plan) {
            return redirect()->back()->with('error', 'The Verified plan is not available.');
        }

        return $this->attempt(fn () => service('membership')->ensureVerifiedActivation($this->company(), $this->userId()) ?? throw new \App\Exceptions\BusinessRuleException('Unable to create the subscription.'), 'Membership invoice issued. Pay it under Invoices & payments to activate your Verified Supplier badge.', 'supplier/billing');
    }

    public function renew()
    {
        return $this->attempt(fn () => service('membership')->renew($this->company(), $this->userId()), 'Renewal invoice issued. Your new term starts when the current one ends.', 'supplier/billing');
    }
}

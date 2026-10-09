<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CompanyModel;
use App\Models\MembershipPlanModel;
use App\Models\SubscriptionModel;

/**
 * Supplier membership plans and annual subscriptions.
 */
class MembershipService
{
    public function __construct(
        private SubscriptionModel $subs = new SubscriptionModel(),
        private MembershipPlanModel $plans = new MembershipPlanModel(),
    ) {
    }

    public function verifiedPlan(): ?array
    {
        return $this->plans->where('code', policy('membership.verified_plan_code', 'supplier_verified'))->where('is_active', 1)->first();
    }

    public function activeSubscription(int $companyId): ?array
    {
        return $this->subs->where('company_id', $companyId)->where('status', 'active')
            ->where('ends_on >=', date('Y-m-d'))->orderBy('ends_on', 'DESC')->first();
    }

    public function pendingSubscription(int $companyId): ?array
    {
        return $this->subs->where('company_id', $companyId)->where('status', 'pending_payment')->orderBy('id', 'DESC')->first();
    }

    /**
     * After verification approval: make sure a Verified plan subscription
     * exists (pending payment with an invoice) unless one is already active.
     * Free plans (fee 0) activate immediately.
     */
    public function ensureVerifiedActivation(array $company, ?int $userId): ?array
    {
        if ($active = $this->activeSubscription((int) $company['id'])) {
            $plan = $this->plans->find($active['plan_id']);
            if ($plan && $plan['requires_verification']) {
                return $active;
            }
        }
        if ($pending = $this->pendingSubscription((int) $company['id'])) {
            return $pending;
        }
        $plan = $this->verifiedPlan();
        if ($plan === null) {
            return null;
        }

        return $this->createSubscription($company, $plan, $userId);
    }

    public function createSubscription(array $company, array $plan, ?int $userId, ?int $renewalOf = null): array
    {
        if ($plan['audience'] !== 'supplier') {
            throw new BusinessRuleException('Only supplier plans can be subscribed to.');
        }
        if ($plan['requires_verification'] && $company['verification_status'] !== 'verified') {
            throw new BusinessRuleException('This plan requires completed company verification. Please complete verification first — compliance approval and membership payment are handled separately.');
        }
        $db = db_connect();
        $db->transBegin();

        try {
            $subId = (int) $this->subs->insert([
                'company_id' => $company['id'], 'plan_id' => $plan['id'], 'status' => 'pending_payment',
                'renewal_of_id' => $renewalOf, 'created_by' => $userId,
            ]);
            if ((float) $plan['annual_fee'] <= 0) {
                $this->activate($this->subs->find($subId));
            } else {
                $invoice = service('billing')->issueInvoice((int) $company['id'], 'subscription', $plan['currency'], (float) $plan['annual_fee'], 'subscription', $subId, $plan['name'] . ' — annual membership');
                $this->subs->update($subId, ['invoice_id' => $invoice['id']]);
                service('notifications')->notifyCompany((int) $company['id'], 'membership.invoice', 'Membership invoice issued', "Invoice {$invoice['invoice_number']} for the {$plan['name']} plan has been issued.", 'supplier/membership');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        return $this->subs->find($subId);
    }

    public function activateFromInvoice(array $invoice): void
    {
        $sub = $this->subs->find($invoice['reference_id']);
        if ($sub && $sub['status'] === 'pending_payment') {
            $this->activate($sub);
        }
    }

    /**
     * Activates a paid subscription. Renewals start the day after the current
     * period ends so no paid days are lost.
     */
    public function activate(array $sub): void
    {
        $plan  = $this->plans->find($sub['plan_id']);
        $start = new \DateTimeImmutable('today');
        if ($current = $this->activeSubscription((int) $sub['company_id'])) {
            if ((int) $current['id'] !== (int) $sub['id'] && (int) $current['plan_id'] === (int) $sub['plan_id']) {
                $start = (new \DateTimeImmutable($current['ends_on']))->modify('+1 day');
            }
        }
        $end = $start->modify('+' . (int) $plan['duration_months'] . ' months')->modify('-1 day');
        $this->subs->update($sub['id'], ['status' => 'active', 'starts_on' => $start->format('Y-m-d'), 'ends_on' => $end->format('Y-m-d')]);
        service('entitlements')->flush();

        $company = model(CompanyModel::class)->find($sub['company_id']);
        service('ranking')->refresh((int) $sub['company_id']);
        service('notifications')->notifyCompany((int) $sub['company_id'], 'membership.activated', $plan['name'] . ' membership active', 'Valid from ' . $start->format('d M Y') . ' to ' . $end->format('d M Y') . '.', 'supplier/membership');
        service('audit')->log('subscription.activated', ['entity_type' => 'subscription', 'entity_id' => (int) $sub['id'], 'company_id' => (int) $company['id']]);
    }

    /**
     * Marks lapsed subscriptions as expired (entitlements already stop on
     * ends_on because EntitlementService checks dates).
     */
    public function expireDue(): int
    {
        $due = $this->subs->where('status', 'active')->where('ends_on <', date('Y-m-d'))->findAll();
        foreach ($due as $s) {
            $this->subs->update($s['id'], ['status' => 'expired']);
            service('ranking')->refresh((int) $s['company_id']);
            service('notifications')->notifyCompany((int) $s['company_id'], 'membership.expired', 'Membership expired', 'Your Verified Supplier membership has expired. Premium features are paused until you renew.', 'supplier/membership');
            service('audit')->log('subscription.expired', ['entity_type' => 'subscription', 'entity_id' => (int) $s['id'], 'company_id' => (int) $s['company_id']]);
        }
        service('entitlements')->flush();

        return count($due);
    }

    public function sendRenewalReminders(): int
    {
        $days = (array) policy('membership.reminder_days', [30, 7]);
        $sent = 0;
        foreach ($days as $d) {
            $target = date('Y-m-d', strtotime('+' . (int) $d . ' days'));
            $rows   = $this->subs->where('status', 'active')->where('ends_on', $target)->findAll();
            foreach ($rows as $s) {
                service('notifications')->notifyCompany((int) $s['company_id'], 'membership.expiring', 'Membership expiring in ' . (int) $d . ' days', 'Your membership ends on ' . date('d M Y', strtotime($s['ends_on'])) . '. Renew to keep your badge and premium features.', 'supplier/membership');
                $this->subs->update($s['id'], ['reminder_sent_at' => date('Y-m-d H:i:s')]);
                $sent++;
            }
        }

        return $sent;
    }

    public function renew(array $company, ?int $userId): array
    {
        $plan = $this->verifiedPlan();
        if (! $plan) {
            throw new BusinessRuleException('The verified plan is not available.');
        }
        if ($this->pendingSubscription((int) $company['id'])) {
            throw new BusinessRuleException('A renewal invoice is already awaiting payment.');
        }
        $current = $this->activeSubscription((int) $company['id']);

        return $this->createSubscription($company, $plan, $userId, $current['id'] ?? null);
    }
}

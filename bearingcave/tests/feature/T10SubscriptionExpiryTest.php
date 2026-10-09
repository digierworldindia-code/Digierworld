<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanyModel;
use App\Models\SubscriptionModel;
use Tests\Support\BCTestCase;

/**
 * TEST 10: Supplier subscription expires → correct renewal/permission behaviour.
 */
final class T10SubscriptionExpiryTest extends BCTestCase
{
    public function testExpiryRevokesPremiumFeaturesAndRenewalRestoresThem(): void
    {
        $s   = $this->verifiedSupplier();
        $cid = (int) $s['company']['id'];
        $sub = model(SubscriptionModel::class)->where('company_id', $cid)->where('status', 'active')->first();
        $this->assertTrue(service('entitlements')->isVerifiedSupplier($s['company']));

        // Reminder 7 days before the end.
        model(SubscriptionModel::class)->update($sub['id'], ['ends_on' => date('Y-m-d', strtotime('+7 days'))]);
        $this->assertSame(1, service('membership')->sendRenewalReminders());

        // Lapse: entitlements stop on the end date even before the nightly job runs.
        model(SubscriptionModel::class)->update($sub['id'], ['starts_on' => date('Y-m-d', strtotime('-1 year')), 'ends_on' => date('Y-m-d', strtotime('-1 day'))]);
        $this->flush();
        $company = model(CompanyModel::class)->find($cid);
        $this->assertFalse(service('entitlements')->isVerifiedSupplier($company));
        $this->assertSame('supplier_free', service('entitlements')->plan($company)['code']);
        $this->getAs($s['user'], 'supplier/imports')->assertRedirectTo(site_url('supplier/membership'));
        $__r = $this->getAs($s['user'], 'supplier/rfqs');
        $__r->assertOK();
        $__r->assertSee('Verified Supplier feature');

        $this->assertSame(1, service('membership')->expireDue());
        $this->assertSame('expired', model(SubscriptionModel::class)->find($sub['id'])['status']);
        $this->assertSame(1, db_connect()->table('notifications')->where('type', 'membership.expired')->countAllResults() > 0 ? 1 : 0);

        // Company stays compliance-verified; renewal = new invoice → payment → active again.
        $this->assertSame('verified', $company['verification_status']);
        $this->postAs($s['user'], 'supplier/membership/renew')->assertRedirect();
        $pending = service('membership')->pendingSubscription($cid);
        $this->assertNotNull($pending);
        $inv = db_connect()->table('invoices')->where('id', $pending['invoice_id'])->get()->getRowArray();
        service('billing')->recordManualPayment($inv, (float) $inv['total'], 'UTR-RENEW', date('Y-m-d'), (int) $this->staff('finance_manager')->id);
        $this->flush();
        $this->assertTrue(service('entitlements')->isVerifiedSupplier(model(CompanyModel::class)->find($cid)));
        $this->getAs($s['user'], 'supplier/imports')->assertOK();
    }
}

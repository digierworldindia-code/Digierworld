<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanyModel;
use App\Models\VerificationApplicationModel;
use Tests\Support\BCTestCase;

/**
 * TEST 1: Supplier registers → uploads documents → admin reviews → account
 * approved → subscription activated → verified badge shown.
 */
final class T01SupplierVerificationTest extends BCTestCase
{
    public function testFullSupplierOnboardingThroughHttp(): void
    {
        // 1. Register through the public form.
        $email = $this->uniqueEmail('apex');
        $r = $this->postAs(null, 'register/supplier', [
            'legal_name' => 'Apex Test Components Pvt Ltd', 'country_code' => 'IN', 'city' => 'Pune', 'phone' => '+91 9000000000',
            'supplier_type' => 'manufacturer', 'email' => $email, 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD, 'terms' => '1',
        ]);
        $r->assertRedirectTo(site_url('supplier/dashboard'));
        $user    = auth()->getProvider()->findByCredentials(['email' => $email]);
        $company = model(CompanyModel::class)->where('legal_name', 'Apex Test Components Pvt Ltd')->first();
        $this->assertNotNull($user);
        $this->assertSame('unverified', $company['verification_status']);
        $this->assertTrue($user->inGroup('supplier'));

        // 2. Complete profile, KYC, director, then submit (missing items block submission first).
        $this->postAs($user, 'supplier/verification/submit')->assertRedirect();
        $this->assertStringContainsString('Please complete', (string) $this->flashError());

        $this->postAs($user, 'supplier/company', ['legal_name' => $company['legal_name'], 'country_code' => 'IN', 'city' => 'Pune', 'address_line1' => 'Plot 1, MIDC',
            'phone' => '+91 9000000000', 'email' => $email])->assertRedirect();
        $this->postAs($user, 'supplier/verification/kyc', ['gst_number' => '27AAACT1234A1Z5', 'pan_number' => 'AAACT1234A', 'bank_account_name' => 'Apex',
            'bank_account_number' => '123456789012', 'bank_name' => 'Test Bank', 'bank_ifsc_swift' => 'TEST0001234', 'bank_country' => 'IN'])->assertRedirect();
        $kyc = db_connect()->table('kyc_records')->where('company_id', $company['id'])->get()->getRowArray();
        $this->assertSame('9012', $kyc['bank_account_last4']);
        $this->assertStringNotContainsString('123456789012', $kyc['bank_account_number_enc'], 'bank account must be encrypted at rest');
        $this->postAs($user, 'supplier/verification/directors', ['full_name' => 'Test Director', 'designation' => 'MD', 'ownership_percent' => '100', 'is_ubo' => '1'])->assertRedirect();

        // Documents: stored privately through DocumentService (HTTP multipart is covered by the browser E2E run).
        $this->actingAs($user);
        $doc = service('documents')->store($this->pdf('registration.pdf'), ['company_id' => (int) $company['id'], 'entity_type' => 'company', 'entity_id' => (int) $company['id'], 'doc_type' => 'registration_certificate', 'title' => 'Registration certificate']);
        $this->assertFileExists(WRITEPATH . 'uploads/private/' . $doc['stored_path']);
        $this->assertStringNotContainsString(FCPATH, WRITEPATH . 'uploads/private/' . $doc['stored_path'], 'documents live outside the public webroot');

        $this->postAs($user, 'supplier/verification/submit')->assertRedirect();
        $app = model(VerificationApplicationModel::class)->where('company_id', $company['id'])->first();
        $this->assertSame('submitted', $app['status']);
        $this->assertSame('in_review', model(CompanyModel::class)->find($company['id'])['verification_status']);

        // 3. Verification officer reviews every module.
        $officer = $this->staff('verification_officer');
        $__r = $this->getAs($officer, 'admin/verifications/' . $app['id']);
        $__r->assertOK();
        $__r->assertSee('Apex Test Components');
        $this->postAs($officer, "admin/verifications/{$app['id']}/sanctions", ['subject_type' => 'company', 'subject_name' => $company['legal_name'], 'lists_checked' => 'UN, OFAC, PEP', 'result' => 'clear'])->assertRedirect();
        $this->postAs($officer, "admin/verifications/{$app['id']}/risk", ['financial_risk' => '2', 'operational_risk' => '2', 'compliance_risk' => '1', 'geographic_risk' => '2', 'supply_risk' => '2', 'esg_risk' => '2', 'evidence_notes' => 'Audited statements, site visit'])->assertRedirect();
        $this->postAs($officer, "admin/verifications/{$app['id']}/documents/{$doc['id']}", ['review_status' => 'approved'])->assertRedirect();
        foreach (service('verification')->stagesOf((int) $app['id']) as $s) {
            if (in_array($s['status'], ['pending', 'in_review'], true) && $s['stage_key'] !== 'management_approval') {
                $this->postAs($officer, "admin/verifications/{$app['id']}/stage", ['stage_key' => $s['stage_key'], 'status' => 'passed', 'comments' => 'ok'])->assertRedirect();
            }
        }
        // Officer lacks verification.approve → cannot give management approval.
        $this->postAs($officer, "admin/verifications/{$app['id']}/approve")->assertRedirect();
        $this->assertSame('in_review', model(CompanyModel::class)->find($company['id'])['verification_status']);

        // 4. Management approval → verified, but badge waits for membership payment.
        $admin = $this->staff('superadmin');
        $this->postAs($admin, "admin/verifications/{$app['id']}/approve", ['notes' => 'Approved'])->assertRedirect();
        $company = model(CompanyModel::class)->find($company['id']);
        $this->assertSame('verified', $company['verification_status']);
        $this->flush();
        $this->assertFalse(service('entitlements')->isVerifiedSupplier($company), 'badge requires paid membership');
        $sub = service('membership')->pendingSubscription((int) $company['id']);
        $this->assertNotNull($sub);
        $invoice = db_connect()->table('invoices')->where('id', $sub['invoice_id'])->get()->getRowArray();
        $this->assertSame(50000.0, (float) $invoice['subtotal']);

        // 5. Finance records the bank receipt → subscription active → badge.
        $finance = $this->staff('finance_manager');
        $this->postAs($finance, "admin/invoices/{$invoice['id']}/record", ['amount' => $invoice['total'], 'reference' => 'UTR123456', 'paid_on' => date('Y-m-d')])->assertRedirect();
        $this->flush();
        $this->assertSame('active', db_connect()->table('subscriptions')->where('id', $sub['id'])->get()->getRow()->status);
        $this->assertTrue(service('entitlements')->isVerifiedSupplier(model(CompanyModel::class)->find($company['id'])));

        // 6. Badge visible on the public product page.
        $p = $this->product(model(CompanyModel::class)->find($company['id']));
        $__r = $this->getAs(null, 'product/' . $p['slug']);
        $__r->assertOK();
        $__r->assertSee('Verified Supplier');
    }
}

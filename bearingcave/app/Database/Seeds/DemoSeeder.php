<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Libraries\Payments\SandboxGateway;
use App\Models\BuyerProfileModel;
use App\Models\CompanyModel;
use App\Models\ProductModel;
use App\Models\RfqMatchModel;
use App\Models\VerificationStageModel;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;

/**
 * DEMO DATA — clearly labelled sample records for client demonstrations.
 *
 *   php spark db:seed DemoSeeder
 *
 * - Refuses to run when CI_ENVIRONMENT=production.
 * - Every company is named "[SAMPLE] …" and flagged is_sample = 1.
 * - Logins use example.com addresses with RANDOM passwords generated at run
 *   time, printed once and written to writable/demo/credentials.txt (git-ignored).
 * - Verification steps are recorded as "SAMPLE — no real check performed".
 * - The membership payment uses the SANDBOX gateway (no money moves).
 */
class DemoSeeder extends Seeder
{
    private array $credentials = [];

    public function run(): void
    {
        if (ENVIRONMENT === 'production') {
            CLI::error('DemoSeeder refuses to run in production.');

            return;
        }
        helper(['app']);
        if ($this->db->table('companies')->where('is_sample', 1)->countAllResults() > 0) {
            CLI::write('Sample data already present — skipping. (Use a fresh database to re-seed.)', 'yellow');

            return;
        }
        service('settings_store')->flush();

        // Staff accounts (one per role).
        foreach (['superadmin', 'verification_officer', 'procurement_manager', 'inspection_manager', 'logistics_manager', 'finance_manager', 'account_manager', 'support_executive'] as $g) {
            $this->user('demo.' . str_replace('_', '-', $g) . '@example.com', [$g]);
        }
        $admin = auth()->getProvider()->findByCredentials(['email' => 'demo.superadmin@example.com']);

        // Companies.
        $supA = $this->company('supplier', '[SAMPLE] Apex Bearings & Components Pvt Ltd', 'IN', 'Pune', 'demo.supplier.verified@example.com', 'manufacturer');
        $supB = $this->company('supplier', '[SAMPLE] Rheinland Surplus Teile GmbH', 'DE', 'Cologne', 'demo.supplier.free@example.com', 'stockist');
        $buyA = $this->company('buyer', '[SAMPLE] Gulf Fleet Spares Trading LLC', 'AE', 'Dubai', 'demo.buyer.verified@example.com');
        $buyB = $this->company('buyer', '[SAMPLE] Metro Auto Spares Distributors', 'IN', 'Delhi', 'demo.buyer@example.com');
        $this->user('demo.supplier.employee@example.com', ['supplier'], $supA['id'], 'employee', ['inventory.manage', 'rfq.manage']);

        $db = $this->db;
        $db->table('kyc_records')->insert(['company_id' => $supA['id'], 'gst_number' => 'SAMPLE-GST-000', 'pan_number' => 'SAMPLEPAN0', 'bank_account_name' => $supA['legal_name'],
            'bank_account_number_enc' => base64_encode(service('encrypter')->encrypt('000000000000')), 'bank_account_last4' => '0000', 'bank_name' => 'Sample Bank', 'bank_ifsc_swift' => 'SMPL0000000', 'bank_country' => 'IN', 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('company_directors')->insert(['company_id' => $supA['id'], 'full_name' => 'Sample Director (fictional)', 'designation' => 'Managing Director', 'nationality' => 'IN', 'ownership_percent' => 60, 'is_ubo' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('kyc_records')->insert(['company_id' => $buyA['id'], 'vat_number' => 'SAMPLE-TRN-000', 'created_at' => date('Y-m-d H:i:s')]);

        // Verify supplier A and buyer A through the real workflow (SAMPLE notes).
        $this->verify($supA, (int) $admin->id);
        $this->verify($buyA, (int) $admin->id);
        model(BuyerProfileModel::class)->where('company_id', $buyA['id'])->set(['restricted_inventory_access' => 1])->update();
        model(CompanyModel::class)->update($supA['id'], ['public_profile_enabled' => 1, 'description' => 'SAMPLE DATA — fictional manufacturer used to demonstrate BearingCave.',
            'account_manager_user_id' => auth()->getProvider()->findByCredentials(['email' => 'demo.account-manager@example.com'])->id]);

        // Membership payment through the sandbox gateway + signed webhook.
        $sub = service('membership')->pendingSubscription((int) $supA['id']);
        if ($sub) {
            $invoice = $db->table('invoices')->where('id', $sub['invoice_id'])->get()->getRowArray();
            $payment = service('billing')->startPayment($invoice, 'sandbox', 'demo-seed-' . $invoice['id']);
            $body    = json_encode(['event_id' => 'evt_demo_' . $invoice['id'], 'type' => 'payment.succeeded', 'payment_reference' => $payment['provider_reference'], 'status' => 'succeeded', 'amount' => (float) $payment['amount']]);
            service('billing')->handleWebhook('sandbox', $body, ['x-sandbox-signature' => SandboxGateway::sign($body)]);
        }
        service('entitlements')->flush();

        // Inventory.
        $cat = static fn (string $slug) => (int) db_connect()->table('categories')->where('slug', $slug)->get()->getRow()->id;
        $brand = static fn (string $slug) => (int) (db_connect()->table('brands')->where('slug', $slug)->get()->getRow()->id ?? 0) ?: null;
        $supAFull = model(CompanyModel::class)->find($supA['id']);
        $items = [
            ['APX-6204-2RS', 'Deep groove ball bearing, 2RS sealed', '6204-2RS', 'ball-bearings', 'skf', 'piece', 18.5, null, null, 50, 1200, '-3 months', ['id' => 20, 'od' => 47, 'w' => 14], "FAG:6204-2RSR\nNSK:6204DDU", 'public', 'all'],
            ['APX-6205-2Z', 'Deep groove ball bearing, 2Z shielded', '6205-2Z', 'ball-bearings', 'nsk', 'both', 22.0, 9500, 500, 100, 2500, '-9 months', ['id' => 25, 'od' => 52, 'w' => 15], 'NTN:6205ZZ', 'public', 'all'],
            ['APX-30205', 'Tapered roller bearing', '30205', 'tapered-roller-bearings', 'timken', 'lot', null, 64000, 800, 800, 1600, '-15 months', ['id' => 25, 'od' => 52, 'w' => 16.25], '', 'public', 'all'],
            ['APX-NU206', 'Cylindrical roller bearing NU type', 'NU206-ECP', 'cylindrical-roller-bearings', 'skf', 'piece', 96.0, null, null, 20, 340, '-14 months', ['id' => 30, 'od' => 62, 'w' => 16], '', 'verified_buyers', 'all'],
            ['APX-HUB-01', 'Front wheel hub bearing unit (sample)', 'SMP-HUB-0001', 'wheel-hub-bearings-units', 'other-unbranded', 'lot', null, 180000, 120, 120, 240, '-20 months', [], '', 'public', 'allow_list'],
            ['APX-6305-C3', 'Deep groove ball bearing, C3 clearance', '6305-2RS-C3', 'ball-bearings', 'schaeffler-fag-ina-luk', 'piece', 41.0, null, null, 25, 600, '-7 months', ['id' => 25, 'od' => 62, 'w' => 17], 'SKF:6305-2RS1/C3', 'public', 'all'],
            ['APX-HK2016', 'Drawn cup needle roller bearing', 'HK2016', 'needle-roller-bearings', 'schaeffler-fag-ina-luk', 'lot', null, 21000, 1000, 1000, 5000, '-30 months', ['id' => 20, 'od' => 26, 'w' => 16], '', 'public', 'all'],
            ['APX-CR-01', 'Clutch release bearing (sample)', 'SMP-CRB-0101', 'clutch-release-bearings', 'other-unbranded', 'lot', null, 45000, 150, 150, 300, '-11 months', [], '', 'hidden', 'all'],
        ];
        foreach ($items as [$sku, $name, $pn, $catSlug, $brandSlug, $mode, $unit, $lot, $lotQty, $moq, $qty, $age, $dims, $xref, $vis, $cmode]) {
            $p = service('products')->save($supAFull, [
                'sku' => $sku, 'name' => $name, 'part_number' => $pn, 'oem_part_number' => '', 'category_id' => $cat($catSlug), 'brand_id' => $brand($brandSlug),
                'item_condition' => $age === '-30 months' ? 'new_old_stock' : 'new', 'sale_mode' => $mode, 'unit_price' => $unit ?? '', 'lot_price' => $lot ?? '', 'lot_quantity' => $lotQty ?? '',
                'moq' => $moq, 'currency' => 'INR', 'price_visibility' => $pn === 'SMP-HUB-0001' ? 'on_request' : 'show', 'visibility' => $vis, 'country_mode' => $cmode,
                'countries' => $cmode === 'allow_list' ? ['AE', 'SA', 'IN'] : [], 'warehouse_city' => 'Pune', 'warehouse_country' => 'IN',
                'description' => 'SAMPLE DATA — fictional surplus listing for demonstration.', 'inner_diameter_mm' => $dims['id'] ?? '', 'outer_diameter_mm' => $dims['od'] ?? '', 'width_mm' => $dims['w'] ?? '',
                'weight_kg' => '', 'cross_references' => $xref, 'hide_supplier_identity' => $sku === 'APX-6204-2RS' ? 0 : 1, 'shipping_options' => ['platform_managed', 'confidential', 'supplier_direct'],
                'specs' => [['name' => 'Seal / shield', 'value' => str_contains($pn, '2RS') ? '2RS (rubber seals)' : (str_contains($pn, '2Z') ? '2Z (metal shields)' : 'Open'), 'unit' => ''], ['name' => 'Packaging', 'value' => 'Original boxes, sample data', 'unit' => '']],
                'tiers' => $mode !== 'lot' ? [['min_qty' => $moq * 10, 'max_qty' => '', 'unit_price' => round((float) $unit * 0.9, 2)]] : [],
            ], null, true);
            service('inventory')->receive((int) $p['id'], $qty, date('Y-m-d', strtotime($age)), 'SMP-' . $sku, 'Pune WH-1', 'receipt', 'Sample stock');
            $prod = model(ProductModel::class)->find($p['id']);
            service('products')->submit($prod, $supAFull, true, false);
            if ($pn === 'SMP-HUB-0001') {
                $db->table('fitments')->insert(['product_id' => $p['id'], 'make' => 'Sample Make', 'model' => 'Sample Model', 'year_from' => 2016, 'year_to' => 2022, 'created_at' => date('Y-m-d H:i:s')]);
            }
        }
        $supBFull = model(CompanyModel::class)->find($supB['id']);
        foreach ([['RST-6204', 'Ball bearing 6204 surplus lot', '6204-2RS', 'ball-bearings', 'schaeffler-fag-ina-luk', 22000, 2000, '-26 months', 'published'], ['RST-OS-01', 'Oil seal assortment surplus lot', 'SMP-OS-ASST', 'o-rings-oil-seals', 'other-unbranded', 8500, 3000, '-13 months', 'pending']] as [$sku, $name, $pn, $catSlug, $brandSlug, $lot, $qty, $age, $state]) {
            $p = service('products')->save($supBFull, [
                'sku' => $sku, 'name' => $name, 'part_number' => $pn, 'category_id' => $cat($catSlug), 'brand_id' => $brand($brandSlug), 'item_condition' => 'new_old_stock', 'sale_mode' => 'lot',
                'unit_price' => '', 'lot_price' => $lot, 'lot_quantity' => $qty, 'moq' => $qty, 'currency' => 'EUR', 'price_visibility' => 'show', 'visibility' => 'public', 'country_mode' => 'all',
                'warehouse_city' => 'Cologne', 'warehouse_country' => 'DE', 'description' => 'SAMPLE DATA — fictional surplus lot.', 'shipping_options' => ['platform_managed'],
            ], null, false);
            service('inventory')->receive((int) $p['id'], $qty, date('Y-m-d', strtotime($age)), null, 'Cologne', 'receipt', 'Sample stock');
            $prod = model(ProductModel::class)->find($p['id']);
            service('products')->submit($prod, $supBFull, false, false);
            if ($state === 'published') {
                service('products')->review(model(ProductModel::class)->find($p['id']), 'approve', 'SAMPLE approval', (int) $admin->id);
            }
        }

        // A sample RFQ from the verified buyer, matched and distributed.
        $buyAFull = model(CompanyModel::class)->find($buyA['id']);
        $buyerUser = auth()->getProvider()->findByCredentials(['email' => 'demo.buyer.verified@example.com']);
        $rfq = service('rfqs')->create($buyAFull, (int) $buyerUser->id, [
            'title' => '[SAMPLE] Quarterly requirement — 6204 sealed bearings', 'destination_country' => 'AE', 'delivery_terms' => 'CIF', 'currency' => 'INR',
            'deadline_at' => date('Y-m-d H:i:s', strtotime('+10 days')), 'comments' => 'SAMPLE DATA — demonstration RFQ.',
        ], [['part_number' => '6204-2RS', 'description' => 'Deep groove ball bearing, sealed', 'quantity' => 1000, 'unit' => 'pcs', 'category_id' => $cat('ball-bearings')]]);
        $rfq = model(\App\Models\RfqModel::class)->find($rfq['id']);
        $ids = array_map('intval', array_column(model(RfqMatchModel::class)->where('rfq_id', $rfq['id'])->findAll(), 'supplier_company_id'));
        if ($ids) {
            service('rfqs')->distribute($rfq, $ids, (int) $admin->id);
        }

        @mkdir(WRITEPATH . 'demo', 0700, true);
        $lines = ["BearingCave DEMO credentials — generated " . date('c'), 'SAMPLE DATA ONLY. Never use in production.', ''];
        foreach ($this->credentials as [$email, $pass, $role]) {
            $lines[] = str_pad($role, 26) . str_pad($email, 46) . $pass;
        }
        file_put_contents(WRITEPATH . 'demo/credentials.txt', implode(PHP_EOL, $lines) . PHP_EOL);
        @chmod(WRITEPATH . 'demo/credentials.txt', 0600);
        CLI::write(implode(PHP_EOL, $lines), 'green');
        CLI::write('Saved to writable/demo/credentials.txt', 'yellow');
    }

    private function user(string $email, array $groups, ?int $companyId = null, string $memberRole = 'owner', array $perms = []): User
    {
        $users = auth()->getProvider();
        $pass  = 'Demo-' . bin2hex(random_bytes(5)) . '!';
        $user  = new User(['username' => null, 'email' => $email, 'password' => $pass]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        foreach ($groups as $g) {
            $user->addGroup($g);
        }
        if ($companyId) {
            $mid = model(\App\Models\CompanyMemberModel::class)->insert(['company_id' => $companyId, 'user_id' => $user->id, 'member_role' => $memberRole, 'status' => 'active']);
            if ($perms) {
                service('companies')->setPermissions((int) $mid, $perms);
            }
        }
        $this->credentials[] = [$email, $pass, $companyId ? ($memberRole === 'employee' ? 'company employee' : $groups[0]) : $groups[0]];

        return $user;
    }

    private function company(string $type, string $name, string $cc, string $city, string $email, ?string $supplierType = null): array
    {
        $pass   = 'Demo-' . bin2hex(random_bytes(5)) . '!';
        $result = service('companies')->register($type, ['legal_name' => $name, 'country_code' => $cc, 'city' => $city, 'phone' => '+00 0000 000000', 'email' => $email, 'password' => $pass, 'supplier_type' => $supplierType]);
        model(CompanyModel::class)->update($result['company']['id'], ['is_sample' => 1, 'address_line1' => 'Sample address (fictional)', 'registration_number' => 'SAMPLE-' . strtoupper(bin2hex(random_bytes(3)))]);
        $this->credentials[] = [$email, $pass, $type . ' owner'];

        return model(CompanyModel::class)->find($result['company']['id']);
    }

    private function verify(array $company, int $adminId): void
    {
        $vs  = service('verification');
        $app = $vs->openApplication($company);
        model(\App\Models\VerificationApplicationModel::class)->update($app['id'], ['status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s')]);
        $app = model(\App\Models\VerificationApplicationModel::class)->find($app['id']);
        foreach ($vs->stagesOf((int) $app['id']) as $s) {
            if (in_array($s['status'], ['pending', 'in_review'], true) && $s['stage_key'] !== 'management_approval') {
                model(VerificationStageModel::class)->update($s['id'], ['status' => 'passed', 'reviewed_by' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s'), 'comments' => 'SAMPLE — demo stage, no real check performed']);
            }
        }
        model(CompanyModel::class)->update($company['id'], ['verification_status' => 'in_review']);
        $vs->approve($app, $adminId, 'SAMPLE DATA — demonstration approval, not a real verification.');
    }
}

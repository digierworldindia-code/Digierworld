<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Seeds\DatabaseSeeder;
use App\Models\CompanyModel;
use App\Models\ProductModel;
use App\Models\VerificationStageModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * Base class for BearingCave feature/integration tests.
 * Uses the real MySQL test database (database.tests.* in .env), all
 * migrations (Shield + Settings + App) and the reference/policy seeders.
 */
abstract class BCTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace   = null;
    protected $refresh     = true;
    protected $migrateOnce = true;
    protected $seed        = DatabaseSeeder::class;
    protected $seedOnce    = true;

    protected const PASSWORD = 'Gr8!Bearing#Cave2026';

    protected function setUp(): void
    {
        parent::setUp();
        // Never carry an authenticated user over from a previous test.
        Services::resetSingle('auth');
        $_SESSION = [];
        helper(['app', 'ui', 'nav', 'form', 'url', 'text']);
        $this->flush();
    }

    protected function flush(): void
    {
        service('visibility')->reset();
        service('companyContext')->flush();
        service('entitlements')->flush();
        service('settings_store')->flush();
    }

    protected function uniqueEmail(string $prefix): string
    {
        return $prefix . '.' . bin2hex(random_bytes(4)) . '@example.com';
    }

    /**
     * @return array{user: User, company: array}
     */
    protected function registerCompany(string $type, string $country = 'IN', ?string $name = null): array
    {
        $r = service('companies')->register($type, [
            'legal_name' => $name ?? ('Test ' . ucfirst($type) . ' ' . bin2hex(random_bytes(3))),
            'country_code' => $country, 'city' => 'Test City', 'phone' => '+91 0000000000',
            'email' => $this->uniqueEmail($type), 'password' => self::PASSWORD, 'supplier_type' => 'manufacturer',
        ]);
        model(CompanyModel::class)->update($r['company']['id'], ['address_line1' => '1 Test Street']);
        $this->flush();

        return ['user' => $r['user'], 'company' => model(CompanyModel::class)->find($r['company']['id'])];
    }

    protected function staff(string $group): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => null, 'email' => $this->uniqueEmail($group), 'password' => self::PASSWORD]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup($group);

        return $user;
    }

    /**
     * Fast-tracks verification through the real VerificationService (stages
     * passed by a staff reviewer, then management approval).
     */
    protected function verifyCompany(array $company): array
    {
        $admin = $this->staff('superadmin');
        $vs    = service('verification');
        $app   = $vs->openApplication($company);
        model(\App\Models\VerificationApplicationModel::class)->update($app['id'], ['status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s')]);
        foreach ($vs->stagesOf((int) $app['id']) as $s) {
            if ($s['status'] === 'pending' && $s['stage_key'] !== 'management_approval') {
                model(VerificationStageModel::class)->update($s['id'], ['status' => 'passed', 'reviewed_by' => $admin->id, 'reviewed_at' => date('Y-m-d H:i:s')]);
            }
        }
        $vs->approve(model(\App\Models\VerificationApplicationModel::class)->find($app['id']), (int) $admin->id, 'test');
        $this->flush();

        return model(CompanyModel::class)->find($company['id']);
    }

    /**
     * Verified supplier with a PAID verified-plan subscription.
     *
     * @return array{user: User, company: array}
     */
    protected function verifiedSupplier(string $country = 'IN'): array
    {
        $r = $this->registerCompany('supplier', $country);
        $c = $this->verifyCompany($r['company']);
        $sub = service('membership')->pendingSubscription((int) $c['id']);
        $inv = db_connect()->table('invoices')->where('id', $sub['invoice_id'])->get()->getRowArray();
        service('billing')->recordManualPayment($inv, (float) $inv['total'], 'TEST-UTR-' . $inv['id'], date('Y-m-d'), (int) $this->staff('finance_manager')->id);
        $this->flush();

        return ['user' => $r['user'], 'company' => model(CompanyModel::class)->find($c['id'])];
    }

    /**
     * Creates and publishes a product with stock.
     */
    protected function product(array $company, array $over = [], int $qty = 100, string $receivedOn = '-2 months'): array
    {
        $cat = (int) db_connect()->table('categories')->where('slug', 'ball-bearings')->get()->getRow()->id;
        $p   = service('products')->save($company, $over + [
            'sku' => 'SKU-' . bin2hex(random_bytes(4)), 'name' => 'Test bearing', 'part_number' => '6204-2RS', 'oem_part_number' => '',
            'category_id' => $cat, 'brand_id' => null, 'item_condition' => 'new', 'sale_mode' => 'piece', 'unit_price' => '10', 'lot_price' => '',
            'lot_quantity' => '', 'moq' => 1, 'currency' => 'INR', 'price_visibility' => 'show', 'visibility' => 'public', 'country_mode' => 'all',
            'warehouse_country' => $company['country_code'], 'shipping_options' => ['platform_managed', 'confidential'],
        ], null, true);
        service('inventory')->receive((int) $p['id'], $qty, date('Y-m-d', strtotime($receivedOn)));
        model(ProductModel::class)->update($p['id'], ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')]);

        return model(ProductModel::class)->find($p['id']);
    }

    protected function postAs(?User $user, string $uri, array $data = []): TestResponse
    {
        $token = bin2hex(random_bytes(16));
        Services::resetSingle('security');
        $this->switchUser($user);

        // Browsers only ever submit strings; mirror that so filters behave as in production.
        array_walk_recursive($data, static function (&$v): void {
            $v = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        });

        return $this->withSession(['csrf_test_name' => $token])->post($uri, $data + ['csrf_test_name' => $token]);
    }

    protected function getAs(?User $user, string $uri): TestResponse
    {
        $this->switchUser($user);

        return $this->withSession([])->get($uri);
    }

    protected function switchUser(?User $user): void
    {
        Services::resetSingle('auth');
        $_SESSION = [];
        if ($user) {
            $this->actingAs($user);
        }
        $this->flush();
    }

    /**
     * GETs a page, asserts the status and returns the body.
     */
    protected function body(?User $user, string $uri, int $status = 200): string
    {
        $r = $this->getAs($user, $uri);
        $r->assertStatus($status);

        return (string) $r->getBody();
    }

    /**
     * Asserts a request is refused as "not found" (rendered 404 or PageNotFoundException).
     */
    protected function assertNotFoundFor(?User $user, string $uri, string $method = 'get', array $data = []): void
    {
        try {
            $r = $method === 'get' ? $this->getAs($user, $uri) : $this->postAs($user, $uri, $data);
            $r->assertStatus(404);
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    protected function flashError(): ?string
    {
        return $_SESSION['error'] ?? null;
    }

    protected function securityEvents(string $eventPrefix = 'access.'): int
    {
        return db_connect()->table('audit_logs')->where('severity', 'security')->like('event', $eventPrefix, 'after')->countAllResults();
    }

    protected function tempFile(string $name, string $contents): \CodeIgniter\Files\File
    {
        $dir = WRITEPATH . 'uploads/test-fixtures';
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($dir . '/' . $name, $contents);

        return new \CodeIgniter\Files\File($dir . '/' . $name, true);
    }

    protected function pdf(string $name = 'doc.pdf'): \CodeIgniter\Files\File
    {
        return $this->tempFile($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }
}

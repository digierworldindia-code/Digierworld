<?php

namespace Tests\Support;

use App\Libraries\Crypto;
use App\Libraries\PasswordPolicy;
use App\Libraries\Rbac;
use App\Libraries\Settings;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

/**
 * Base for tests that touch the database.
 *
 * Every test runs inside a transaction that is rolled back afterwards, so the
 * suite leaves the test schema exactly as it found it and tests cannot affect
 * one another. The schema itself comes from the real migrations:
 *
 *   php spark polyfix:migrate --owner-user polyfix_test --group tests
 *   php spark polyfix:seed ReferenceData --group tests
 */
abstract class PolyfixTestCase extends CIUnitTestCase
{
    /** @var BaseConnection */
    protected $db;

    /** Reference ids created for the current test. */
    protected array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = Database::connect('tests');
        $this->db->transBegin();

        Settings::forget();
        Services::resetSingle('requestContext');
    }

    protected function tearDown(): void
    {
        // Nothing a test wrote survives it.
        $this->db->transRollback();
        Settings::forget();
        parent::tearDown();
    }

    /**
     * Inserts a fixture row and fails the test loudly if the database refused
     * it. Inside a transaction a rejected INSERT is otherwise silent, and a
     * missing fixture row shows up later as a puzzling assertion failure.
     */
    protected function insert(string $table, array $row): void
    {
        $this->db->table($table)->insert($row);
        $error = $this->db->error();
        if (($error['code'] ?? 0) !== 0) {
            $this->fail("fixture insert into {$table} was refused: " . $error['message']);
        }
    }

    // =====================================================================
    // Fixtures — the smallest tree that lets a real workflow run
    // =====================================================================

    protected function makeDealer(array $overrides = []): string
    {
        $id = uuid4();
        $this->insert('dealers', $overrides + [
            'id' => $id, 'code' => 'DLR' . random_int(1000, 9999) . substr((string) microtime(true), -3),
            'business_name' => 'Test Dealer ' . substr($id, 0, 6), 'owner_name' => 'Test Owner',
            'phone' => '9876500000', 'address_line1' => '1 Test Road', 'city' => 'Jaipur', 'state' => 'Rajasthan',
            'pincode' => '302001', 'status' => 'ACTIVE', 'public_listed' => 1, 'is_showroom' => 0, 'onboarded_at' => utc_now(),
        ]);

        return $id;
    }

    /** @param list<string> $roles */
    protected function makeUser(array $roles = ['ADMIN'], ?string $dealerId = null, array $overrides = []): string
    {
        $id = uuid4();
        $this->insert('users', $overrides + [
            'id' => $id, 'email' => strtolower(substr($id, 0, 8)) . '@test.polyfix.local',
            'full_name' => 'Test Person', 'password_hash' => PasswordPolicy::hash(self::password()),
            'status' => 'ACTIVE', 'mfa_enabled' => 0, 'must_change_password' => 0, 'password_changed_at' => utc_now(),
        ]);
        foreach ($roles as $role) {
            $roleId = $this->db->table('roles')->select('id')->where('key', $role)->get()->getRow()->id;
            $this->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
        }
        if ($dealerId !== null) {
            $this->insert('dealer_users', ['user_id' => $id, 'dealer_id' => $dealerId, 'is_primary' => 1]);
        }

        return $id;
    }

    public static function password(): string
    {
        return 'Test-passphrase-91!';
    }

    /** @return array{product:string, variant:string} */
    protected function makeProduct(int $warrantyYears = 10, array $overrides = []): array
    {
        $productId = uuid4();
        $slug      = 'test-product-' . substr($productId, 0, 8);
        $this->insert('products', $overrides + [
            // Both the slug and the SKU prefix are unique in the schema.
            'id' => $productId, 'slug' => $slug, 'name' => 'Test Mattress',
            'sku_prefix' => 'T' . strtoupper(substr(str_replace('-', '', $productId), 0, 5)),
            'category' => 'Hybrid', 'short_description' => 'A test mattress.', 'description' => 'A mattress used by the test suite.',
            'comfort_level' => 'Medium-firm', 'firmness_score' => 6, 'warranty_years' => $warrantyYears,
            'materials' => '[]', 'features' => '[]', 'specifications' => '{}', 'status' => 'PUBLISHED', 'is_featured' => 0, 'sort_order' => 1,
        ]);
        $variantId = uuid4();
        $this->insert('product_variants', [
            'id' => $variantId, 'product_id' => $productId, 'sku' => 'TST-' . substr($variantId, 0, 4),
            'size_label' => 'Queen 78 x 60', 'width_in' => 60, 'length_in' => 78, 'height_in' => 8,
            'mrp' => 21500.00, 'status' => 'PUBLISHED', 'sort_order' => 1,
        ]);

        return ['product' => $productId, 'variant' => $variantId];
    }

    protected function warehouseId(): string
    {
        $row = $this->db->table('warehouses')->select('id')->where('is_active', 1)->get()->getRow();

        return $row->id;
    }

    protected function makeBatch(): string
    {
        $id = uuid4();
        $this->insert('manufacturing_batches', [
            'id' => $id, 'batch_code' => 'BAT' . random_int(100000, 999999), 'warehouse_id' => $this->warehouseId(),
            'manufactured_on' => utc_today(), 'planned_quantity' => 100, 'produced_quantity' => 0,
        ]);

        return $id;
    }

    /**
     * A unit in a given lifecycle state, with its serial.
     *
     * @return array{id:string, serial:string, qr_token:string}
     */
    protected function makeMattress(string $variantId, string $status = 'MANUFACTURED', ?string $dealerId = null, array $overrides = []): array
    {
        // A unit in a sold state must carry a sale date, and the lifecycle
        // dates have to run in order: the database checks both.
        if (in_array($status, ['SOLD', 'CLAIM_OPEN', 'REPLACED'], true) && ! isset($overrides['sold_at'])) {
            $overrides['sold_at'] = gmdate('Y-m-d H:i:s', strtotime('-60 days')) . '.000000';
        }

        if (isset($overrides['sold_at']) && ! isset($overrides['received_at'])) {
            $overrides['received_at'] = gmdate('Y-m-d H:i:s', strtotime(substr($overrides['sold_at'], 0, 19) . ' UTC') - 30 * 86400) . '.000000';
        }
        if (isset($overrides['received_at']) && ! isset($overrides['manufactured_at'])) {
            $overrides['manufactured_at'] = gmdate('Y-m-d H:i:s', strtotime(substr($overrides['received_at'], 0, 19) . ' UTC') - 30 * 86400) . '.000000';
        }

        $id     = uuid4();
        // The database itself enforces the serial format, so a fixture has to
        // use the real prefix; the high range keeps it clear of issued numbers.
        $serial = brand('serialPrefix') . str_pad((string) random_int(50_000_000, 99_999_999), 8, '0', STR_PAD_LEFT);
        $token  = \App\Libraries\Identifiers::qrToken();
        $this->insert('mattresses', $overrides + [
            'id' => $id, 'serial_number' => $serial, 'qr_token' => $token, 'product_variant_id' => $variantId,
            'batch_id' => $this->ids['batch'] ??= $this->makeBatch(), 'current_status' => $status,
            'current_dealer_id' => $dealerId, 'current_warehouse_id' => $dealerId === null ? $this->warehouseId() : null,
            'manufactured_at' => utc_now(), 'is_replacement' => 0,
            // Received a while back, so a fixture sale can be dated in the past.
            'received_at' => in_array($status, ['DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN'], true)
                ? gmdate('Y-m-d H:i:s', strtotime('-180 days')) . '.000000' : null,
        ]);

        return ['id' => $id, 'serial' => $serial, 'qr_token' => $token];
    }

    /**
     * Signs a user into the request context, as the auth filter would.
     *
     * Two-factor counts as satisfied by default so tests exercise the ordinary
     * case; pass mfa_satisfied => false to check a gated permission is refused.
     */
    protected function actingAs(string $userId, array $overrides = []): array
    {
        $user = $this->db->table('users u')->select('u.id, u.email, u.full_name, u.mfa_enabled')->where('u.id', $userId)->get()->getRowArray();
        $roles = array_column($this->db->table('user_roles ur')->select('r.key')->join('roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)->get()->getResultArray(), 'key');
        $dealer = $this->db->table('dealer_users du')->select('d.id, d.business_name')->join('dealers d', 'd.id = du.dealer_id')
            ->where('du.user_id', $userId)->get()->getRowArray();

        $signedIn = [
            'id' => $user['id'], 'email' => $user['email'], 'full_name' => $user['full_name'],
            'roles' => $roles, 'dealer_id' => $dealer['id'] ?? null, 'dealer_name' => $dealer['business_name'] ?? null,
            'mfa_enabled' => true, 'mfa_satisfied' => true,
            'must_change_password' => false, 'must_enrol_mfa' => false, 'session_id' => uuid4(),
        ];
        $signedIn = $overrides + $signedIn;
        service('requestContext')->signIn($signedIn);

        return $signedIn;
    }

    protected function crypto(): Crypto
    {
        return Crypto::instance();
    }

    /** @return list<string> */
    protected function permissionsOf(string $role): array
    {
        return Rbac::ROLE_PERMISSIONS[$role];
    }
}

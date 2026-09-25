<?php

namespace Tests\Unit;

use App\Libraries\Audit;
use App\Libraries\AuditChain;
use App\Libraries\Crypto;
use App\Services\SalesService;
use Tests\Support\PolyfixTestCase;

/**
 * The audit trail and the hash chain that makes tampering visible.
 *
 * @internal
 */
final class AuditTrailTest extends PolyfixTestCase
{
    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->makeUser(['ADMIN']));
        $this->audit = new Audit($this->db, service('requestContext'));
    }

    public function testEachEntryLinksToTheOneBeforeIt(): void
    {
        $before = (int) $this->db->table('audit_logs')->countAllResults();

        $this->audit->record('TEST_ONE', 'thing', 'a', null, ['n' => 1]);
        $this->audit->record('TEST_TWO', 'thing', 'b', ['n' => 1], ['n' => 2], 'because');

        $rows = $this->db->table('audit_logs')->orderBy('id', 'DESC')->limit(2)->get()->getResultArray();
        $this->assertSame($before + 2, (int) $this->db->table('audit_logs')->countAllResults());
        $this->assertSame($rows[1]['row_hash'], $rows[0]['prev_hash'], 'the newer entry carries the older one\'s hash');

        $chain = (new AuditChain($this->db))->verify();
        $this->assertSame([], $chain['problems']);
    }

    public function testWhoDidItAndWhyAreRecorded(): void
    {
        $context = service('requestContext');
        $this->audit->record('SOMETHING_HAPPENED', 'thing', 'x', ['was' => 'this'], ['now' => 'that'], 'a written reason');

        $row = $this->db->table('audit_logs')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('SOMETHING_HAPPENED', $row['action']);
        $this->assertSame($context->userId(), $row['user_id']);
        $this->assertSame($context->email(), $row['user_email']);
        $this->assertSame('ADMIN', $row['role_key']);
        $this->assertSame('a written reason', $row['reason']);
        $this->assertSame(['was' => 'this'], json_decode($row['previous_value'], true));
        $this->assertNotNull($row['request_id'], 'the request id ties the entry to the logs');
    }

    public function testEditingAnEntryBreaksTheChain(): void
    {
        $this->audit->record('FIRST', 'thing', '1');
        $this->audit->record('SECOND', 'thing', '2');
        $this->audit->record('THIRD', 'thing', '3');

        $this->assertSame([], (new AuditChain($this->db))->verify()['problems']);

        // Someone with database access rewrites history.
        $middle = $this->db->table('audit_logs')->select('id')->where('action', 'SECOND')->get()->getRow()->id;
        $this->db->query('UPDATE audit_logs SET action = ? WHERE id = ?', ['SECOND_CHANGED', $middle]);

        $problems = (new AuditChain($this->db))->verify()['problems'];
        $this->assertNotEmpty($problems, 'a changed row must be detectable');
        $this->assertStringContainsString((string) $middle, implode(' ', $problems));
    }

    public function testDeletingAnEntryBreaksTheChain(): void
    {
        $this->audit->record('ONE', 'thing', '1');
        $this->audit->record('TWO', 'thing', '2');
        $this->audit->record('THREE', 'thing', '3');

        $middle = $this->db->table('audit_logs')->select('id')->where('action', 'TWO')->get()->getRow()->id;
        $this->db->query('DELETE FROM audit_logs WHERE id = ?', [$middle]);

        $this->assertNotEmpty((new AuditChain($this->db))->verify()['problems'], 'a removed row leaves a gap that shows');
    }

    public function testSensitiveValuesAreNeverWrittenToTheTrail(): void
    {
        $this->audit->record('USER_UPDATED', 'user', 'u1', null, [
            'email' => 'someone@example.com', 'password' => 'the-actual-password',
            'password_hash' => '$argon2id$v=19$...', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'token' => 'abcdef',
        ]);

        $row = $this->db->table('audit_logs')->orderBy('id', 'DESC')->get()->getRowArray();
        $new = json_decode($row['new_value'], true);

        $this->assertSame('someone@example.com', $new['email']);
        foreach (['password', 'password_hash', 'mfa_secret', 'token'] as $secret) {
            $this->assertArrayHasKey($secret, $new);
            $this->assertSame('[redacted]', $new[$secret], "{$secret} must be redacted in the audit trail");
        }
    }

    public function testBusinessActionsWriteTheirOwnEntries(): void
    {
        $dealerId = $this->makeDealer();
        $product  = $this->makeProduct();
        $unit     = $this->makeMattress($product['variant'], 'DEALER_RECEIVED', $dealerId);
        $this->actingAs($this->makeUser(['DEALER'], $dealerId));

        (new SalesService($this->db, service('requestContext'), Crypto::instance()))->recordSale([
            'serial' => $unit['serial'], 'invoice_number' => 'INV-AUDIT', 'sold_on' => utc_today(),
            'sale_price' => 21500, 'payment_mode' => 'CASH',
            'customer' => ['full_name' => 'Ritu Sharma', 'phone' => '9876500011', 'city' => 'Jaipur'],
        ]);

        $entry = $this->db->table('audit_logs')->where('action', 'SALE_RECORDED')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($entry, 'a sale is recorded in the trail');
        $this->assertSame($dealerId, $entry['dealer_id']);
        $this->assertSame($unit['serial'], json_decode($entry['new_value'], true)['serialNumber']);
        $this->assertSame([], (new AuditChain($this->db))->verify()['problems']);
    }

    public function testRecordVersionsKeepThePreviousShapeOfARow(): void
    {
        $dealerId = $this->makeDealer();
        $before   = $this->db->table('dealers')->where('id', $dealerId)->get()->getRowArray();

        $this->audit->version('dealer', $dealerId, $before, 'before an edit');
        $this->audit->version('dealer', $dealerId, ['business_name' => 'Renamed'] + $before, 'after an edit');

        $versions = $this->db->table('record_versions')->where(['entity' => 'dealer', 'entity_id' => $dealerId])->orderBy('version')->get()->getResultArray();
        $this->assertCount(2, $versions);
        $this->assertSame([1, 2], array_map('intval', array_column($versions, 'version')));
        $this->assertSame($before['business_name'], json_decode($versions[0]['data'], true)['business_name']);
    }
}

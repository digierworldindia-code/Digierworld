<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/** Audit entries made inside a transaction are chained when it commits and vanish with a rollback. */
final class AuditChainTest extends DgrTestCase
{
    public function testEntriesFollowTheirTransactionAndTheChainStaysValid(): void
    {
        $db    = db_connect();
        $count = static fn (string $action) => $db->table('audit_logs')->where('action', $action)->countAllResults();

        $db->transBegin();
        service('audit')->log('test.committed', 'test', 1, null, ['n' => 1], 'kept');
        $this->assertSame(0, $count('test.committed'), 'written when the transaction commits, not before');
        $db->transBegin(); // nested
        service('audit')->log('test.committed', 'test', 2, null, ['n' => 2], 'kept too');
        $db->transCommit();
        $this->assertSame(0, $count('test.committed'));
        $db->transCommit();
        $this->assertSame(2, $count('test.committed'));

        $db->transBegin();
        service('audit')->log('test.rolled_back', 'test', 3, null, null, 'discarded');
        $db->transRollback();
        $this->assertSame(0, $count('test.rolled_back'), 'a rolled-back action leaves no audit entry');

        service('audit')->log('test.outside', 'test', 4, null, null, 'immediate');
        $this->assertSame(1, $count('test.outside'), 'outside a transaction the entry is written at once');

        // JSON data the database may store in its own format (key order, spacing, unicode, decimals)
        service('audit')->log('test.json', 'bank_cash_account', 5, ['z' => 1, 'a' => ['y' => '₹ 1,000', 'b' => 1.0], 'url' => 'https://x.test/a'], ['bank_name' => 'ICICI'], 'json', ['k' => [3, 2, 1]]);
        [$ok, $broken] = service('audit')->verify();
        $this->assertTrue($ok, 'hash chain intact (first broken id: ' . var_export($broken, true) . ')');

        // changing a stored entry outside the application is detected
        $id = (int) $db->table('audit_logs')->where('action', 'test.json')->get()->getRow()->id;
        $db->query("UPDATE audit_logs SET after_data = JSON_OBJECT('bank_name', 'HACKED') WHERE id = ?", [$id]);
        [$ok, $broken] = service('audit')->verify();
        $this->assertFalse($ok);
        $this->assertSame($id, $broken);
        $db->table('audit_logs')->where('id >=', $id)->delete(); // leave a valid chain for later tests
    }
}

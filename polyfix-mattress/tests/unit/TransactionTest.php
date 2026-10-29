<?php

namespace Tests\Unit;

use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\PolyfixTestCase;

/**
 * What happens when a write fails inside a transaction.
 *
 * This is not a theoretical question. CodeIgniter does not throw on a failed
 * query while a transaction is open — the framework's own comment reads "In
 * transactions, do not throw exception by default". It sets the transaction's
 * status to failed, `transCommit()` then rolls back instead of committing, and
 * the call returns normally. Every write path in this application runs inside
 * Tx::run, so without the setting these tests pin down, a duplicate serial, a
 * failed CHECK or a bad foreign key would make an operation report success and
 * hand back an id for a row that was never written. The dealer, the warehouse
 * operator or the agent would be told the work was done.
 *
 * @internal
 */
final class TransactionTest extends PolyfixTestCase
{
    public function testAFailedWriteInsideATransactionRaisesRatherThanReturningQuietly(): void
    {
        $this->expectException(DatabaseException::class);

        Tx::run(function (BaseConnection $db): string {
            // No such column: the query cannot succeed on any schema.
            $db->query('INSERT INTO `dealers` (`no_such_column`) VALUES (1)');

            return 'reported as done';
        }, $this->db);
    }

    /** And nothing it managed to write first is left behind. */
    public function testTheWholeOperationIsRolledBackWhenOneStepFails(): void
    {
        $before = $this->db->table('dealers')->countAllResults();

        try {
            Tx::run(function (BaseConnection $db): void {
                $this->makeDealer();
                $db->query('INSERT INTO `dealers` (`no_such_column`) VALUES (1)');
            }, $this->db);
            $this->fail('the failed step was not reported');
        } catch (DatabaseException) {
            // expected
        }

        $this->assertSame($before, $this->db->table('dealers')->countAllResults());
    }

    /**
     * A constraint the schema enforces, rather than a syntax error: two
     * mattresses cannot share a serial number, and the attempt must be
     * reported, not swallowed.
     */
    public function testAConstraintViolationIsReported(): void
    {
        $variant = $this->makeProduct()['variant'];
        $unit    = $this->makeMattress($variant);

        $this->expectException(DatabaseException::class);

        Tx::run(function (BaseConnection $db) use ($unit, $variant): void {
            $db->table('mattresses')->insert([
                'id' => uuid4(), 'serial_number' => $unit['serial'], 'qr_token' => bin2hex(random_bytes(16)),
                'product_variant_id' => $variant, 'current_status' => 'MANUFACTURED',
                'manufactured_at' => utc_now(),
            ]);
        }, $this->db);
    }

    /**
     * An inner unit that fails for a reason of the application's own — a rule,
     * a conflict, something not found — is undone on its own, and the outer
     * work survives. This is the case CodeIgniter's depth counting gets wrong:
     * its nested rollback just decrements a counter and leaves the writes in
     * place, so a caller that catches the failure would commit them.
     */
    public function testAnInnerStepThatRaisesOnARuleRollsBackToItsOwnSavepoint(): void
    {
        $before = $this->db->table('dealers')->countAllResults();

        Tx::run(function (BaseConnection $db) use ($before): void {
            $this->makeDealer();

            try {
                Tx::run(function (BaseConnection $db): void {
                    $this->makeDealer();

                    throw new \RuntimeException('the rule this operation checks was not met');
                }, $db);
                $this->fail('the inner step was not reported');
            } catch (\RuntimeException) {
                // The caller decides to carry on with the outer work.
            }

            $this->assertSame($before + 1, $db->table('dealers')->countAllResults(), 'the inner work was not undone');
        }, $this->db);

        $this->assertSame($before + 1, $this->db->table('dealers')->countAllResults(), 'the outer work was not kept');
    }

    /**
     * A failed *query* inside a nested unit is different, and the difference
     * is the database's, not this application's: to raise at all the framework
     * must first roll the whole transaction back, and that discards the
     * savepoint. So the outer work goes too. What matters is that it is
     * reported and that nothing half-finished is committed — never that the
     * caller is told it worked.
     */
    public function testAFailedQueryInsideANestedUnitUndoesEverythingAndSaysSo(): void
    {
        $before = $this->db->table('dealers')->countAllResults();

        try {
            Tx::run(function (BaseConnection $db): void {
                $this->makeDealer();

                Tx::run(function (BaseConnection $db): void {
                    $db->query('INSERT INTO `dealers` (`no_such_column`) VALUES (1)');
                }, $db);
            }, $this->db);
            $this->fail('the failed query was not reported');
        } catch (DatabaseException) {
            // expected
        }

        $this->assertSame($before, $this->db->table('dealers')->countAllResults(), 'a write was left behind');
    }

    /** The setting that makes all of the above true, asserted where it is set. */
    public function testTheConnectionIsConfiguredToRaiseInsideTransactions(): void
    {
        $property = new \ReflectionProperty($this->db, 'transException');
        $property->setAccessible(true);

        $this->assertTrue(
            $property->getValue($this->db),
            'without transException the framework swallows a failed write inside a transaction',
        );
    }
}

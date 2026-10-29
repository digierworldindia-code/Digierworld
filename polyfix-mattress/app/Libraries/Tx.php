<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Runs work in a transaction: commit on success, roll back on any exception.
 *
 *   $saleId = Tx::run(fn (BaseConnection $db) => $service->recordSale(...));
 *
 * A nested call joins the transaction already open, so a multi-step operation
 * built out of smaller ones — opening a batch is creating it and then issuing
 * every serial — is all-or-nothing.
 *
 * The nesting is done with savepoints rather than by counting depth.
 * CodeIgniter's own nested transactions only keep a counter: a nested
 * rollback decrements it and returns true, leaving every write in place. That
 * is correct as far as the outermost scope is concerned, because the
 * exception travels up and the real rollback happens there — but only while
 * nobody catches it on the way. A caller that catches a failed inner step and
 * carries on would commit its half-finished work. A savepoint makes the inner
 * unit honest on its own terms, whatever the caller then does.
 *
 * One case is out of reach, and is handled rather than papered over. The
 * connection is configured to raise on a failed query inside a transaction
 * (see App\Database\MySQLi\Connection — without that the framework reports
 * the write as successful), and on its way out the framework rolls the whole
 * transaction back before it throws, which discards every savepoint with it.
 * So a failing *query* inside a nested unit undoes the outer work too; there
 * is no honest way to keep half of it, because the database no longer holds
 * the mark. The savepoint still does its job when the inner unit fails for a
 * reason of the application's own — a rule, a conflict, a not-found — which
 * is the common case. Either way nothing half-finished is ever committed, and
 * nothing fails quietly.
 */
final class Tx
{
    /**
     * @template T
     *
     * @param callable(BaseConnection): T $work
     *
     * @return T
     */
    public static function run(callable $work, ?BaseConnection $db = null): mixed
    {
        $db ??= db_connect();

        if ($db->transDepth > 0) {
            return self::nested($work, $db);
        }

        $db->transBegin();

        try {
            $result = $work($db);
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        // transCommit() rolls back instead of committing when any query in
        // the transaction failed, and says so only through transStatus().
        // Returning the result after that would be the same lie this class
        // exists to prevent.
        if ($db->transStatus() === false) {
            throw new DatabaseException('The transaction was rolled back because a write in it failed.');
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param callable(BaseConnection): T $work
     *
     * @return T
     */
    private static function nested(callable $work, BaseConnection $db): mixed
    {
        // Named uniquely: savepoints nest, and two levels sharing a name
        // would have the inner release discard the outer one's mark.
        $name = 'tx_' . bin2hex(random_bytes(6));
        $db->query("SAVEPOINT {$name}");

        try {
            $result = $work($db);
            $db->query("RELEASE SAVEPOINT {$name}");

            return $result;
        } catch (Throwable $e) {
            // A failed query has already had the framework roll the whole
            // transaction back, taking this savepoint with it; rolling back to
            // a mark that no longer exists would replace the real error with a
            // confusing one. Nothing is left to undo in that case.
            if ($db->transDepth > 0) {
                $db->query("ROLLBACK TO SAVEPOINT {$name}");
            }

            throw $e;
        }
    }
}

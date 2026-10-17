<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
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

            return $result;
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }
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
            $db->query("ROLLBACK TO SAVEPOINT {$name}");

            throw $e;
        }
    }
}

<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Runs work in a transaction: commit on success, roll back on any exception.
 *
 *   $saleId = Tx::run(fn (BaseConnection $db) => $service->recordSale(...));
 *
 * Nested calls join the outer transaction (CodeIgniter counts depth), and an
 * exception anywhere rolls the whole outermost transaction back — so a
 * multi-step operation such as issuing a replacement is all-or-nothing.
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
}

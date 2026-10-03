<?php

namespace App\Services\Support;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Runs a unit of work in one database transaction.
 *
 * - Any exception rolls the whole transaction back and is re-thrown.
 * - Deadlocks (1213) and lock wait timeouts (1205) are retried (default 3 attempts).
 * - Re-entrant: work started inside an open transaction joins it.
 * - Never call external APIs (Google) inside the callback.
 */
class TransactionRunner
{
    private const RETRYABLE = [1205, 1213];

    public function __construct(private readonly BaseConnection $db)
    {
        // Make query failures inside transactions throw (and roll back) instead of returning false.
        $this->db->transException(true);
    }

    /**
     * @template T
     *
     * @param callable(BaseConnection): T $work
     *
     * @return T
     */
    public function run(callable $work, int $maxAttempts = 3): mixed
    {
        if ($this->db->transDepth > 0) {
            return $work($this->db);
        }

        for ($attempt = 1; ; $attempt++) {
            $this->db->transBegin();

            try {
                $result = $work($this->db);

                if ($this->db->transStatus() === false) {
                    throw new DatabaseException('The database transaction failed.');
                }

                $this->db->transCommit();

                return $result;
            } catch (Throwable $e) {
                // No-op when CodeIgniter already rolled back after a failed query.
                $this->db->transRollback();

                if ($attempt < $maxAttempts && $this->isRetryable($e)) {
                    usleep(random_int(20_000, 120_000) * $attempt);

                    continue;
                }

                throw $e;
            }
        }
    }

    private function isRetryable(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (in_array((int) $cause->getCode(), self::RETRYABLE, true)) {
                return true;
            }
        }

        return false;
    }
}

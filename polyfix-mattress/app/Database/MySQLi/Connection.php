<?php

namespace App\Database\MySQLi;

use CodeIgniter\Database\MySQLi\Connection as BaseConnection;

/**
 * MySQLi connection that pins every session to UTC and utf8mb4.
 *
 * All timestamps are stored in UTC. That is not a preference: the audit log's
 * hash chain covers each row's timestamp rendered in UTC, so a connection in any
 * other zone would write rows the chain verifier then reports as tampered.
 *
 * CodeIgniter's own driver only exposes `strictOn` through MYSQLI_INIT_COMMAND,
 * so the zone is set immediately after connecting instead. This works on any
 * host, whatever the server's default-time-zone.
 *
 * It also makes a failed write inside a transaction raise. CodeIgniter does not,
 * by default — the framework's own comment on the branch reads "In transactions,
 * do not throw exception by default". It records the transaction as failed, so
 * `transCommit()` quietly rolls back instead of committing, and the call that
 * made the write returns as if it had worked. Every write path here runs inside
 * App\Libraries\Tx, which is to say inside a transaction, so without this a
 * duplicate serial number, a failed CHECK or a missing foreign key would hand an
 * operator an id for a row that was never written and tell them the job was
 * done. A dispatch would be confirmed with nothing on it. Errors are worth
 * seeing; silence is not.
 *
 * Selected in Config\Database with DBDriver = 'App\Database\MySQLi'. The DBDriver
 * property is reset to 'MySQLi' so that Forge, Utils and every platform check in
 * the framework still treat this as the stock MySQLi driver.
 */
class Connection extends BaseConnection
{
    public function __construct(array $params)
    {
        parent::__construct($params);
        $this->DBDriver = 'MySQLi';
        $this->transException(true);
    }

    /**
     * @return false|\mysqli
     */
    public function connect(bool $persistent = false)
    {
        $connection = parent::connect($persistent);

        if ($connection !== false) {
            $connection->query("SET time_zone = '+00:00'");
            $connection->set_charset('utf8mb4');
        }

        return $connection;
    }
}

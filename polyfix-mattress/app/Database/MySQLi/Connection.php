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

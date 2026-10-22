<?php

namespace App\Database\MySQLi;

use CodeIgniter\Database\MySQLi\Connection as BaseConnection;

/**
 * MySQLi connection that
 *  - aligns the session time zone with the application time zone, so
 *    DEFAULT CURRENT_TIMESTAMP columns and PHP date() values agree;
 *  - throws on any failed query inside a transaction (CodeIgniter's default
 *    is to swallow it and let transCommit() commit the remaining work).
 */
class Connection extends BaseConnection
{
    protected bool $transException = true;

    public function connect(bool $persistent = false)
    {
        $conn = parent::connect($persistent);
        if ($conn instanceof \mysqli) {
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone(config('App')->appTimezone)))->format('P');
            $conn->query("SET time_zone = '" . $conn->real_escape_string($offset) . "'");
            if (stripos($conn->server_info, 'mariadb') !== false) {
                // MariaDB 11.6+ defaults to innodb_snapshot_isolation=ON: a locking read (SELECT … FOR UPDATE)
                // of a row changed after the transaction's first read fails with error 1020 instead of
                // waiting. Invoice numbering, receipts, payroll and the audit hash chain serialise with
                // FOR UPDATE, so concurrent users would fail. OFF = the MySQL 8 / MariaDB ≤ 11.5 behaviour.
                try {
                    $conn->query('SET SESSION innodb_snapshot_isolation = OFF');
                } catch (\mysqli_sql_exception) {
                    // MariaDB versions without the variable already behave this way
                }
            }
        }

        return $conn;
    }
}

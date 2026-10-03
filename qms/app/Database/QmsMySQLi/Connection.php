<?php

namespace App\Database\QmsMySQLi;

use CodeIgniter\Database\MySQLi\Connection as BaseMySQLiConnection;

/**
 * Stock MySQLi connection that pins the session time zone to UTC.
 *
 * Every DATETIME in the QMS is stored in UTC; the plant time zone is only used
 * for display. Pinning the session guarantees that DEFAULT CURRENT_TIMESTAMP,
 * ON UPDATE CURRENT_TIMESTAMP and NOW() agree with the values written by PHP,
 * whatever the server's default_time_zone is.
 */
class Connection extends BaseMySQLiConnection
{
    /**
     * @return false|\mysqli
     */
    public function connect(bool $persistent = false)
    {
        $mysqli = parent::connect($persistent);

        if ($mysqli instanceof \mysqli) {
            $mysqli->query("SET time_zone = '+00:00'");
        }

        return $mysqli;
    }

    /**
     * Report the underlying platform so framework code that branches on it
     * (session handler, Model) treats this exactly like the stock MySQLi driver.
     */
    public function getPlatform(): string
    {
        return 'MySQLi';
    }
}

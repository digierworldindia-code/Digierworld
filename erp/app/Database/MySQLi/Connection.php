<?php

namespace App\Database\MySQLi;

use CodeIgniter\Database\MySQLi\Connection as BaseConnection;

/**
 * MySQLi connection that aligns the session time zone with the application
 * time zone, so DEFAULT CURRENT_TIMESTAMP columns and PHP date() values agree.
 */
class Connection extends BaseConnection
{
    public function connect(bool $persistent = false)
    {
        $conn = parent::connect($persistent);
        if ($conn instanceof \mysqli) {
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone(config('App')->appTimezone)))->format('P');
            $conn->query("SET time_zone = '" . $conn->real_escape_string($offset) . "'");
        }

        return $conn;
    }
}

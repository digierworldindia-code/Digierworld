<?php

declare(strict_types=1);

namespace App\Core;

use PDOException;
use RuntimeException;

/**
 * A failed query or connection. The code is the MySQL error number
 * (1062 duplicate key, 1205 lock wait timeout, 1213 deadlock, 1451/1452 foreign
 * key, 1644 trigger SIGNAL, 3819 CHECK constraint ...), the message is the
 * server's text (for the integrity triggers it starts with "QMS-LOCK:").
 */
class DatabaseException extends RuntimeException
{
    public string $sqlState = '';

    public string $sql = '';

    public static function fromPdo(PDOException $e, string $sql = ''): self
    {
        $info    = $e->errorInfo ?? null;
        $code    = is_array($info) && isset($info[1]) ? (int) $info[1] : 0;
        $message = is_array($info) && isset($info[2]) && $info[2] !== '' ? (string) $info[2] : $e->getMessage();

        if ($code === 0 && preg_match('/SQLSTATE\[\w+\] \[(\d+)\]/', $e->getMessage(), $m)) {
            $code = (int) $m[1]; // connection errors: "SQLSTATE[HY000] [2002] ..."
        }

        $exception           = new self($message, $code, $e);
        $exception->sqlState = is_array($info) && isset($info[0]) ? (string) $info[0] : '';
        $exception->sql      = $sql;

        return $exception;
    }
}

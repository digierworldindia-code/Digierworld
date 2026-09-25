<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database configuration.
 *
 * Every credential comes from the environment (.env locally, the host's secret
 * store in production). Nothing here is a real password.
 *
 * Two groups:
 *   default  the web application. SELECT/INSERT/UPDATE/DELETE on business
 *            tables and INSERT-only on history tables. It cannot run DDL.
 *   tests    a separate database for the automated test suite.
 *
 * Schema changes run as a separate owner account through
 * `php spark polyfix:migrate`, which asks for that password at run time. It is
 * never stored here or in .env.
 */
class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    public string $defaultGroup = 'default';

    public array $default = [
        'DSN'          => '',
        'hostname'     => '127.0.0.1',
        'username'     => '',
        'password'     => '',
        'database'     => 'polyfix_mattress',
        // UTC-pinned MySQLi driver: see app/Database/MySQLi/Connection.php
        'DBDriver'     => 'App\Database\MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        // Throw on a database error, in every environment. Turning this off
        // makes a failed query return false silently, so a transaction would
        // commit half its work. Visitors never see the error: the production
        // environment renders a generic page and logs the detail.
        'DBDebug'      => true,
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_0900_ai_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => true,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];


    public array $tests = [
        'DSN'          => '',
        'hostname'     => '127.0.0.1',
        'username'     => '',
        'password'     => '',
        'database'     => 'polyfix_mattress_test',
        'DBDriver'     => 'App\Database\MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => true,
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_0900_ai_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => true,
        'failover'     => [],
        'port'         => 3306,
        'foreignKeys'  => true,
        'busyTimeout'  => 1000,
        'numberNative' => false,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}

<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database configuration.
 *
 * No credentials live in this file. Every value is overridden from the
 * environment (.env outside the web root, or PHP-FPM pool env[]), e.g.
 *
 *   database.default.hostname = /var/run/mysqld/mysqld.sock
 *   database.default.database = qms
 *   database.default.username = qms_app
 *   database.default.password = <generated>
 *
 * The custom driver App\Database\QmsMySQLi is the stock MySQLi driver plus
 * "SET time_zone = '+00:00'" on every new connection (all DATETIMEs are UTC).
 */
class Database extends Config
{
    /**
     * The directory that holds the Migrations and Seeds directories.
     */
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Lets you choose which connection group to use if no other is specified.
     */
    public string $defaultGroup = 'default';

    /**
     * The default database connection.
     *
     * @var array<string, mixed>
     */
    public array $default = [
        'DSN'          => '',
        'hostname'     => 'localhost',
        'username'     => '',
        'password'     => '',
        'database'     => '',
        'DBDriver'     => 'App\Database\QmsMySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => true, // exceptions are required: services rely on them to roll back
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_0900_ai_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => true,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false, // DECIMAL values stay strings: compared exactly, never as floats
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * Connection used by the automated test suite (database.tests.* in phpunit.xml / env).
     * Uses a dedicated MySQL schema: the integrity triggers and CHECK constraints are part
     * of what is tested, so SQLite is not an option.
     *
     * @var array<string, mixed>
     */
    public array $tests = [
        'DSN'          => '',
        'hostname'     => '127.0.0.1',
        'username'     => '',
        'password'     => '',
        'database'     => 'qms_tests',
        'DBDriver'     => 'App\Database\QmsMySQLi',
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

        // Never touch live data from the automated test suite.
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}

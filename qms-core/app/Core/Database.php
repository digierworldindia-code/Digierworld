<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * MySQL connection (PDO).
 *
 * - Every value reaches MySQL through a bound parameter; SQL text only ever
 *   comes from application code, never from user input.
 * - Multiple statements per call are disabled (no stacked queries).
 * - The session time zone is UTC: every DATETIME in the QMS is stored in UTC.
 * - Result values stay strings, so DECIMAL limits and readings are compared
 *   exactly and never as floats.
 * - Errors throw DatabaseException carrying the MySQL error number.
 */
final class Database
{
    /** Open transaction levels (0 = none). */
    public int $transDepth = 0;

    private ?PDO $pdo = null;

    private int $affectedRows = 0;

    private bool $transFailed = false;

    private string $lastQuery = '';

    /**
     * @param array{host?: string|null, port?: int|string|null, socket?: string|null, database: string, username: string, password: string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Connection settings from the environment (.env): DB_HOST, DB_PORT,
     * DB_SOCKET, DB_NAME, DB_USER, DB_PASS (or TEST_DB_* for the test suite).
     */
    public static function fromEnv(string $prefix = 'DB_'): self
    {
        return new self([
            'host'     => Env::get($prefix . 'HOST', 'localhost'),
            'port'     => Env::int($prefix . 'PORT', 3306),
            'socket'   => Env::get($prefix . 'SOCKET', ''),
            'database' => (string) Env::get($prefix . 'NAME', ''),
            'username' => (string) Env::get($prefix . 'USER', ''),
            'password' => (string) Env::get($prefix . 'PASS', ''),
        ]);
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $socket = (string) ($this->config['socket'] ?? '');
        $dsn    = $socket !== ''
            ? 'mysql:unix_socket=' . $socket
            : 'mysql:host=' . ($this->config['host'] ?: 'localhost') . ';port=' . (int) ($this->config['port'] ?: 3306);
        if ($this->config['database'] !== '') {
            $dsn .= ';dbname=' . $this->config['database'];
        }
        $dsn .= ';charset=utf8mb4';

        try {
            $pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true,
                PDO::ATTR_STRINGIFY_FETCHES  => true,
                PDO::ATTR_TIMEOUT            => 10,
                self::mysqlAttribute('MULTI_STATEMENTS') => false,
                self::mysqlAttribute('LOCAL_INFILE')     => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00', SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES')");
        } catch (PDOException $e) {
            throw DatabaseException::fromPdo($e);
        }

        return $this->pdo = $pdo;
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /** Builder without a table (sub-queries use ->from()). */
    public function newQuery(): QueryBuilder
    {
        return new QueryBuilder($this, '');
    }

    /**
     * Runs one statement with ? placeholders.
     *
     * @param list<mixed> $binds
     */
    public function query(string $sql, array $binds = []): Result
    {
        $this->lastQuery = $sql;

        try {
            $statement = $this->pdo()->prepare($sql);
            $position  = 1;
            foreach ($binds as $value) {
                [$value, $type] = self::bindable($value);
                $statement->bindValue($position++, $value, $type);
            }
            $statement->execute();
            $this->affectedRows = $statement->rowCount();
            $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
            $statement->closeCursor();
        } catch (PDOException $e) {
            if ($this->transDepth > 0) {
                $this->transFailed = true;
            }

            throw DatabaseException::fromPdo($e, $sql);
        }

        return new Result($rows);
    }

    /**
     * Runs trusted SQL text without placeholder parsing (schema scripts, DDL).
     */
    public function exec(string $sql): int
    {
        $this->lastQuery = $sql;

        try {
            $count = $this->pdo()->exec($sql);
        } catch (PDOException $e) {
            throw DatabaseException::fromPdo($e, $sql);
        }

        return (int) $count;
    }

    public function insertID(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    public function lastQuery(): string
    {
        return $this->lastQuery;
    }

    public function getDatabase(): string
    {
        return (string) $this->config['database'];
    }

    public function getVersion(): string
    {
        return (string) $this->query('SELECT VERSION() AS v')->getRow('v');
    }

    /**
     * Literal for the rare SQL fragment built in code (never for user input
     * that could be bound instead).
     */
    public function escape(mixed $value): string|int|float
    {
        return match (true) {
            $value === null       => 'NULL',
            is_bool($value)       => (int) $value,
            is_int($value)        => $value,
            is_float($value)      => $value,
            $value instanceof \BackedEnum => $this->escape($value->value),
            default               => $this->pdo()->quote((string) $value),
        };
    }

    // ------------------------------------------------------------------
    // Transactions (re-entrant: inner levels join the outer transaction)
    // ------------------------------------------------------------------

    public function transBegin(): bool
    {
        if ($this->transDepth === 0) {
            try {
                $this->pdo()->beginTransaction();
            } catch (PDOException $e) {
                throw DatabaseException::fromPdo($e, 'BEGIN');
            }
            $this->transFailed = false;
        }
        $this->transDepth++;

        return true;
    }

    public function transCommit(): bool
    {
        if ($this->transDepth === 0) {
            return false;
        }
        if ($this->transDepth > 1) {
            $this->transDepth--;

            return true;
        }

        try {
            $this->pdo()->commit();
        } catch (PDOException $e) {
            $this->transDepth = 0;

            throw DatabaseException::fromPdo($e, 'COMMIT');
        }
        $this->transDepth = 0;

        return true;
    }

    public function transRollback(): bool
    {
        if ($this->transDepth === 0) {
            return false;
        }
        if ($this->transDepth > 1) {
            $this->transDepth--;
            $this->transFailed = true;

            return true;
        }

        $this->transDepth = 0;
        if ($this->pdo instanceof PDO && $this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException) {
                // the server already ended the transaction (deadlock victim)
            }
        }

        return true;
    }

    public function transStatus(): bool
    {
        return ! $this->transFailed;
    }

    public function close(): void
    {
        $this->pdo        = null;
        $this->transDepth = 0;
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    private static function bindable(mixed $value): array
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return match (true) {
            $value === null  => [null, PDO::PARAM_NULL],
            is_bool($value)  => [(int) $value, PDO::PARAM_INT],
            is_int($value)   => [$value, PDO::PARAM_INT],
            is_float($value) => [self::floatString($value), PDO::PARAM_STR],
            is_array($value) => throw new \InvalidArgumentException('Arrays cannot be bound to a single placeholder.'),
            default          => [(string) $value, PDO::PARAM_STR],
        };
    }

    private static function floatString(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    /** PDO MySQL attribute constant (Pdo\Mysql::X on PHP 8.4+, PDO::MYSQL_X before). */
    private static function mysqlAttribute(string $name): int
    {
        $modern = 'Pdo\\Mysql::ATTR_' . $name;

        return defined($modern) ? (int) constant($modern) : (int) constant('PDO::MYSQL_ATTR_' . $name);
    }
}

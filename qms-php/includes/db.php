<?php
/**
 * MySQL access with PDO.
 *
 * Every value goes to MySQL as a bound parameter (?) – SQL text only ever comes
 * from this code, never from user input. The connection uses UTC, strict mode
 * and refuses several statements in one call.
 *
 *   $rows = db_all('SELECT * FROM parts WHERE is_active = ? ORDER BY part_number', [1]);
 *   $row  = db_row('SELECT * FROM gauges WHERE id = ?', [$id]);
 *   $id   = db_insert('machines', ['machine_code' => 'CNC-01', 'machine_name' => 'Lathe']);
 *   db_update('machines', ['machine_name' => 'Lathe 1'], 'id = ?', [$id]);
 *   db_transaction(function () { … });
 */
defined('QMS') || exit;

/**
 * The shared connection. Pass a PDO to replace it (installer, tests).
 */
function db(?PDO $replace = null): PDO
{
    static $pdo = null;
    if ($replace !== null) {
        $pdo = $replace;
    }
    if ($pdo === null) {
        $c   = (array) config('db', []);
        $pdo = db_connect((string) ($c['host'] ?? 'localhost'), (int) ($c['port'] ?? 3306), (string) ($c['name'] ?? ''),
            (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), (string) ($c['socket'] ?? ''));
    }

    return $pdo;
}

function db_connect(string $host, int $port, string $name, string $user, string $pass, string $socket = ''): PDO
{
    $dsn = $socket !== '' ? 'mysql:unix_socket=' . $socket : 'mysql:host=' . ($host !== '' ? $host : 'localhost') . ';port=' . ($port ?: 3306);
    if ($name !== '') {
        $dsn .= ';dbname=' . $name;
    }
    $dsn .= ';charset=utf8mb4';

    $multi  = defined('Pdo\\Mysql::ATTR_MULTI_STATEMENTS') ? constant('Pdo\\Mysql::ATTR_MULTI_STATEMENTS') : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
    $infile = defined('Pdo\\Mysql::ATTR_LOCAL_INFILE') ? constant('Pdo\\Mysql::ATTR_LOCAL_INFILE') : PDO::MYSQL_ATTR_LOCAL_INFILE;
    $pdo    = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
        PDO::ATTR_STRINGIFY_FETCHES  => true,   // DECIMAL limits stay exact strings
        PDO::ATTR_TIMEOUT            => 10,
        $multi                       => false,  // no stacked queries
        $infile                      => false,
    ]);
    // All DATETIME values are stored in UTC; strict mode refuses invalid data.
    $pdo->exec("SET time_zone = '+00:00', SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES')");

    return $pdo;
}

/**
 * Runs one statement with ? placeholders.
 *
 * @param list<mixed> $params
 */
function db_query(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $position  = 1;
    foreach ($params as $value) {
        if (is_bool($value)) {
            $value = (int) $value;
        }
        $type = match (true) {
            $value === null => PDO::PARAM_NULL,
            is_int($value)  => PDO::PARAM_INT,
            default         => PDO::PARAM_STR,
        };
        $statement->bindValue($position++, is_float($value) ? rtrim(rtrim(sprintf('%.14F', $value), '0'), '.') : $value, $type);
    }
    $statement->execute();

    return $statement;
}

/** @return list<array<string, string|null>> */
function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/** @return array<string, string|null>|null */
function db_row(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();

    return $row === false ? null : $row;
}

/** First column of the first row (or null). */
function db_value(string $sql, array $params = []): mixed
{
    $value = db_query($sql, $params)->fetchColumn();

    return $value === false ? null : $value;
}

/** @return list<string|null> first column of every row */
function db_column(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

/** Runs INSERT / UPDATE / DELETE; returns the number of changed rows. */
function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}

/**
 * @param array<string, mixed> $data column => value
 *
 * @return int new id
 */
function db_insert(string $table, array $data): int
{
    $columns = array_map('db_name', array_keys($data));
    db_query('INSERT INTO ' . db_name($table) . ' (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));

    return (int) db()->lastInsertId();
}

/**
 * @param array<string, mixed> $data   column => value
 * @param string               $where  e.g. 'id = ?' (required)
 * @param list<mixed>          $params values for $where
 */
function db_update(string $table, array $data, string $where, array $params = []): int
{
    if (trim($where) === '') {
        throw new InvalidArgumentException('db_update() needs a WHERE condition.');
    }
    $set = [];
    foreach (array_keys($data) as $column) {
        $set[] = db_name($column) . ' = ?';
    }

    return db_exec('UPDATE ' . db_name($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . $where, [...array_values($data), ...$params]);
}

/** Placeholders for IN (…); an empty list matches nothing. */
function db_in(array $values): string
{
    return $values === [] ? 'NULL' : implode(', ', array_fill(0, count($values), '?'));
}

/** Escapes % _ ! for LIKE … ESCAPE '!'. */
function db_like(string $value, string $side = 'both'): string
{
    $value = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);

    return match ($side) {
        'after'  => $value . '%',
        'before' => '%' . $value,
        default  => '%' . $value . '%',
    };
}

/** A table or column name written in code ([A-Za-z0-9_] only), quoted. */
function db_name(string $name): string
{
    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException("Invalid SQL name: {$name}");
    }

    return '`' . $name . '`';
}

/** MySQL error number of a database exception (1062 duplicate, 1213 deadlock …). */
function db_error_code(Throwable $e): int
{
    for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
        if ($cause instanceof PDOException && isset($cause->errorInfo[1])) {
            return (int) $cause->errorInfo[1];
        }
    }

    return 0;
}

/**
 * Runs $work in one transaction: commit on success, rollback on any error.
 * Deadlocks and lock wait timeouts are retried. Calls inside a running
 * transaction join it.
 */
function db_transaction(callable $work, int $attempts = 3): mixed
{
    static $depth = 0;
    if ($depth > 0) {
        return $work();
    }

    for ($try = 1; ; $try++) {
        db()->beginTransaction();
        $depth = 1;
        try {
            $result = $work();
            db()->commit();
            $depth = 0;

            return $result;
        } catch (Throwable $e) {
            $depth = 0;
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($try < $attempts && in_array(db_error_code($e), [1205, 1213], true)) {
                usleep(random_int(20000, 120000) * $try);

                continue;
            }

            throw $e;
        }
    }
}

/**
 * Splits a trusted .sql file into statements (handles quotes, comments and
 * DELIMITER blocks for triggers). Used by the installer only.
 *
 * @return list<string>
 */
function sql_split(string $sql): array
{
    $statements = [];
    $current    = '';
    $delimiter  = ';';
    $quote      = null;
    $length     = strlen($sql);
    $lineStart  = true;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($quote !== null) {
            $current .= $char;
            if ($char === '\\' && $quote !== '`') {
                $current .= $next;
                $i++;
            } elseif ($char === $quote) {
                $quote = null;
            }
            $lineStart = false;

            continue;
        }
        if ($lineStart && strncasecmp(substr($sql, $i, 10), 'DELIMITER ', 10) === 0) {
            $end       = strpos($sql, "\n", $i);
            $line      = $end === false ? substr($sql, $i) : substr($sql, $i, $end - $i);
            $delimiter = trim(substr($line, 10));
            $i         = $end === false ? $length : $end;

            continue;
        }
        if (($char === '-' && $next === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) || $char === '#') {
            $end = strpos($sql, "\n", $i);
            $i   = $end === false ? $length : $end - 1;

            continue;
        }
        if ($char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i   = $end === false ? $length : $end + 1;

            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
        }
        if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current   = '';
            $i        += strlen($delimiter) - 1;
            $lineStart = false;

            continue;
        }
        $current  .= $char;
        $lineStart = $char === "\n" || ($lineStart && ($char === ' ' || $char === "\t" || $char === "\r"));
    }
    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    return $statements;
}

/** Runs a trusted .sql file from database/. */
function sql_run_file(string $file): int
{
    $count = 0;
    foreach (sql_split((string) file_get_contents($file)) as $statement) {
        db()->exec($statement);
        $count++;
    }

    return $count;
}

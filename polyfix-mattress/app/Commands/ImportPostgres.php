<?php

namespace App\Commands;

use App\Libraries\AuditChain;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * One-way copy of the existing POLYFIX PostgreSQL database into MySQL.
 *
 *   php spark polyfix:import-postgres \
 *       --pg-dsn "pgsql:host=127.0.0.1;port=5432;dbname=colifees" --pg-user colifees_backup
 *
 * Passwords: POLYFIX_PG_PASSWORD and POLYFIX_OWNER_PASSWORD, or hidden prompts.
 * Add --dry-run to do everything, verify everything, and write nothing.
 *
 * Guarantees, in order of importance:
 *
 *  1. The source is never modified. The PostgreSQL session is READ ONLY at the
 *     transaction level, so even a bug here cannot write to it.
 *  2. The target is never overwritten. If any business table in MySQL already
 *     holds a row, the import refuses before writing anything. There is no
 *     --force: re-running means restoring an empty target yourself.
 *  3. All or nothing. Every row goes in inside one MySQL transaction. Any
 *     failure — a CHECK violation, an orphaned foreign key, a count mismatch, a
 *     content difference, a broken audit chain — rolls the whole import back.
 *  4. Proof, not a count. After copying, every row of every table is read back
 *     from both databases and compared column by column, and the audit hash
 *     chain is recomputed over the imported rows.
 *
 * Needs the pdo_pgsql extension, but only while this command runs.
 */
class ImportPostgres extends BaseCommand
{
    protected $group       = 'POLYFIX';
    protected $name        = 'polyfix:import-postgres';
    protected $description = 'Copy the existing PostgreSQL database into MySQL, verified, all-or-nothing.';
    protected $usage       = 'polyfix:import-postgres --pg-dsn <dsn> --pg-user <user> [--owner-user <user>] [--dry-run]';
    protected $options     = [
        '--pg-dsn'     => 'PDO DSN of the source, e.g. pgsql:host=127.0.0.1;port=5432;dbname=colifees',
        '--pg-user'    => 'PostgreSQL account to read with (colifees_backup recommended)',
        '--owner-user' => 'MySQL account with rights on every table (default: polyfix_owner)',
        '--dry-run'    => 'Copy and verify inside a transaction, then roll back',
    ];

    /** Tables that exist only on the MySQL side and are maintained by the app. */
    private const MYSQL_ONLY = ['migrations', 'ci_sessions', 'identifier_counters', 'audit_chain_head'];

    /** MySQL table => PostgreSQL table, where the name changed. */
    private const RENAMED = ['legacy_prisma_migrations' => '_prisma_migrations'];

    /** Exact text the audit hash covers: compared byte for byte, never normalised. */
    private const EXACT_TEXT = ['audit_logs.previous_value', 'audit_logs.new_value'];

    /** PostgreSQL sequence => identifier_counters.name */
    private const SEQUENCES = [
        'colifees_serial_seq'   => 'serial',
        'colifees_batch_seq'    => 'batch',
        'colifees_claim_seq'    => 'claim',
        'colifees_dispatch_seq' => 'dispatch',
        'colifees_dealer_seq'   => 'dealer',
    ];

    private PDO $pg;
    private BaseConnection $my;

    public function run(array $params)
    {
        $dsn    = $params['pg-dsn'] ?? CLI::getOption('pg-dsn');
        $pgUser = $params['pg-user'] ?? CLI::getOption('pg-user');
        $owner  = $params['owner-user'] ?? CLI::getOption('owner-user') ?? 'polyfix_owner';
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;

        if (! $dsn || ! $pgUser) {
            CLI::error('Both --pg-dsn and --pg-user are required.');
            CLI::write($this->usage);

            return EXIT_ERROR;
        }
        if (! extension_loaded('pdo_pgsql')) {
            CLI::error('The pdo_pgsql PHP extension is required to read the source database.');

            return EXIT_ERROR;
        }

        $pgPass = getenv('POLYFIX_PG_PASSWORD') ?: $this->promptHidden("PostgreSQL password for {$pgUser}: ");
        $myPass = getenv('POLYFIX_OWNER_PASSWORD') ?: $this->promptHidden("MySQL password for {$owner}: ");

        try {
            $this->connectSource($dsn, $pgUser, $pgPass);
            $this->connectTarget($owner, $myPass);

            $report = $this->import($dryRun);
        } catch (Throwable $e) {
            CLI::error('Import aborted. Nothing was written to MySQL.');
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        $this->printReport($report, $dryRun);

        return EXIT_SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function connectSource(string $dsn, string $user, string $password): void
    {
        $this->pg = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // One consistent snapshot, and a session that physically cannot write.
        $this->pg->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        // The source enforces row-level security on dealer tables; staff scope
        // reads every row. (colifees_backup bypasses RLS anyway.)
        $this->pg->exec("SET LOCAL app.dealer_id = 'ALL'");
    }

    private function connectTarget(string $user, string $password): void
    {
        $config             = config(Database::class)->default;
        $config['username'] = $user;
        $config['password'] = $password;
        $this->my           = Database::connect($config, false);
        $this->my->initialize();
    }

    /**
     * @return array{tables: array<string, array{source:int, target:int}>, counters: array<string,int>, audit: array{rows:int, problems:list<string>}}
     */
    private function import(bool $dryRun): array
    {
        $tables = $this->targetTables();
        $this->assertTargetEmpty($tables);

        $report = ['tables' => [], 'counters' => [], 'audit' => ['rows' => 0, 'problems' => []]];

        // CHECK and UNIQUE constraints stay on throughout. Foreign keys are
        // validated explicitly below instead, because the schema has circular
        // references (a replacement mattress points at the claim that points at
        // the original mattress) that no insertion order can satisfy.
        $this->my->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->my->transBegin();

        try {
            foreach ($tables as $table) {
                $copied                   = $this->copyTable($table);
                $report['tables'][$table] = ['source' => $copied, 'target' => 0];
                CLI::write(sprintf('  copied %-26s %6d rows', $table, $copied));
            }

            $this->assertNoOrphans();
            $report['counters'] = $this->syncCounters();
            $this->syncChainHead();

            foreach ($tables as $table) {
                $report['tables'][$table]['target'] = (int) $this->my->table($table)->countAllResults();
                if ($report['tables'][$table]['target'] !== $report['tables'][$table]['source']) {
                    throw new RuntimeException("Row count mismatch in {$table}.");
                }
                $this->assertIdenticalContent($table);
            }

            $report['audit'] = (new AuditChain($this->my))->verify();
            if ($report['audit']['problems'] !== []) {
                throw new RuntimeException('The audit hash chain does not verify after import: ' . $report['audit']['problems'][0]);
            }

            if ($dryRun) {
                $this->my->transRollback();
            } else {
                $this->my->transCommit();
            }
        } catch (Throwable $e) {
            $this->my->transRollback();

            throw $e;
        } finally {
            $this->my->query('SET FOREIGN_KEY_CHECKS = 1');
            $this->pg->exec('ROLLBACK');
        }

        return $report;
    }

    /** @return list<string> MySQL tables to fill, parents before children where possible. */
    private function targetTables(): array
    {
        $tables = array_values(array_filter(
            $this->my->listTables(),
            static fn (string $t): bool => ! in_array($t, self::MYSQL_ONLY, true),
        ));
        sort($tables);

        $sourceTables = $this->pg->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'",
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            if (! in_array(self::RENAMED[$table] ?? $table, $sourceTables, true)) {
                throw new RuntimeException("Source database has no table for {$table}. Is this the POLYFIX database?");
            }
        }

        return $tables;
    }

    /** @param list<string> $tables */
    private function assertTargetEmpty(array $tables): void
    {
        $occupied = [];
        foreach ($tables as $table) {
            if ($this->my->table($table)->countAllResults() > 0) {
                $occupied[] = $table;
            }
        }
        if ($occupied !== []) {
            throw new RuntimeException(
                'Refusing to import: these MySQL tables already hold data: ' . implode(', ', $occupied)
                . '. The import never overwrites. Point it at an empty, freshly migrated database.',
            );
        }
    }

    /** @return array<string, array{type:string, data_type:string}> */
    private function targetColumns(string $table): array
    {
        $rows = $this->my->query(
            'SELECT column_name, column_type, data_type FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        )->getResultArray();

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['column_name'] ?? $row['COLUMN_NAME']] = [
                'type'      => strtolower($row['column_type'] ?? $row['COLUMN_TYPE']),
                'data_type' => strtolower($row['data_type'] ?? $row['DATA_TYPE']),
            ];
        }

        return $columns;
    }

    /** @return array<string, string> source column => udt name */
    private function sourceColumns(string $pgTable): array
    {
        $stmt = $this->pg->prepare(
            "SELECT column_name, udt_name FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position",
        );
        $stmt->execute([$pgTable]);

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Builds a SELECT that renders every value exactly as MySQL should receive
     * it, so no conversion happens in PHP where a subtle difference could hide.
     *
     * @param list<string> $columns
     * @param array<string,string> $udt
     */
    private function sourceSelect(string $pgTable, array $columns, array $udt): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $q = '"' . $column . '"';
            $parts[] = match ($udt[$column]) {
                // UTC, microseconds, the format DATETIME(6) takes and returns
                'timestamptz', 'timestamp' => "to_char({$q} AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS {$q}",
                'bool'                     => "({$q})::int AS {$q}",
                '_text'                    => "to_json({$q})::text AS {$q}",
                default                    => "({$q})::text AS {$q}",
            };
        }

        $pk = $this->sourcePrimaryKey($pgTable);

        return 'SELECT ' . implode(', ', $parts) . ' FROM "' . $pgTable . '" ORDER BY ' . implode(', ', array_map(static fn ($c) => '"' . $c . '"', $pk));
    }

    /** @return list<string> */
    private function sourcePrimaryKey(string $pgTable): array
    {
        $stmt = $this->pg->prepare(
            "SELECT a.attname FROM pg_index i
               JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
              WHERE i.indrelid = ('public.' || quote_ident(?))::regclass AND i.indisprimary
              ORDER BY array_position(i.indkey, a.attnum)",
        );
        $stmt->execute([$pgTable]);
        $pk = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($pk === []) {
            throw new RuntimeException("Source table {$pgTable} has no primary key; cannot verify it row by row.");
        }

        return $pk;
    }

    /** @return list<string> columns present on both sides */
    private function sharedColumns(string $table): array
    {
        $pgTable = self::RENAMED[$table] ?? $table;
        $target  = $this->targetColumns($table);
        $source  = $this->sourceColumns($pgTable);

        foreach (array_keys($source) as $column) {
            if (! isset($target[$column])) {
                throw new RuntimeException("Source column {$pgTable}.{$column} has nowhere to go in MySQL; refusing to drop data.");
            }
        }

        return array_values(array_filter(array_keys($target), static fn ($c) => isset($source[$c])));
    }

    private function copyTable(string $table): int
    {
        $pgTable = self::RENAMED[$table] ?? $table;
        $columns = $this->sharedColumns($table);
        $udt     = $this->sourceColumns($pgTable);
        $stmt    = $this->pg->query($this->sourceSelect($pgTable, $columns, $udt));

        $sqlHead = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES ';
        $rowSql  = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $count = 0;
        $batch = [];
        $binds = [];
        $flush = function () use (&$batch, &$binds, $sqlHead): void {
            if ($batch === []) {
                return;
            }
            $this->my->query($sqlHead . implode(', ', $batch), $binds);
            $batch = [];
            $binds = [];
        };

        while ($row = $stmt->fetch()) {
            $batch[] = $rowSql;
            foreach ($columns as $column) {
                $binds[] = $row[$column];
            }
            $count++;
            if (count($batch) === 200) {
                $flush();
            }
        }
        $flush();

        return $count;
    }

    /** Every foreign key, checked explicitly, since FK checks were off during the copy. */
    private function assertNoOrphans(): void
    {
        $fks = $this->my->query(
            'SELECT k.table_name, k.column_name, k.referenced_table_name, k.referenced_column_name, k.constraint_name
               FROM information_schema.key_column_usage k
              WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL',
        )->getResultArray();

        foreach ($fks as $fk) {
            $fk     = array_change_key_case($fk, CASE_LOWER);
            $orphan = $this->my->query(sprintf(
                'SELECT COUNT(*) AS n FROM `%s` c LEFT JOIN `%s` p ON p.`%s` = c.`%s`
                  WHERE c.`%s` IS NOT NULL AND p.`%s` IS NULL',
                $fk['table_name'], $fk['referenced_table_name'], $fk['referenced_column_name'],
                $fk['column_name'], $fk['column_name'], $fk['referenced_column_name'],
            ))->getRow()->n;

            if ((int) $orphan > 0) {
                throw new RuntimeException("{$orphan} orphaned row(s) violate {$fk['constraint_name']}.");
            }
        }
    }

    /** @return array<string,int> */
    private function syncCounters(): array
    {
        $values = [];
        foreach (self::SEQUENCES as $sequence => $name) {
            $row = $this->pg->query("SELECT last_value, is_called FROM \"{$sequence}\"")->fetch();
            // A sequence that was never called has not issued last_value yet.
            $value         = $row ? ((int) $row['last_value'] - ($row['is_called'] ? 0 : 1)) : 0;
            $values[$name] = max(0, $value);
            $this->my->query(
                'INSERT INTO identifier_counters (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$name, $values[$name]],
            );
        }

        return $values;
    }

    private function syncChainHead(): void
    {
        $last = $this->my->query('SELECT id, row_hash FROM audit_logs ORDER BY id DESC LIMIT 1')->getRow();
        $this->my->query(
            'INSERT INTO audit_chain_head (id, last_audit_id, last_hash) VALUES (1, ?, ?)
               ON DUPLICATE KEY UPDATE last_audit_id = VALUES(last_audit_id), last_hash = VALUES(last_hash)',
            [$last->id ?? null, $last->row_hash ?? null],
        );
    }

    /**
     * Reads every row back from both databases and compares each value.
     * Differences in representation that are not differences in data (JSON key
     * order and spacing, trailing fractional zeros) are normalised first; the
     * audit value columns are not, because their exact bytes are what the hash
     * chain covers.
     */
    private function assertIdenticalContent(string $table): void
    {
        $pgTable = self::RENAMED[$table] ?? $table;
        $columns = $this->sharedColumns($table);
        $udt     = $this->sourceColumns($pgTable);
        $target  = $this->targetColumns($table);
        $pk      = $this->sourcePrimaryKey($pgTable);

        $source = [];
        foreach ($this->pg->query($this->sourceSelect($pgTable, $columns, $udt)) as $row) {
            $source[$this->rowKey($row, $pk)] = $row;
        }

        $orderBy = implode(', ', array_map(static fn ($c) => '`' . $c . '`', $pk));
        $query   = $this->my->query('SELECT `' . implode('`, `', $columns) . '` FROM `' . $table . '` ORDER BY ' . $orderBy);

        while ($row = $query->getUnbufferedRow('array')) {
            $key = $this->rowKey($row, $pk);
            if (! isset($source[$key])) {
                throw new RuntimeException("{$table}: row {$key} exists in MySQL but not in the source.");
            }
            foreach ($columns as $column) {
                $exact = in_array("{$table}.{$column}", self::EXACT_TEXT, true);
                $a     = $this->canonical($source[$key][$column], $target[$column]['data_type'], $exact);
                $b     = $this->canonical($row[$column], $target[$column]['data_type'], $exact);
                if ($a !== $b) {
                    throw new RuntimeException("{$table}.{$column} differs for row {$key}.");
                }
            }
            unset($source[$key]);
        }

        if ($source !== []) {
            throw new RuntimeException("{$table}: " . count($source) . ' source row(s) missing from MySQL.');
        }
    }

    /** @param array<string,mixed> $row @param list<string> $pk */
    private function rowKey(array $row, array $pk): string
    {
        return implode('|', array_map(static fn ($c) => (string) $row[$c], $pk));
    }

    private function canonical(mixed $value, string $dataType, bool $exact): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        if ($exact) {
            return $value;
        }

        return match ($dataType) {
            'json'     => json_encode($this->sortKeys(json_decode($value, true, 512, JSON_THROW_ON_ERROR)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'datetime' => str_pad(str_contains($value, '.') ? $value : $value . '.', 26, '0'),
            'tinyint'  => (string) (int) $value,
            default    => $value,
        };
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->sortKeys($v), $value);
    }

    // -------------------------------------------------------------------------

    /** @param array{tables: array<string, array{source:int, target:int}>, counters: array<string,int>, audit: array{rows:int, problems:list<string>}} $report */
    private function printReport(array $report, bool $dryRun): void
    {
        $total = array_sum(array_column($report['tables'], 'source'));
        CLI::newLine();
        CLI::write(sprintf(
            '%d rows across %d tables copied and verified value by value.',
            $total, count($report['tables']),
        ), 'green');
        CLI::write('Foreign keys: no orphaned rows. Audit chain: ' . $report['audit']['rows'] . ' rows, intact.', 'green');
        CLI::write('Identifier counters: ' . implode(', ', array_map(
            static fn ($k, $v) => "{$k}={$v}", array_keys($report['counters']), $report['counters'],
        )));
        CLI::newLine();
        CLI::write($dryRun
            ? 'DRY RUN: everything above was rolled back. MySQL is unchanged.'
            : 'Committed. The PostgreSQL source was not modified.', $dryRun ? 'yellow' : 'green');
    }

    private function promptHidden(string $label): string
    {
        if (! CLI::isInteractive()) {
            return '';
        }
        CLI::print($label);
        $stty = shell_exec('stty -g 2>/dev/null');
        if (is_string($stty)) {
            shell_exec('stty -echo 2>/dev/null');
        }
        $value = trim((string) fgets(STDIN));
        if (is_string($stty)) {
            shell_exec('stty ' . escapeshellarg(trim($stty)) . ' 2>/dev/null');
        }
        CLI::newLine();

        return $value;
    }
}

<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Database\Query;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Events\Events;
use Config\Database;
use Config\Migrations;
use Throwable;

/**
 * Builds POLYFIZZ-EXISTING-DATABASE-UPGRADE.sql.
 *
 *   php spark polyfix:build-upgrade-sql --from polyfizz_before --owner-user polyfix_owner
 *
 * A build tool, not something to run on a live installation. `--from` must be
 * a throwaway copy of a database at the previous release: the command applies
 * the migrations and the seeders to it, watching every statement the framework
 * sends, and writes the schema changes out as SQL. The script is therefore
 * generated from the migrations rather than transcribed from them, so it
 * cannot drift away from what the application itself does.
 *
 * Two kinds of statement are treated differently.
 *
 * The schema changes go out as they were issued, each one wrapped in a guard
 * that looks in information_schema first, so the file is safe to run twice and
 * safe on a database that already has some of the changes. Nothing is dropped
 * except a CHECK or an index that the next statement puts back.
 *
 * The reference data cannot go out as issued: the seeder writes `WHERE id =
 * '<uuid>'`, and those uuids belong to this throwaway copy, not to the
 * client's database, where the same role has a different one. So roles,
 * permissions, their grants, the settings, the counters, the website pages and
 * the FAQs are written afresh, matched on the keys the application looks them
 * up by, and inserted only where they are missing. An administrator's edits
 * survive; nothing already there is overwritten.
 */
class BuildUpgradeSql extends BaseCommand
{
    protected $group       = 'POLYFIZZ';
    protected $name        = 'polyfix:build-upgrade-sql';
    protected $description = 'Generate the safe upgrade SQL by applying the migrations to a copy of the previous release.';
    protected $usage       = 'polyfix:build-upgrade-sql --from <database> [--owner-user <name>] [--out <file>]';
    protected $options     = [
        '--from'       => 'A throwaway copy of a database at the previous release',
        '--owner-user' => 'MySQL account with DDL rights (default: polyfix_owner)',
        '--out'        => 'Output file (default: delivery/POLYFIZZ-EXISTING-DATABASE-UPGRADE.sql)',
    ];

    /** @var list<string> Every statement the framework sent, in order. */
    private array $seen = [];

    /** Reference tables, and the column the application identifies a row by. */
    private const REFERENCE = [
        'permissions'         => 'key',
        'roles'               => 'key',
        'system_settings'     => 'key',
        'identifier_counters' => 'name',
        'website_pages'       => 'slug',
        'faqs'                => 'question',
    ];

    public function run(array $params)
    {
        $from = $params['from'] ?? CLI::getOption('from');
        $user = $params['owner-user'] ?? CLI::getOption('owner-user') ?? 'polyfix_owner';
        $out = $params['out'] ?? CLI::getOption('out') ?? 'delivery/POLYFIZZ-EXISTING-DATABASE-UPGRADE.sql';
        // spark runs with public/ as the working directory, and a delivery
        // file written under the document root would be downloadable by
        // anyone. A relative path means relative to the project.
        if (! str_starts_with($out, '/')) {
            $out = ROOTPATH . $out;
        }

        if (! is_string($from) || $from === '') {
            CLI::error('Name the throwaway database to build from: --from <database>');

            return EXIT_ERROR;
        }

        $password = getenv('POLYFIZZ_OWNER_PASSWORD');
        if (! is_string($password) || $password === '') {
            CLI::error('Set POLYFIZZ_OWNER_PASSWORD; this command needs DDL rights.');

            return EXIT_ERROR;
        }

        $config               = config(Database::class);
        $connection           = $config->default;
        $connection['database'] = $from;
        $connection['username'] = $user;
        $connection['password'] = $password;

        $db = Database::connect($connection, false);

        CLI::write("Applying the migrations to `{$from}` and watching every statement…", 'yellow');

        Events::on('DBQuery', function (Query $query): void {
            $this->seen[] = (string) $query->getQuery();
        });

        try {
            $runner = new MigrationRunner(config(Migrations::class), $connection);
            $runner->setNamespace(null);
            if (! $runner->latest()) {
                CLI::error('The migrations did not complete; nothing was written.');

                return EXIT_ERROR;
            }

            $seeder = new Seeder($config, $db);
            $seeder->setSilent(true);
            $seeder->call('ReferenceData');
            $seeder->call('WebsiteContent');
        } catch (Throwable $e) {
            CLI::error('Failed while upgrading the copy: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $sql = $this->assemble($db);

        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, $sql);

        $guards = substr_count($sql, 'PREPARE ');
        CLI::write('', 'white');
        CLI::write("built: {$out}", 'green');
        CLI::write(sprintf(
            '       %s, %d guarded schema step(s), %d insert statement(s)',
            $this->size($out),
            $guards,
            substr_count($sql, 'INSERT INTO'),
        ));

        return EXIT_SUCCESS;
    }

    private function size(string $path): string
    {
        $bytes = (int) filesize($path);

        return $bytes > 1024 ? round($bytes / 1024) . 'K' : $bytes . 'B';
    }

    /** @return list<string> */
    private function ddl(): array
    {
        return array_values(array_filter(
            $this->seen,
            static fn (string $q): bool => preg_match('/^\s*(CREATE TABLE|ALTER TABLE)\b/i', $q) === 1,
        ));
    }

    /** The rebrand's content rewrites, which are idempotent by construction. */
    private function contentUpdates(): array
    {
        return array_values(array_filter(
            $this->seen,
            static fn (string $q): bool => preg_match('/^\s*UPDATE\b/i', $q) === 1 && str_contains($q, 'REPLACE('),
        ));
    }

    private function assemble(\CodeIgniter\Database\BaseConnection $db): string
    {
        $parts   = [$this->header(), $this->precondition()];
        $parts[] = $this->schemaSection();
        $parts[] = $this->contentSection();
        $parts[] = $this->referenceSection($db);
        $parts[] = $this->migrationsSection($db);
        $parts[] = $this->closing();

        return implode("\n", $parts);
    }

    private function header(): string
    {
        return <<<'SQL'
            -- =============================================================================
            -- POLYFIZZ — UPGRADE AN EXISTING DATABASE
            -- -----------------------------------------------------------------------------
            -- Brings a database from the previous release up to this one. It adds; it does
            -- not reset. There is no DROP TABLE, no DROP DATABASE, no TRUNCATE and no
            -- DELETE of a business record anywhere in this file — check for yourself:
            --
            --     grep -iE 'drop table|drop database|truncate' POLYFIZZ-EXISTING-DATABASE-UPGRADE.sql
            --
            -- Your users, dealers, products, mattresses, batches, dispatches, sales,
            -- warranties, claims, customers and audit trail are left exactly as they are.
            -- Serial numbers already issued in the old CLF… format keep working; the
            -- constraint is widened to accept the new PFZ-… format alongside them.
            --
            -- BEFORE YOU RUN IT: take a backup you have restored at least once.
            --
            --     mysqldump --single-transaction --routines <database> > backup-before-upgrade.sql
            --
            -- HOW TO RUN IT
            --   phpMyAdmin:  select the database, Import, choose this file, Go.
            --   A shell:     mysql -u <user> -p <database> < POLYFIZZ-EXISTING-DATABASE-UPGRADE.sql
            --
            -- The account running it needs ALTER, CREATE, INDEX and REFERENCES as well as
            -- SELECT/INSERT/UPDATE — the schema owner, not the application's own account.
            --
            -- It is safe to run twice. Every schema step asks information_schema whether
            -- the change is already there and does nothing if it is, so a run that was
            -- interrupted can simply be run again.
            --
            -- WHAT IT DOES
            --   * Adds seven tables: the warehouse scan log, dealer stock surveys and
            --     their line items, agent-to-dealer assignments, inventory discrepancies,
            --     and warranty review cases with their event history.
            --   * Widens the serial-number rule to accept PFZ-YYYY-NNNNNN as well as the
            --     old CLF numbers.
            --   * Adds FIELD_AGENT to the list of roles.
            --   * Lets an invoice be filed against a warranty review, not only a claim.
            --   * Makes the duplicate-scan rule real, with a unique key the database
            --     itself enforces.
            --   * Corrects the company name to POLYFIZZ in website text, product copy,
            --     SEO fields, FAQs and settings. Historical records — the audit trail,
            --     customer text — are not rewritten: they are what happened.
            --   * Adds the new permissions and the role grants that go with them, the new
            --     settings, the new identifier counters, and the website pages a fresh
            --     install now starts with. Anything already present is left untouched, so
            --     text an administrator has edited is not overwritten.
            --   * Records the migrations as applied, so `php spark polyfix:migrate` knows
            --     the schema is current and will not try to apply them again.
            --
            -- AFTERWARDS
            --     php spark polyfix:doctor        checks the whole deployment
            --
            -- If you have shell access, running the migrations instead of this file is
            -- equivalent and is the path the test suite exercises:
            --
            --     php spark polyfix:migrate --owner-user polyfix_owner
            --     php spark polyfix:seed ReferenceData
            --     php spark polyfix:seed WebsiteContent
            --
            -- This file exists for installations where only phpMyAdmin is available.
            -- =============================================================================

            SET NAMES utf8mb4;
            SET time_zone = '+00:00';
            SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

            SQL;
    }

    /**
     * Refuses to touch a database that is not a POLYFIZZ one. MySQL reports
     * the missing table by name, so the name is the message — and it is kept
     * under 64 characters, or MySQL complains about the length of the
     * identifier instead and the explanation is lost.
     */
    private function precondition(): string
    {
        return <<<'SQL'
            -- --- Is this the right database? ---------------------------------------------
            SET @core = (
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME IN ('users', 'roles', 'permissions', 'mattresses', 'dealers', 'system_settings')
            );
            SET @check = IF(@core = 6, 'DO 0',
                'SELECT * FROM `ABORTED_not_a_POLYFIZZ_database_nothing_changed`');
            PREPARE stmt FROM @check; EXECUTE stmt; DEALLOCATE PREPARE stmt;

            SQL;
    }

    /** Wraps one statement so it runs only when the guard query returns 0. */
    private function guard(string $comment, string $countSql, string $statement): string
    {
        $literal = "'" . str_replace("'", "''", $this->tidy($statement)) . "'";

        return "-- {$comment}\n"
            . 'SET @todo = (' . $this->tidy($countSql) . ");\n"
            . "SET @sql = IF(@todo = 0, {$literal}, 'DO 0');\n"
            . "PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;\n";
    }

    /**
     * One line, no runs of whitespace — and no `--` comments, which the
     * migrations' CREATE TABLE statements are full of. Collapsing the
     * newlines first would put the rest of the statement behind a comment
     * marker, and the prepared statement would be a syntax error.
     */
    private function tidy(string $sql): string
    {
        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/--\s.*$/', '', $line)),
            explode("\n", str_replace("\r\n", "\n", $sql)),
        );

        return trim((string) preg_replace('/\s+/', ' ', implode(' ', $lines)));
    }

    private const TABLE_EXISTS = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'";
    private const COLUMN_EXISTS = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = '%s'";
    // DISTINCT, because STATISTICS holds one row per column in an index: the
    // old four-column scan key counted as four, so a guard testing for one
    // never matched and the key it was meant to replace survived the upgrade.
    private const INDEX_EXISTS = "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND INDEX_NAME = '%s'";
    private const CONSTRAINT_EXISTS = "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND CONSTRAINT_NAME = '%s'";

    private function schemaSection(): string
    {
        $out = ["-- --- Schema ------------------------------------------------------------------\n"];

        foreach ($this->ddl() as $statement) {
            $out[] = $this->guardFor($statement);
        }

        return implode("\n", $out);
    }

    /**
     * Works out what to look for before running a statement, from the
     * statement itself. Everything the migrations issue is one of these five
     * shapes; anything else would be a new kind of change and is better
     * refused than guessed at.
     */
    private function guardFor(string $statement): string
    {
        $one = $this->tidy($statement);

        if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)? `(\w+)`/i', $one, $m) === 1) {
            // CREATE TABLE IF NOT EXISTS is its own guard, but the guard is
            // applied anyway so every step in the file reads the same way.
            return $this->guard("the {$m[1]} table", sprintf(self::TABLE_EXISTS, $m[1]), $statement);
        }

        if (preg_match('/^ALTER TABLE `(\w+)` ADD COLUMN `(\w+)`/i', $one, $m) === 1) {
            return $this->guard("{$m[1]}.{$m[2]}", sprintf(self::COLUMN_EXISTS, $m[1], $m[2]), $statement);
        }

        // The combined ALTER that nullifies claim_id and adds review_case_id.
        if (preg_match('/^ALTER TABLE `(\w+)` MODIFY COLUMN .*ADD COLUMN `(\w+)`/i', $one, $m) === 1) {
            return $this->guard("{$m[1]}.{$m[2]}", sprintf(self::COLUMN_EXISTS, $m[1], $m[2]), $statement);
        }

        if (preg_match('/^ALTER TABLE `(\w+)` ADD (?:UNIQUE )?KEY `(\w+)`/i', $one, $m) === 1) {
            return $this->guard("the {$m[2]} index", sprintf(self::INDEX_EXISTS, $m[1], $m[2]), $statement);
        }

        if (preg_match('/^ALTER TABLE `(\w+)` ADD CONSTRAINT `(\w+)`/i', $one, $m) === 1) {
            return $this->guard("the {$m[2]} constraint", sprintf(self::CONSTRAINT_EXISTS, $m[1], $m[2]), $statement);
        }

        // A DROP of a CHECK or an index is always followed by the statement
        // that puts a better one back, so it runs only while the old one is
        // still there — which is also what makes the file safe to re-run.
        if (preg_match('/^ALTER TABLE `(\w+)` DROP CONSTRAINT `(\w+)`/i', $one, $m) === 1) {
            return $this->guardPresent("the old {$m[2]} constraint", sprintf(self::CONSTRAINT_EXISTS, $m[1], $m[2]), $statement);
        }

        if (preg_match('/^ALTER TABLE `(\w+)` DROP INDEX `(\w+)`/i', $one, $m) === 1) {
            return $this->guardPresent("the old {$m[2]} index", sprintf(self::INDEX_EXISTS, $m[1], $m[2]), $statement);
        }

        // An ENUM being widened. Running it again changes nothing, so it needs
        // no guard; the column name is named in the comment for the reader.
        if (preg_match('/^ALTER TABLE `(\w+)` MODIFY COLUMN `(\w+)` ENUM/i', $one, $m) === 1) {
            return "-- {$m[1]}.{$m[2]}: widen the list of allowed values\n" . $this->tidy($statement) . ";\n";
        }

        throw new \RuntimeException(
            "No guard is defined for this statement, so the upgrade script cannot be built safely:\n" . $one,
        );
    }

    /**
     * The mirror of guard(): runs the statement only while the thing is still
     * there. The test is "more than none" rather than "exactly one", because a
     * count from information_schema is not always one per object.
     */
    private function guardPresent(string $comment, string $countSql, string $statement): string
    {
        $literal = "'" . str_replace("'", "''", $this->tidy($statement)) . "'";

        return "-- {$comment}\n"
            . 'SET @todo = (' . $this->tidy($countSql) . ");\n"
            . "SET @sql = IF(@todo > 0, {$literal}, 'DO 0');\n"
            . "PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;\n";
    }

    private function contentSection(): string
    {
        $out = [
            "\n-- --- The company name in editable content ------------------------------------\n"
            . "-- REPLACE on text that is already correct changes nothing, so these are safe\n"
            . "-- to run again. The audit trail and customer-entered text are not touched.\n",
        ];

        foreach ($this->contentUpdates() as $statement) {
            $out[] = $this->tidy($statement) . ";";
        }

        return implode("\n", $out) . "\n";
    }

    /** @param list<string> $columns */
    private function values(array $row, array $columns): string
    {
        $out = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            $out[] = $value === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $value) . "'";
        }

        return implode(', ', $out);
    }

    private function referenceSection(\CodeIgniter\Database\BaseConnection $db): string
    {
        $out = [
            "\n-- --- Reference data ----------------------------------------------------------\n"
            . "-- Matched on the key the application looks each row up by, never on the\n"
            . "-- internal id, because the same role has a different id in every database.\n"
            . "-- A row that is already there is left exactly as it is.\n",
        ];

        foreach (self::REFERENCE as $table => $key) {
            $rows    = $db->table($table)->get()->getResultArray();
            $columns = array_values(array_filter(
                array_keys($rows[0] ?? []),
                // Left to their defaults: who made the row and when is this
                // installation's business, not ours.
                static fn (string $c): bool => ! in_array($c, ['created_at', 'updated_at', 'created_by', 'updated_by'], true),
            ));

            $out[] = "-- {$table}: " . count($rows) . ' row(s), by `' . $key . '`';
            foreach ($rows as $row) {
                $out[] = sprintf(
                    'INSERT INTO `%s` (%s) SELECT %s FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `%s` WHERE `%s` = %s);',
                    $table,
                    implode(', ', array_map(static fn ($c) => "`{$c}`", $columns)),
                    $this->values($row, $columns),
                    $table,
                    $key,
                    "'" . str_replace("'", "''", (string) $row[$key]) . "'",
                );
            }
            $out[] = '';
        }

        $out[] = $this->grantsSection($db);

        return implode("\n", $out);
    }

    /**
     * Which role holds which permission, joined on the two keys so it lands on
     * whatever ids this database happens to use.
     */
    private function grantsSection(\CodeIgniter\Database\BaseConnection $db): string
    {
        $grants = $db->query(
            'SELECT r.`key` AS role_key, p.`key` AS permission_key
               FROM `role_permissions` rp
               JOIN `roles` r ON r.id = rp.role_id
               JOIN `permissions` p ON p.id = rp.permission_id
              ORDER BY r.`key`, p.`key`',
        )->getResultArray();

        $out = ['-- role_permissions: ' . count($grants) . ' grant(s), joined on the role and permission keys'];
        foreach ($grants as $grant) {
            $out[] = sprintf(
                "INSERT INTO `role_permissions` (`role_id`, `permission_id`)\n"
                . "SELECT r.id, p.id FROM `roles` r JOIN `permissions` p\n"
                . " WHERE r.`key` = '%s' AND p.`key` = '%s'\n"
                . "   AND NOT EXISTS (SELECT 1 FROM `role_permissions` x WHERE x.role_id = r.id AND x.permission_id = p.id);",
                $grant['role_key'],
                $grant['permission_key'],
            );
        }

        return implode("\n", $out) . "\n";
    }

    private function migrationsSection(\CodeIgniter\Database\BaseConnection $db): string
    {
        $rows = $db->table('migrations')->orderBy('id')->get()->getResultArray();

        $out = [
            "\n-- --- Record the migrations as applied ----------------------------------------\n"
            . "-- Without these rows `php spark polyfix:migrate` would try to apply the same\n"
            . "-- changes a second time.\n",
        ];

        foreach ($rows as $row) {
            $out[] = sprintf(
                "INSERT INTO `migrations` (`version`, `class`, `group`, `namespace`, `time`, `batch`)\n"
                . "SELECT '%s', '%s', '%s', '%s', UNIX_TIMESTAMP(), 1 FROM DUAL\n"
                . " WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `version` = '%s');",
                $row['version'],
                str_replace('\\', '\\\\', $row['class']),
                $row['group'],
                $row['namespace'],
                $row['version'],
            );
        }

        return implode("\n", $out) . "\n";
    }

    private function closing(): string
    {
        return <<<'SQL'

            -- --- What the database now holds ---------------------------------------------
            -- Printed so the result is visible without having to go looking for it.
            SELECT 'tables' AS `what`, COUNT(*) AS `count` FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            UNION ALL SELECT 'roles', COUNT(*) FROM `roles`
            UNION ALL SELECT 'permissions', COUNT(*) FROM `permissions`
            UNION ALL SELECT 'role grants', COUNT(*) FROM `role_permissions`
            UNION ALL SELECT 'settings', COUNT(*) FROM `system_settings`
            UNION ALL SELECT 'website pages', COUNT(*) FROM `website_pages`
            UNION ALL SELECT 'migrations applied', COUNT(*) FROM `migrations`
            UNION ALL SELECT 'users (untouched)', COUNT(*) FROM `users`
            UNION ALL SELECT 'dealers (untouched)', COUNT(*) FROM `dealers`
            UNION ALL SELECT 'mattresses (untouched)', COUNT(*) FROM `mattresses`
            UNION ALL SELECT 'sales (untouched)', COUNT(*) FROM `sales`
            UNION ALL SELECT 'warranties (untouched)', COUNT(*) FROM `warranties`
            UNION ALL SELECT 'claims (untouched)', COUNT(*) FROM `warranty_claims`
            UNION ALL SELECT 'audit entries (untouched)', COUNT(*) FROM `audit_logs`;

            -- Next:  php spark polyfix:doctor
            SQL;
    }
}

<?php

namespace App\Services\Admin;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Creates or updates the database for `php spark dgr:install` and the
 * browser setup page: all migrations (Shield, Settings, App) and the base
 * master data. On an installation that already holds data, pending changes
 * are applied only after a backup has been written and read back; if that
 * fails nothing is changed.
 */
final class InstallService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Migrations not yet applied (short class names). Read-only: unlike the
     * migration runner's history it never creates the migrations table.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $done = [];
        if ($this->db->tableExists('migrations', false)) {
            foreach ($this->db->table('migrations')->select('class')->get()->getResultArray() as $row) {
                $done[ltrim((string) $row['class'], '\\')] = true;
            }
        }
        $runner = service('migrations');
        $runner->setNamespace(null);
        $out = [];
        foreach ($runner->findMigrations() as $m) {
            if (! isset($done[ltrim((string) $m->class, '\\')])) {
                $out[] = substr((string) strrchr('\\' . $m->class, '\\'), 1);
            }
        }

        return $out;
    }

    /** An installation with business tables already in place (a fresh database needs no backup). */
    public function hasData(): bool
    {
        return $this->db->tableExists('company_settings', false) || $this->db->tableExists('users', false);
    }

    /**
     * @param callable(string): void|null $progress told about the backup before and after it is made
     *
     * @return array{applied: list<string>, backup: array{name: string, tables: int, rows: int}|null}
     *
     * @throws RuntimeException when the backup or a migration fails
     */
    public function run(bool $backup = true, ?callable $progress = null): array
    {
        $pending = $this->pending();
        $made    = null;
        if ($pending !== [] && $backup && $this->hasData()) {
            $progress && $progress('Backing up the database first…');
            try {
                $backups = new BackupService($this->db);
                [$name]  = $backups->create(false);
                $check   = $backups->verify($name);
            } catch (Throwable $e) {
                throw new RuntimeException('The backup could not be made or verified, so no database change was made: ' . $e->getMessage(), 0, $e);
            }
            $made = ['name' => $name, 'tables' => (int) $check['tables'], 'rows' => (int) $check['rows']];
            $progress && $progress("Backup verified: writable/backups/{$name} ({$made['tables']} tables, " . number_format($made['rows']) . ' rows). Keep a copy off the server.');
        }

        $runner = service('migrations');
        $runner->setNamespace(null);
        if (! $runner->latest()) {
            throw new RuntimeException('Migrations failed.');
        }
        $seeder = Database::seeder();
        $seeder->setSilent(true);
        $seeder->call('App\Database\Seeds\DatabaseSeeder');

        return ['applied' => $pending, 'backup' => $made];
    }
}

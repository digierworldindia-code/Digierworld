<?php

declare(strict_types=1);

namespace App\Database\Support;

use CodeIgniter\Database\Forge;

/**
 * Small helpers that keep migration definitions short and consistent.
 *
 * Every BearingCave table uses INT UNSIGNED primary keys so that foreign keys
 * line up with CodeIgniter Shield's `users.id` column.
 */
trait SchemaHelper
{
    protected function pk(): array
    {
        return ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true];
    }

    protected function ref(bool $nullable = false): array
    {
        return ['type' => 'INT', 'unsigned' => true, 'null' => $nullable];
    }

    protected function str(int $length = 191, bool $nullable = false, ?string $default = null): array
    {
        $f = ['type' => 'VARCHAR', 'constraint' => $length, 'null' => $nullable];
        if ($default !== null) {
            $f['default'] = $default;
        }

        return $f;
    }

    protected function text(bool $nullable = true): array
    {
        return ['type' => 'TEXT', 'null' => $nullable];
    }

    protected function json(): array
    {
        return ['type' => 'JSON', 'null' => true];
    }

    protected function dec(int $precision = 15, int $scale = 2, bool $nullable = true, ?string $default = null): array
    {
        $f = ['type' => 'DECIMAL', 'constraint' => "{$precision},{$scale}", 'null' => $nullable];
        if ($default !== null) {
            $f['default'] = $default;
        }

        return $f;
    }

    protected function int(bool $nullable = false, ?int $default = 0, bool $unsigned = true): array
    {
        $f = ['type' => 'INT', 'unsigned' => $unsigned, 'null' => $nullable];
        if ($default !== null) {
            $f['default'] = $default;
        }

        return $f;
    }

    protected function bool(int $default = 0): array
    {
        return ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => $default];
    }

    protected function enum(array $values, ?string $default = null, bool $nullable = false): array
    {
        $f = ['type' => 'ENUM', 'constraint' => $values, 'null' => $nullable];
        if ($default !== null) {
            $f['default'] = $default;
        }

        return $f;
    }

    protected function date(bool $nullable = true): array
    {
        return ['type' => 'DATE', 'null' => $nullable];
    }

    protected function dt(bool $nullable = true): array
    {
        return ['type' => 'DATETIME', 'null' => $nullable];
    }

    protected function timestamps(bool $softDeletes = false): array
    {
        $f = ['created_at' => $this->dt(), 'updated_at' => $this->dt()];
        if ($softDeletes) {
            $f['deleted_at'] = $this->dt();
        }

        return $f;
    }

    /**
     * Creates a table in one call.
     *
     * @param array<string,array> $fields
     * @param list<string|list<string>> $indexes  plain indexes
     * @param list<string|list<string>> $uniques  unique indexes
     * @param list<array{0:string,1:string,2?:string,3?:string}> $fks [column, table, onDelete, column(id)]
     */
    protected function table(string $name, array $fields, array $indexes = [], array $uniques = [], array $fks = []): void
    {
        /** @var Forge $forge */
        $forge = $this->forge;
        $forge->addField(['id' => $this->pk()] + $fields);
        $forge->addPrimaryKey('id');

        foreach ($indexes as $idx) {
            $forge->addKey($idx);
        }

        foreach ($uniques as $u) {
            $forge->addUniqueKey($u);
        }

        foreach ($fks as $fk) {
            [$col, $table] = $fk;
            $onDelete      = $fk[2] ?? 'RESTRICT';
            $forge->addForeignKey($col, $table, $fk[3] ?? 'id', 'CASCADE', $onDelete);
        }

        $forge->createTable($name, true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']);
    }
}

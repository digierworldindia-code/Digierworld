<?php

namespace Tests\Database;

use Tests\Support\DatabaseTestCase;

/**
 * The migrations must produce the reviewed reference schema
 * (database/schema/qms_schema.sql): same tables, columns in the same order,
 * same constraint names (FK, CHECK, UNIQUE) and the same triggers.
 *
 * @internal
 */
final class SchemaParityTest extends DatabaseTestCase
{
    /**
     * @return array{tables: array<string, list<string>>, constraints: list<string>, triggers: list<string>}
     */
    private function reference(): array
    {
        $sql = (string) file_get_contents(ROOTPATH . 'database/schema/qms_schema.sql');
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);
        preg_match_all('/CREATE TABLE (\w+) \((.*?)\n\) ENGINE/s', $sql, $tables, PREG_SET_ORDER);

        $out = ['tables' => [], 'constraints' => [], 'triggers' => []];
        foreach ($tables as [, $name, $body]) {
            $columns = [];
            foreach (preg_split('/\n/', $body) as $line) {
                if (preg_match('/^\s{2}(\w+)\s+(?!KEY\b)[A-Z]/', $line, $m) && ! in_array($m[1], ['PRIMARY', 'UNIQUE', 'KEY', 'CONSTRAINT', 'INDEX'], true)) {
                    $columns[] = $m[1];
                }
                if (preg_match('/^\s*(?:CONSTRAINT|UNIQUE KEY)\s+(\w+)/', $line, $m)) {
                    $out['constraints'][] = $name . '.' . $m[1];
                }
            }
            $out['tables'][$name] = $columns;
        }
        preg_match_all('/CREATE TRIGGER (\w+)/', $sql, $triggers);
        $out['triggers'] = $triggers[1];
        sort($out['constraints']);
        sort($out['triggers']);

        return $out;
    }

    public function testMigratedSchemaMatchesTheReferenceFile(): void
    {
        $ref    = $this->reference();
        $schema = $this->db->getDatabase();

        $tables = array_column($this->db->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' AND table_name <> 'migrations' ORDER BY table_name", [$schema])->getResultArray(), 't');
        $expected = array_keys($ref['tables']);
        sort($expected);
        $this->assertSame($expected, $tables, 'tables');

        foreach ($ref['tables'] as $table => $columns) {
            $actual = array_column($this->db->query('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$schema, $table])->getResultArray(), 'c');
            $this->assertSame($columns, $actual, "columns of {$table}");
        }

        $constraints = array_map(static fn (array $r): string => $r['t'] . '.' . $r['c'], $this->db->query(
            "SELECT table_name AS t, constraint_name AS c FROM information_schema.table_constraints
              WHERE table_schema = ? AND constraint_type <> 'PRIMARY KEY' AND table_name <> 'migrations'",
            [$schema],
        )->getResultArray());
        sort($constraints);
        $this->assertSame($ref['constraints'], $constraints, 'named constraints');

        $triggers = array_column($this->db->query('SELECT trigger_name AS t FROM information_schema.triggers WHERE trigger_schema = ? ORDER BY trigger_name', [$schema])->getResultArray(), 't');
        $this->assertSame($ref['triggers'], $triggers, 'triggers');
        $this->assertCount(27, $triggers);
    }

    public function testIndexesForReportingFiltersExist(): void
    {
        $indexed = array_column($this->db->query(
            "SELECT DISTINCT column_name AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = 'inspection_reports' AND seq_in_index = 1",
            [$this->db->getDatabase()],
        )->getResultArray(), 'c');
        foreach (['inspection_date', 'machine_id', 'part_id', 'operator_employee_id', 'status', 'template_id', 'report_type_id', 'shift_id'] as $column) {
            $this->assertContains($column, $indexed, "index on inspection_reports.{$column}");
        }
    }
}

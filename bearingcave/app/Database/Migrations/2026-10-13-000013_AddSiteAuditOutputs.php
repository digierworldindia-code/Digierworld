<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Workbook "Site Audit & Factory Assessment — Outputs: Audit score,
 * Corrective action requests, Approval recommendation". Additive/reversible.
 */
class AddSiteAuditOutputs extends Migration
{
    use SchemaHelper;

    private function columns(): array
    {
        return [
            'audit_score'        => $this->dec(5, 2, true),
            'corrective_actions' => $this->text(),
            'recommendation'     => $this->enum(['approve', 'approve_with_conditions', 'reject'], null, true),
        ];
    }

    private function hasColumn(string $column): bool
    {
        return $this->db->query('SHOW COLUMNS FROM `site_audits` LIKE ' . $this->db->escape($column))->getNumRows() > 0;
    }

    public function up(): void
    {
        $missing = array_filter($this->columns(), fn ($f, $name) => ! $this->hasColumn($name), ARRAY_FILTER_USE_BOTH);
        if ($missing) {
            $this->forge->addColumn('site_audits', $missing);
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->columns()) as $name) {
            if ($this->hasColumn($name)) {
                $this->forge->dropColumn('site_audits', $name);
            }
        }
    }
}

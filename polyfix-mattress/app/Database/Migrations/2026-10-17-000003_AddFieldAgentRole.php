<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Makes room for the FIELD_AGENT role.
 *
 * `roles`.`key` is an ENUM, so a role that is not one of its members cannot be
 * inserted at all: the seeder would fail and no agent could be created. The
 * column is widened to include it. Existing rows are unaffected — widening an
 * ENUM adds a member, it does not renumber the ones already there.
 *
 * The role row itself, its description and its grants are written by
 * `php spark db:seed ReferenceData`, which rebuilds them from App\Libraries\Rbac
 * and is safe to re-run. That is kept out of the migration on purpose: the
 * grants change as the panels are built, and the seeder is the one place that
 * applies them.
 */
class AddFieldAgentRole extends Migration
{
    private const ROLES = ['SUPER_ADMIN', 'ADMIN', 'WAREHOUSE', 'WARRANTY_MANAGER', 'SALES_MANAGER', 'FIELD_AGENT', 'DEALER', 'REPORTING'];

    public function up(): void
    {
        $this->setMembers(self::ROLES);
    }

    public function down(): void
    {
        // Refuse to narrow the column while the role is in use: dropping an
        // ENUM member silently blanks the rows that hold it, which would
        // detach those users from their permissions without a word.
        $inUse = (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM `roles` WHERE `key` = 'FIELD_AGENT'",
        )->getRow()->n;

        if ($inUse > 0) {
            return;
        }

        $this->setMembers(array_values(array_diff(self::ROLES, ['FIELD_AGENT'])));
    }

    /** @param list<string> $members */
    private function setMembers(array $members): void
    {
        $list = implode(', ', array_map(fn (string $m): string => $this->db->escape($m), $members));

        $this->db->query("ALTER TABLE `roles` MODIFY COLUMN `key` ENUM({$list}) NOT NULL");
    }
}

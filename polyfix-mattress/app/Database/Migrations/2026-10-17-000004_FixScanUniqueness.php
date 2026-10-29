<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Makes the scan uniqueness constraint actually unique.
 *
 * `warehouse_scans` was given UNIQUE (mattress_id, purpose, batch_id,
 * dispatch_id) so that a second scan of the same label would be refused by
 * the database rather than merely by the application. It does not do that.
 * In MySQL a UNIQUE index treats NULLs as distinct, and exactly one of those
 * two columns is always NULL: a production scan carries a batch and no
 * dispatch, a dispatch scan the other way round. Every row therefore has a
 * NULL in the key, so every row is unique to the index and the same mattress
 * could be counted into one batch any number of times.
 *
 * The fix is a stored column holding whichever context applies, and the
 * unique key on that. The CHECK is tightened at the same time so the
 * irrelevant column must be NULL — without that, a row could carry both and
 * there would be no single answer to which one is the context.
 *
 * Nothing is lost: the table holds only scans, and the tightened rules are
 * satisfied by every row the application has ever written.
 */
class FixScanUniqueness extends SchemaMigration
{
    public function up(): void
    {
        // Not a condition to tiptoe around: `warehouse_scans` is created by
        // the migration before this one, so if it is absent something is
        // wrong with the run and saying so beats reporting success. This
        // returned quietly once, and a fresh install went live with no
        // unique key and no sign that anything had been skipped.
        if (! $this->db->tableExists('warehouse_scans', false)) {
            throw new \RuntimeException(
                'warehouse_scans does not exist, so the duplicate-scan constraint cannot be added. '
                . 'Run the migrations in order, from an empty database or from the previous release.',
            );
        }

        // Any row that the tightened CHECK would reject has to go first, and
        // there should be none: the application has never written one.
        $bad = (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM `warehouse_scans`
             WHERE (`purpose` = 'PRODUCTION' AND (`batch_id` IS NULL OR `dispatch_id` IS NOT NULL))
                OR (`purpose` = 'DISPATCH'   AND (`dispatch_id` IS NULL OR `batch_id` IS NOT NULL))",
        )->getRow()->n;

        if ($bad > 0) {
            throw new \RuntimeException(
                "{$bad} warehouse_scans row(s) carry both a batch and a dispatch, or neither. "
                . 'Resolve them before tightening the constraint; a scan belongs to one context.',
            );
        }

        // mattress_id is the leftmost column of the old unique key, so that
        // key is what supports the foreign key to `mattresses` and MySQL
        // refuses to drop it. A plain index on the column takes over first.
        $this->addIndexIfMissing('warehouse_scans_mattress_idx', '`mattress_id`');

        $this->dropIfPresent('index', 'warehouse_scan_unique');
        $this->dropIfPresent('check', 'warehouse_scans_context_chk');

        $this->db->query(
            "ALTER TABLE `warehouse_scans` ADD CONSTRAINT `warehouse_scans_context_chk` CHECK (
                (`purpose` = 'PRODUCTION' AND `batch_id` IS NOT NULL AND `dispatch_id` IS NULL)
             OR (`purpose` = 'DISPATCH'   AND `dispatch_id` IS NOT NULL AND `batch_id` IS NULL))",
        );

        // Stored rather than virtual so the unique index is a plain index on
        // a real column, which is what the duplicate check leans on.
        if (! $this->db->fieldExists('context_id', 'warehouse_scans')) {
            $this->db->query(
                'ALTER TABLE `warehouse_scans` ADD COLUMN `context_id`
                 CHAR(36) CHARACTER SET ascii COLLATE ascii_bin
                 GENERATED ALWAYS AS (COALESCE(`batch_id`, `dispatch_id`)) STORED NOT NULL',
            );
        }

        $this->db->query(
            'ALTER TABLE `warehouse_scans`
             ADD UNIQUE KEY `warehouse_scan_unique` (`mattress_id`, `purpose`, `context_id`)',
        );

        $this->assertUnique();
    }

    public function down(): void
    {
        if (! $this->db->tableExists('warehouse_scans')) {
            return;
        }

        $this->dropIfPresent('index', 'warehouse_scan_unique');

        if ($this->db->fieldExists('context_id', 'warehouse_scans')) {
            $this->db->query('ALTER TABLE `warehouse_scans` DROP COLUMN `context_id`');
        }

        // The original key is restored as it was, ineffective NULLs and all,
        // because a rollback restores the previous schema rather than a
        // better one.
        $this->db->query(
            'ALTER TABLE `warehouse_scans`
             ADD UNIQUE KEY `warehouse_scan_unique` (`mattress_id`, `purpose`, `batch_id`, `dispatch_id`)',
        );

        $this->dropIfPresent('check', 'warehouse_scans_context_chk');
        $this->db->query(
            "ALTER TABLE `warehouse_scans` ADD CONSTRAINT `warehouse_scans_context_chk` CHECK (
                (`purpose` = 'PRODUCTION' AND `batch_id` IS NOT NULL)
             OR (`purpose` = 'DISPATCH'   AND `dispatch_id` IS NOT NULL))",
        );
    }

    private function addIndexIfMissing(string $name, string $columns): void
    {
        $present = (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['warehouse_scans', $name],
        )->getRow()->n;

        if ($present === 0) {
            $this->db->query("ALTER TABLE `warehouse_scans` ADD KEY `{$name}` ({$columns})");
        }
    }

    private function dropIfPresent(string $kind, string $name): void
    {
        $sql = $kind === 'index'
            ? 'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
            : 'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?';

        if ((int) $this->db->query($sql, ['warehouse_scans', $name])->getRow()->n === 0) {
            return;
        }

        $this->db->query($kind === 'index'
            ? "ALTER TABLE `warehouse_scans` DROP INDEX `{$name}`"
            : "ALTER TABLE `warehouse_scans` DROP CONSTRAINT `{$name}`");
    }

    /**
     * The whole point of this migration is a constraint that holds, so it is
     * checked rather than assumed. A scan path that silently lost its
     * backstop would double-count stock and nobody would find out until a
     * physical audit.
     */
    private function assertUnique(): void
    {
        $present = (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_scans'
               AND INDEX_NAME = 'warehouse_scan_unique' AND NON_UNIQUE = 0",
        )->getRow()->n;

        if ($present === 0) {
            throw new \RuntimeException(
                'warehouse_scans has no unique key. Scanning would double-count; restore it before use.',
            );
        }
    }
}

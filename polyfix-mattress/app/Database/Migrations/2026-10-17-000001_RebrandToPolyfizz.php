<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Corrects the company name in stored content, and widens the serial format.
 *
 * Two jobs, both additive:
 *
 *  1. Website copy, FAQs, SEO metadata and the company name setting carry the
 *     old spelling. They are live content, not history, so they are corrected.
 *     Sales, claims, warranties, dispatches, customer text and the audit trail
 *     are NOT touched: those record what happened, and rewriting them would
 *     falsify the record.
 *
 *  2. `mattresses_serial_format_chk` hard-coded `^CLF[0-9]{8,}$`. New
 *     production is numbered PFZ-YYYY-NNNNNN, which that constraint would
 *     reject outright. The check is widened to accept both, so serials already
 *     printed on mattresses in customers' homes stay valid and new ones can be
 *     issued. No existing serial is rewritten.
 *
 * down() restores the old constraint only if no PFZ serial exists yet, because
 * narrowing it under rows that violate it would fail anyway and leaving the
 * data unmatched to the schema is worse than refusing.
 */
class RebrandToPolyfizz extends SchemaMigration
{
    /** Content tables and their text columns. Deliberately no history tables. */
    private const CONTENT = [
        'website_pages'   => ['title', 'hero', 'sections'],
        'products'        => ['name', 'tagline', 'short_description', 'description'],
        'seo_metadata'    => ['title', 'description', 'og_title', 'og_description'],
        'faqs'            => ['question', 'answer'],
        'system_settings' => ['value'],
    ];

    public function up(): void
    {
        $this->correctContent();
        $this->renameWhyPage();
        $this->widenSerialFormat();
    }

    public function down(): void
    {
        // Content is not reverted: the old spelling was wrong, and a rollback
        // of the schema is not a reason to put a misspelling back on a
        // customer-facing website.
        $prefixes = $this->db->query(
            "SELECT COUNT(*) AS n FROM mattresses WHERE serial_number NOT REGEXP '^CLF[0-9]{8,}$'",
        )->getRow()->n;

        if ((int) $prefixes > 0) {
            // Narrowing would reject rows that already exist.
            return;
        }

        $this->db->query('ALTER TABLE `mattresses` DROP CONSTRAINT `mattresses_serial_format_chk`');
        $this->db->query(
            "ALTER TABLE `mattresses` ADD CONSTRAINT `mattresses_serial_format_chk` "
            . "CHECK (REGEXP_LIKE(`serial_number`, '^CLF[0-9]{8,}$', 'c'))",
        );
    }

    /**
     * Replaces the old spelling in stored content.
     *
     * Case by case rather than one lowercase pass, so "POLYFIX" in a heading
     * becomes "POLYFIZZ" and "Polyfix" in a sentence becomes "Polyfizz".
     */
    private function correctContent(): void
    {
        $pairs = [
            'POLYFIX MATTRESS' => 'POLYFIZZ',
            'Polyfix Mattress' => 'Polyfizz',
            'POLYFIX'          => 'POLYFIZZ',
            'Polyfix'          => 'Polyfizz',
            'polyfix'          => 'polyfizz',
        ];

        foreach (self::CONTENT as $table => $columns) {
            if (! $this->db->tableExists($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! $this->db->fieldExists($column, $table)) {
                    continue;
                }

                // Nested REPLACE() so one statement handles every spelling,
                // and only rows that actually contain one are rewritten.
                $expr = "`{$column}`";
                foreach ($pairs as $from => $to) {
                    $expr = "REPLACE({$expr}, " . $this->db->escape($from) . ', ' . $this->db->escape($to) . ')';
                }

                $this->db->query(
                    "UPDATE `{$table}` SET `{$column}` = {$expr} "
                    . "WHERE `{$column}` LIKE '%olyfix%' OR `{$column}` LIKE '%OLYFIX%'",
                );
            }
        }
    }

    /**
     * The "why POLYFIX" page is addressed by slug in the URL. The route now
     * serves why-polyfizz and redirects the old spellings, so the stored slug
     * has to follow or the page 404s.
     */
    private function renameWhyPage(): void
    {
        if (! $this->db->tableExists('website_pages')) {
            return;
        }

        $exists = $this->db->table('website_pages')->where('slug', 'why-polyfix')->countAllResults();
        if ($exists === 0) {
            return;
        }

        $this->db->table('website_pages')->where('slug', 'why-polyfix')->update(['slug' => 'why-polyfizz']);

        // SEO metadata points at the page by a composite key.
        if ($this->db->tableExists('seo_metadata')) {
            $this->db->query(
                "UPDATE `seo_metadata` SET `entity_key` = REPLACE(`entity_key`, 'why-polyfix', 'why-polyfizz') "
                . "WHERE `entity_key` LIKE '%why-polyfix%'",
            );
        }
    }

    /**
     * Accept PFZ-YYYY-NNNNNN alongside the legacy CLF numbering.
     *
     * MySQL 8 cannot alter a CHECK in place, so it is dropped and re-added.
     * Both halves run in one statement batch; if the ADD failed the table
     * would be left without the constraint, so it is verified afterwards and
     * the migration fails loudly rather than leaving the format unpoliced.
     */
    private function widenSerialFormat(): void
    {
        $name = 'mattresses_serial_format_chk';

        $present = $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['mattresses', $name],
        )->getRow()->n;

        if ((int) $present > 0) {
            $this->db->query("ALTER TABLE `mattresses` DROP CONSTRAINT `{$name}`");
        }

        $this->db->query(
            "ALTER TABLE `mattresses` ADD CONSTRAINT `{$name}` CHECK ("
            . "REGEXP_LIKE(`serial_number`, '^CLF[0-9]{8,}$', 'c') OR "
            . "REGEXP_LIKE(`serial_number`, '^PFZ-[0-9]{4}-[0-9]{6,}$', 'c')"
            . ')',
        );

        $after = $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['mattresses', $name],
        )->getRow()->n;

        if ((int) $after === 0) {
            throw new \RuntimeException(
                'The serial format constraint was dropped but not replaced. The mattresses table is '
                . 'now unpoliced; restore it before issuing any serial.',
            );
        }
    }
}

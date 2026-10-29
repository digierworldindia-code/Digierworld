<?php

namespace Tests\Unit;

use Tests\Support\PolyfixTestCase;

/**
 * What the migrations were supposed to leave behind.
 *
 * Written after a fresh install silently lost two of them. A CodeIgniter
 * connection caches the table list the first time anything asks, and
 * `tableExists()` reads that cache; the first migration primes it while the
 * database is still empty, so later migrations that guard their work with
 * `tableExists()` were told the tables did not exist and did nothing. The
 * runner reported all ten as applied. The database went live without the
 * unique key that stops a mattress being counted twice and without the column
 * that files an invoice against a review case — and upgrading an existing
 * database hid it, because there the tables already existed when the cache
 * was primed.
 *
 * So the schema is now asserted against directly. "The migration ran" is not
 * the same claim as "the migration did something", and only the second one
 * matters to the warehouse floor.
 *
 * @internal
 */
final class MigratedSchemaTest extends PolyfixTestCase
{
    /** Every table the application expects, by the migration that adds it. */
    public function testEveryTableTheApplicationNeedsIsPresent(): void
    {
        $tables = $this->db->listTables(false);

        foreach ([
            // core schema
            'users', 'roles', 'permissions', 'role_permissions', 'user_roles',
            'dealers', 'dealer_users', 'products', 'product_variants', 'mattresses',
            'manufacturing_batches', 'dispatches', 'dispatch_items',
            'sales', 'warranties', 'warranty_claims', 'claim_media', 'claim_events',
            'customers', 'audit_logs', 'identifier_counters',
            'system_settings', 'website_pages', 'seo_metadata', 'faqs',
            // framework
            'ci_sessions', 'migrations',
            // the agent survey, warehouse and review work
            'warehouses', 'warehouse_scans', 'dealer_receipts', 'dealer_receipt_items',
            'dealer_surveys', 'dealer_survey_items', 'agent_dealer_assignments',
            'inventory_discrepancies', 'warranty_review_cases', 'warranty_review_events',
        ] as $table) {
            $this->assertContains($table, $tables, "the {$table} table is missing from the migrated schema");
        }
    }

    /**
     * The constraint that stops the same label being counted twice. It is a
     * unique key over a stored generated column, because the obvious key —
     * (mattress_id, purpose, batch_id, dispatch_id) — does not work: one of
     * those two columns is always NULL and MySQL treats NULLs as distinct, so
     * every row was unique to the index and a mattress could be counted into
     * one batch any number of times.
     */
    public function testTheDuplicateScanConstraintIsInPlaceAndUnique(): void
    {
        $this->assertTrue(
            $this->db->fieldExists('context_id', 'warehouse_scans'),
            'warehouse_scans.context_id is missing, so the unique key has nothing to stand on',
        );

        $columns = $this->db->query(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_scans'
               AND INDEX_NAME = 'warehouse_scan_unique' AND NON_UNIQUE = 0
             ORDER BY SEQ_IN_INDEX",
        )->getResultArray();

        $this->assertSame(
            ['mattress_id', 'purpose', 'context_id'],
            array_column($columns, 'COLUMN_NAME'),
            'the scan key must be unique over the mattress, the purpose and the one context that applies',
        );
    }

    /** An invoice uploaded during a review has to be filed against the case. */
    public function testAnInvoiceCanBeFiledAgainstAReviewCase(): void
    {
        $this->assertTrue(
            $this->db->fieldExists('review_case_id', 'claim_media'),
            'claim_media.review_case_id is missing, so an invoice cannot be attached to a review',
        );

        $this->assertSame(
            1,
            (int) $this->db->query(
                "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND CONSTRAINT_NAME = 'claim_media_review_case_fk'",
            )->getRow()->n,
            'the foreign key to warranty_review_cases is missing',
        );
    }

    /** Added later than the rest, by widening an ENUM that had no room for it. */
    public function testTheFieldAgentRoleExists(): void
    {
        $this->assertSame(
            1,
            (int) $this->db->table('roles')->where('key', 'FIELD_AGENT')->countAllResults(),
            'the FIELD_AGENT role is missing, so no agent can be given an account',
        );
    }

    /**
     * Surveys and reviews are referred to by number — SRV-26-000001,
     * WRV-26-000001 — and those numbers come from a counter row. Without the
     * row the first survey of the year cannot be opened at all.
     */
    public function testTheIdentifierCountersForSurveysAndReviewsArePresent(): void
    {
        $names = array_column(
            $this->db->table('identifier_counters')->select('name')->get()->getResultArray(),
            'name',
        );

        foreach (['serial', 'batch', 'dispatch', 'claim', 'dealer', 'survey', 'warranty_review'] as $counter) {
            $this->assertContains($counter, $names, "the {$counter} counter is missing, so its next number cannot be issued");
        }

        foreach (['dealer_surveys', 'warranty_review_cases'] as $table) {
            $this->assertTrue(
                $this->db->fieldExists('reference', $table),
                "{$table} has no reference column, so its records cannot be identified by number",
            );
        }
    }

    /**
     * The guard that let this through. `tableExists()` answers from a cache
     * primed by the first migration, so the default is not safe to build a
     * migration on; the uncached form asks the database.
     */
    public function testTableExistsIsOnlyTrustworthyWhenItAsksTheDatabase(): void
    {
        $this->db->resetDataCache();

        // Prime the cache with a list that is missing a table, exactly as the
        // first migration primes it from an empty database.
        self::setPrivateProperty($this->db, 'dataCache', ['table_names' => ['users']]);

        $this->assertFalse(
            $this->db->tableExists('warehouse_scans'),
            'the cached form answers from the stale list — this is the trap',
        );
        $this->assertTrue(
            $this->db->tableExists('warehouse_scans', false),
            'the uncached form must ask the database',
        );

        $this->db->resetDataCache();
    }
}

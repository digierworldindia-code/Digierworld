<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Counters for the two references the survey programme issues.
 *
 *   survey          SRV-26-000001   one dealer visit
 *   warranty_review WRV-26-000001   one recommendation that a warranty be
 *                                   reviewed for rejection
 *
 * They are sequences, not dates, because both are quoted at people — an
 * agent reads a reference down the phone to the office, and a dealer sees it
 * beside a decision about their warranty.
 *
 * INSERT IGNORE, so a re-run never resets a counter that has already issued
 * references.
 */
class SurveyIdentifierCounters extends SchemaMigration
{
    public function up(): void
    {
        $this->db->query(
            "INSERT IGNORE INTO `identifier_counters` (`name`, `value`) VALUES ('survey', 0), ('warranty_review', 0)",
        );
    }

    public function down(): void
    {
        // Only if nothing has been issued. Dropping a counter that has
        // handed out references would let the next install repeat them.
        $this->db->query(
            "DELETE FROM `identifier_counters` WHERE `name` IN ('survey', 'warranty_review') AND `value` = 0",
        );
    }
}

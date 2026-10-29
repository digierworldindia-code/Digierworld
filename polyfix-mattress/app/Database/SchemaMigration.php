<?php

namespace App\Database;

use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;

/**
 * The base class for this application's migrations.
 *
 * It exists for one reason. A CodeIgniter connection caches the list of
 * tables the first time anything asks for it, and `tableExists()` answers
 * from that cache by default. The first migration calls `listTables()` while
 * the database is still empty, so the cache is primed with nothing, and every
 * later migration in the same process is told that tables plainly created a
 * moment earlier do not exist.
 *
 * On a fresh install that was silent and serious: two migrations guard their
 * work with `tableExists()` and so did nothing at all. The database went live
 * without the unique key that stops the same mattress being counted twice,
 * and without the column that files an invoice against a review case. Both
 * were reported as applied. Upgrading an existing database hid it, because
 * there the tables already existed when the cache was primed.
 *
 * Clearing the cache here, once per migration, costs one query per probe and
 * means a migration can trust what the database tells it. Every migration in
 * this application extends this class; `MigrationRunner` constructs each one
 * immediately before calling `up()` or `down()`, so the reset always lands
 * in front of the work.
 */
abstract class SchemaMigration extends Migration
{
    public function __construct(?Forge $forge = null)
    {
        parent::__construct($forge);

        $this->db->resetDataCache();
    }
}

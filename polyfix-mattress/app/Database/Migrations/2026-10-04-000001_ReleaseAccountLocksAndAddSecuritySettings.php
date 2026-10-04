<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Frees accounts held by the old lock, and registers the two-factor switch.
 *
 * No column is added, changed or dropped. This only touches two rows' worth of
 * state that the application no longer maintains:
 *
 *  1. `users.locked_until` / `failed_login_count`. A wrong password no longer
 *     locks an account, so a value left in either column is stale. Anyone
 *     locked out when this is deployed can sign in again straight away; the
 *     columns themselves stay, because older backups carry them and because
 *     the failure count is still worth reading on an account's own page.
 *
 *  2. `system_settings` gains `security.two_factor_enabled`, off. An existing
 *     installation would otherwise have no row for it, and Settings → Security
 *     would be an empty section. Inserted only if absent, so a site that has
 *     already switched it on keeps its choice.
 *
 * down() puts the setting row back the way it found it and leaves the lock
 * columns alone: re-locking accounts on a rollback would be its own outage.
 */
class ReleaseAccountLocksAndAddSecuritySettings extends Migration
{
    private const KEY = 'security.two_factor_enabled';

    public function up(): void
    {
        $locked = $this->db->table('users')
            ->groupStart()->where('locked_until IS NOT NULL')->orWhere('failed_login_count >', 0)->groupEnd()
            ->countAllResults();

        if ($locked > 0) {
            $this->db->table('users')
                ->groupStart()->where('locked_until IS NOT NULL')->orWhere('failed_login_count >', 0)->groupEnd()
                ->update(['locked_until' => null, 'failed_login_count' => 0]);

            log_message('notice', 'migration.ACCOUNT_LOCKS_RELEASED count={n}', ['n' => $locked]);
        }

        if ($this->db->table('system_settings')->where('key', self::KEY)->countAllResults() === 0) {
            $this->db->table('system_settings')->insert([
                'key'         => self::KEY,
                'value'       => 'false',
                'category'    => 'security',
                'description' => 'Offer two-factor authentication to staff and dealers. Off means a password is all '
                    . 'that is asked for. Nobody is ever forced to enrol, and anyone who turns it on can turn it off again.',
                'is_secret'   => 0,
                'updated_at'  => gmdate('Y-m-d H:i:s') . '.000000',
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('system_settings')->where('key', self::KEY)->delete();
    }
}

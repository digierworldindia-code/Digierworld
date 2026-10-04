<?php

namespace App\Commands;

use App\Libraries\Settings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The way back in when nobody can sign in.
 *
 *   php spark polyfix:unlock                          clear every stale lock
 *   php spark polyfix:unlock --email you@example.com   one account
 *   php spark polyfix:unlock --clear-2fa --email …     also clear its two-factor
 *   php spark polyfix:unlock --two-factor-off          switch the feature off
 *
 * A wrong password no longer locks an account, so the first form is mostly
 * tidying. The others matter: if the only administrator turns two-factor on
 * and then loses the phone and the recovery codes, every route into the
 * console asks for a code, including the Settings page that would switch it
 * off. This command is the documented way out, and it is why that situation is
 * no longer a dead end.
 *
 * It never changes or prints a password.
 */
class Unlock extends BaseCommand
{
    protected $group       = 'POLYFIX';
    protected $name        = 'polyfix:unlock';
    protected $description = 'Clear stale sign-in locks, clear an account\'s two-factor, or switch two-factor off.';
    protected $usage       = 'polyfix:unlock [--email x] [--clear-2fa] [--two-factor-off]';
    protected $options     = [
        '--email'           => 'Limit to one account, by email address',
        '--clear-2fa'       => "Also clear that account's two-factor enrolment",
        '--two-factor-off'  => 'Switch two-factor off for the whole installation',
    ];

    public function run(array $params)
    {
        helper('brand');
        $db      = db_connect();
        $email   = CLI::getOption('email');
        $clear2fa = CLI::getOption('clear-2fa') !== null || isset($params['clear-2fa']);
        $featureOff = CLI::getOption('two-factor-off') !== null || isset($params['two-factor-off']);

        if ($featureOff) {
            $db->table('system_settings')->where('key', 'security.two_factor_enabled')->update([
                'value'      => 'false',
                'updated_at' => gmdate('Y-m-d H:i:s') . '.000000',
            ]);
            Settings::forget();
            CLI::write('Two-factor authentication is switched off for this installation.', 'green');
            CLI::write('Everyone signs in with an email address and a password. Enrolled accounts keep their');
            CLI::write('enrolment on file, unused, in case it is switched on again.');
            CLI::newLine();
        }

        // The lock columns are no longer written, so this only clears what an
        // earlier build left behind.
        $builder = $db->table('users')
            ->groupStart()->where('locked_until IS NOT NULL')->orWhere('failed_login_count >', 0)->groupEnd();

        if (is_string($email) && $email !== '') {
            $id = $this->findByEmail($db, $email);
            if ($id === null) {
                CLI::error('No account with that email address.');

                return EXIT_ERROR;
            }
            $builder->where('id', $id);
        }

        $cleared = $builder->update(['locked_until' => null, 'failed_login_count' => 0])
            ? $db->affectedRows()
            : 0;

        CLI::write($cleared > 0
            ? sprintf('Cleared a stale lock or failure count on %d account(s).', $cleared)
            : 'No account was holding a stale lock.', $cleared > 0 ? 'green' : 'yellow');

        if ($clear2fa) {
            if (! is_string($email) || $email === '') {
                CLI::error('--clear-2fa needs --email, so it cannot clear everyone by accident.');

                return EXIT_ERROR;
            }
            $id = $this->findByEmail($db, $email);
            $db->table('users')->where('id', $id)->update([
                'mfa_enabled'        => 0,
                'mfa_secret_encrypted' => null,
                'mfa_recovery_codes' => null,
                'mfa_enrolled_at'    => null,
            ]);
            CLI::write('Two-factor cleared for that account. It signs in with its password alone.', 'green');
        }

        CLI::newLine();
        CLI::write('Nothing else was changed, and no password was touched.');

        return EXIT_SUCCESS;
    }

    /** A staff or dealer login is found by its address, which is stored lowercase. */
    private function findByEmail($db, string $email): ?string
    {
        $row = $db->table('users')
            ->select('id')
            ->where('email', strtolower(trim($email)))
            ->where('deleted_at', null)
            ->get()->getRowArray();

        return $row['id'] ?? null;
    }
}

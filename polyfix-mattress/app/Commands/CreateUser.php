<?php

namespace App\Commands;

use App\Exceptions\AppException;
use App\Libraries\Rbac;
use App\Services\UserService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Creates an account from the command line — the way to make the first
 * administrator on a new installation.
 *
 *   php spark polyfix:create-user --email owner@example.com --name "Full Name" --role SUPER_ADMIN
 *   php spark polyfix:create-user --email shop@example.com --name "Shop Owner" --role DEALER --dealer DLR0001
 *   php spark polyfix:create-user ... --demo        (marks the account DEMO ONLY)
 *
 * There is no default or universal password. A random temporary password is
 * printed once; the holder must replace it at first sign-in, and roles that
 * require two-factor authentication must enrol before reaching anything else.
 */
class CreateUser extends BaseCommand
{
    protected $group       = 'POLYFIX';
    protected $name        = 'polyfix:create-user';
    protected $description = 'Create a user with a one-time temporary password (use for the first administrator).';
    protected $usage       = 'polyfix:create-user --email <email> --name <full name> --role <ROLE> [--dealer <code>] [--demo]';
    protected $options     = [
        '--email'  => 'Sign-in email address',
        '--name'   => 'Full name',
        '--role'   => 'One of: ' . 'SUPER_ADMIN, ADMIN, WAREHOUSE, WARRANTY_MANAGER, SALES_MANAGER, REPORTING, DEALER',
        '--dealer' => 'Dealer code (for a DEALER login)',
        '--demo'   => 'Mark the account DEMO ONLY (never on a production system)',
    ];

    public function run(array $params)
    {
        $email = (string) (CLI::getOption('email') ?? CLI::prompt('Email'));
        $name  = (string) (CLI::getOption('name') ?? CLI::prompt('Full name'));
        $role  = strtoupper((string) (CLI::getOption('role') ?? CLI::prompt('Role', Rbac::ROLE_KEYS)));
        $demo  = CLI::getOption('demo') !== null;

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || trim($name) === '' || ! in_array($role, Rbac::ROLE_KEYS, true)) {
            CLI::error('A valid --email, --name and --role are required.');

            return EXIT_ERROR;
        }
        if ($demo && ENVIRONMENT === 'production') {
            CLI::error('Refusing to create a DEMO ONLY account with CI_ENVIRONMENT=production.');

            return EXIT_ERROR;
        }

        $dealerId = null;
        if ($role === 'DEALER') {
            $code   = (string) (CLI::getOption('dealer') ?? CLI::prompt('Dealer code'));
            $dealer = db_connect()->table('dealers')->select('id')->where(['code' => strtoupper($code), 'deleted_at' => null])->get()->getRow();
            if ($dealer === null) {
                CLI::error("No dealer with code {$code}.");

                return EXIT_ERROR;
            }
            $dealerId = $dealer->id;
        }

        try {
            $created = UserService::instance()->create([
                'email'     => $email,
                'full_name' => $demo ? trim($name) . ' [DEMO ONLY]' : trim($name),
                'roles'     => [$role],
                'dealer_id' => $dealerId,
            ], null);
        } catch (AppException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('');
        CLI::write('Account created: ' . $created['email'] . ' (' . Rbac::roleName($role) . ')', 'green');
        if ($demo) {
            CLI::write('DEMO ONLY — this account is for demonstration and must not exist on a production system.', 'yellow');
        }
        CLI::write('Temporary password (shown once, not stored readable):');
        CLI::write('    ' . $created['temporary_password'], 'yellow');
        CLI::write('Hand it over in person or by phone. It must be changed at first sign-in.');
        if (in_array($role, config('Polyfix')->mfaRoles(), true)) {
            CLI::write('This role requires two-factor authentication: have an authenticator app ready.');
        }

        return EXIT_SUCCESS;
    }
}

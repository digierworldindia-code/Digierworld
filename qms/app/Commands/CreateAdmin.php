<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Creates the first Super Admin. There are no default credentials anywhere.
 *
 *   php spark qms:create-admin                       (interactive, hidden password prompt)
 *   php spark qms:create-admin --username admin --generate   (prints a one-time password)
 *   QMS_ADMIN_PASSWORD=... php spark qms:create-admin --username admin --password-from-env
 *
 * The account must change its password at first login.
 */
class CreateAdmin extends BaseCommand
{
    protected $group       = 'QMS';
    protected $name        = 'qms:create-admin';
    protected $description = 'Creates a Super Admin account (must change password at first login).';
    protected $usage       = 'qms:create-admin [--username admin] [--name "Full Name"] [--email a@b.c] [--generate|--password-from-env]';
    protected $options     = [
        '--username'          => 'Login name (default: admin)',
        '--name'              => 'Display name (default: QMS Administrator)',
        '--email'             => 'E-mail address (optional)',
        '--generate'          => 'Generate a random password and print it once',
        '--password-from-env' => 'Read the password from the QMS_ADMIN_PASSWORD environment variable',
    ];

    public function run(array $params)
    {
        $db       = db_connect();
        $policy   = service('passwordPolicy');
        $username = (string) (CLI::getOption('username') ?? 'admin');
        $email    = CLI::getOption('email');
        $email    = is_string($email) && $email !== '' ? $email : null;

        if (! preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            CLI::error('Username must be 3-50 characters: letters, digits, dot, dash, underscore.');

            return EXIT_ERROR;
        }
        if ($db->table('users')->where('username', $username)->countAllResults() > 0) {
            CLI::error("User '{$username}' already exists.");

            return EXIT_ERROR;
        }

        $role = $db->table('roles')->where('code', 'SUPER_ADMIN')->get(1)->getRowArray();
        if ($role === null) {
            CLI::error('Reference data missing. Run: php spark db:seed ReferenceDataSeeder');

            return EXIT_ERROR;
        }

        $generated = false;
        if (CLI::getOption('generate') !== null) {
            $password  = $policy->generate(16);
            $generated = true;
        } elseif (CLI::getOption('password-from-env') !== null) {
            $password = (string) getenv('QMS_ADMIN_PASSWORD');
        } else {
            $password = $this->askHidden('Password for ' . $username);
            if ($password !== $this->askHidden('Repeat password')) {
                CLI::error('Passwords do not match.');

                return EXIT_ERROR;
            }
        }

        $problems = $policy->check($password, ['username' => $username]);
        if ($problems !== []) {
            CLI::error('Password rejected: ' . implode(' ', $problems));

            return EXIT_ERROR;
        }

        $now = gmdate('Y-m-d H:i:s');
        try {
            $db->table('users')->insert([
                'employee_id'          => null,
                'role_id'              => $role['id'],
                'username'             => $username,
                'email'                => $email,
                'password_hash'        => $policy->hash($password),
                'status'               => 'ACTIVE',
                'must_change_password' => 1,
                'security_stamp'       => bin2hex(random_bytes(16)),
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
            $userId = (int) $db->insertID();
            $audit  = service('audit');
            $audit->setActor(null);
            $audit->log('CREATE', 'user', $userId, null, ['username' => $username, 'role' => 'SUPER_ADMIN', 'via' => 'qms:create-admin'], $username);
        } catch (Throwable $e) {
            CLI::error('Could not create the account: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("Super Admin '{$username}' created. The password must be changed at first login.", 'green');
        if ($generated) {
            CLI::write('One-time password (shown only now): ' . $password, 'yellow');
        }

        return EXIT_SUCCESS;
    }

    private function askHidden(string $label): string
    {
        CLI::print($label . ': ');
        if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && posix_isatty(STDIN)) {
            shell_exec('stty -echo');
            $value = trim((string) fgets(STDIN));
            shell_exec('stty echo');
            CLI::newLine();

            return $value;
        }

        return trim((string) fgets(STDIN));
    }
}

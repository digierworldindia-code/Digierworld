<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;
use Config\AuthGroups;

/**
 * Creates the first super admin (or any staff user) from the command line.
 *
 *   php spark bearingcave:create-admin admin@example.com
 *   php spark bearingcave:create-admin ops@example.com --group verification_officer
 */
class CreateAdmin extends BaseCommand
{
    protected $group       = 'BearingCave';
    protected $name        = 'bearingcave:create-admin';
    protected $description = 'Create a BearingCave super admin or staff account.';
    protected $usage       = 'bearingcave:create-admin <email> [--group superadmin] [--password <password>]';
    protected $arguments   = ['email' => 'Login email address'];
    protected $options     = ['--group' => 'Staff group (default superadmin)', '--password' => 'Password (prompted securely if omitted)'];

    public function run(array $params)
    {
        $email = strtolower(trim((string) ($params[0] ?? CLI::prompt('Email'))));
        $group = (string) (CLI::getOption('group') ?: 'superadmin');
        $cfg   = config(AuthGroups::class);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            CLI::error('Invalid email.');

            return EXIT_ERROR;
        }
        if (! in_array($group, $cfg->staffGroups, true)) {
            CLI::error('Group must be one of: ' . implode(', ', $cfg->staffGroups));

            return EXIT_ERROR;
        }
        $users = auth()->getProvider();
        if ($users->findByCredentials(['email' => $email])) {
            CLI::error('A user with this email already exists.');

            return EXIT_ERROR;
        }
        $password = (string) (CLI::getOption('password') ?: '');
        if ($password === '') {
            $password = $this->promptHidden('Password (min 10 chars)');
            if ($password !== $this->promptHidden('Repeat password')) {
                CLI::error('Passwords do not match.');

                return EXIT_ERROR;
            }
        }
        $check = service('passwords')->check($password, new User(['email' => $email]));
        if (! $check->isOK()) {
            CLI::error('Weak password: ' . $check->reason());

            return EXIT_ERROR;
        }
        $user = new User(['username' => null, 'email' => $email, 'password' => $password]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup($group);
        service('audit')->log('staff.created_cli', ['entity_type' => 'user', 'entity_id' => (int) $user->id, 'description' => $group, 'severity' => 'warning']);
        CLI::write("Created {$group} account for {$email} (user #{$user->id}).", 'green');

        return EXIT_SUCCESS;
    }

    private function promptHidden(string $label): string
    {
        if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && stream_isatty(STDIN)) {
            echo $label . ': ';
            shell_exec('stty -echo');
            $v = rtrim((string) fgets(STDIN), "\r\n");
            shell_exec('stty echo');
            echo PHP_EOL;

            return $v;
        }

        return (string) CLI::prompt($label);
    }
}

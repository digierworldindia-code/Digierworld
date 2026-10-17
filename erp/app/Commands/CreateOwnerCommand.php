<?php

namespace App\Commands;

use App\Exceptions\BusinessRuleException;
use App\Services\Security\UserService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark dgr:create-owner --email owner@example.com --name "Owner" [--password ...]
 * Creates an Owner/Super Admin. If no password is given, a random one is
 * generated, printed once, and must be changed at first sign-in.
 */
class CreateOwnerCommand extends BaseCommand
{
    protected $group       = 'DGR Office';
    protected $name        = 'dgr:create-owner';
    protected $description = 'Create an Owner / Super Admin user.';
    protected $usage       = 'dgr:create-owner --email <email> --name <full name> [--password <password>]';
    protected $options     = [
        '--email'    => 'Owner email (login id)',
        '--name'     => 'Full name',
        '--password' => 'Password (min 10 chars, letters+numbers). Omit to generate one.',
    ];

    public function run(array $params)
    {
        $email = (string) (CLI::getOption('email') ?? CLI::prompt('Owner email', null, 'required|valid_email'));
        $name  = (string) (CLI::getOption('name') ?? CLI::prompt('Full name', null, 'required'));
        $pass  = CLI::getOption('password');
        $generated = false;
        if (! is_string($pass) || $pass === '') {
            $pass      = UserService::randomPassword();
            $generated = true;
        }

        service('audit')->asActor('system');

        try {
            $id = (new UserService(db_connect()))->create($email, $name, $pass, ['super_admin'], ['must_change_password' => $generated ? 1 : 0]);
        } catch (BusinessRuleException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("Owner created (user #{$id}): {$email}", 'green');
        if ($generated) {
            CLI::write('Temporary password (shown once, change at first login): ' . $pass, 'yellow');
        }

        return EXIT_SUCCESS;
    }
}

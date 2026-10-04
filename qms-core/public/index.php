<?php

declare(strict_types=1);

/*
 * Front controller: every request that is not a file under public/ ends here.
 * The web server's document root must be this public/ folder.
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/app/bootstrap.php';

// First run: the setup page creates .env, the database tables and the admin account.
if (! is_file(ROOTPATH . '.env') || ! is_file(WRITEPATH . 'installed.lock')) {
    (new App\Core\Installer())->web();

    return;
}

(new App\Core\App())->run();

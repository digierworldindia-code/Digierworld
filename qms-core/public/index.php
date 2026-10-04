<?php

declare(strict_types=1);

/*
 * Front controller: every request that is not a file under public/ ends here.
 * The web server's document root should be this public/ folder.
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Folder that contains app/, storage/ and .env. Change this line only when you
// copied the contents of public/ into another folder (for example public_html)
// and kept the rest of QMS outside it, e.g.  $qmsRoot = '/home/USER/qms';
$qmsRoot = dirname(__DIR__);

require $qmsRoot . '/app/bootstrap.php';

// First run: the setup page creates .env, the database tables and the admin account.
if (! is_file(ROOTPATH . '.env') || ! is_file(WRITEPATH . 'installed.lock')) {
    (new App\Core\Installer())->web();

    return;
}

(new App\Core\App())->run();

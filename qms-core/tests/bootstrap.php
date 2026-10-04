<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap: the application in "testing" mode. Database tests use the
 * TEST_DB_* settings from .env (a database whose name contains "test").
 */

define('ENVIRONMENT', 'testing');

require dirname(__DIR__) . '/app/bootstrap.php';

restore_error_handler();      // PHPUnit reports warnings and notices itself
restore_exception_handler();

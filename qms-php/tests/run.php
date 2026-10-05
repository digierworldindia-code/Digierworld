<?php
/**
 * Runs all tests against an EMPTY test database (it is reset on every run):
 *
 *   QMS_TEST_DB_NAME=qms_test QMS_TEST_DB_USER=qms_test QMS_TEST_DB_PASS=... php tests/run.php
 *
 * Exit code 0 = all passed. Never point it at the production database
 * (the name must contain "test").
 */
require __DIR__ . '/bootstrap.php';

$started = microtime(true);
t_reset_database();
t_say('QMS tests on database ' . db_value('SELECT DATABASE()') . ' (MySQL ' . db_value('SELECT VERSION()') . ')');

foreach (glob(__DIR__ . '/test_*.php') ?: [] as $file) {
    t_run_file($file);
}

$r = $GLOBALS['t_results'];
t_say(sprintf("\n%d passed, %d failed in %.1f s", $r['passed'], $r['failed'], microtime(true) - $started));
foreach ($r['failures'] as $failure) {
    t_say("  - {$failure}");
}
exit($r['failed'] === 0 ? 0 : 1);

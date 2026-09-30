<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Polyfix;
use Throwable;

/**
 * Checks a deployment and says, in order, what is wrong with it.
 *
 *   php spark polyfix:doctor
 *
 * Written after a deployment answered HTTP 500 on every request with no way to
 * tell why: the generic error page is the same whether a secret is missing,
 * MySQL is refusing the password or writable/ is read-only. This separates
 * those cases. It reads; it changes nothing, and it never prints a password,
 * a key, or anything else worth stealing.
 *
 * Exits non-zero if anything failed, so it can be used in a deploy script.
 */
class Doctor extends BaseCommand
{
    protected $group       = 'POLYFIX';
    protected $name        = 'polyfix:doctor';
    protected $description = 'Check PHP, permissions, secrets, the database and the migrations, and report what fails.';
    protected $usage       = 'polyfix:doctor';

    private int $failed = 0;
    private int $warned = 0;

    public function run(array $params)
    {
        helper('brand');
        CLI::write(brand('name') . ' — deployment check', 'white');
        CLI::newLine();

        $this->checkPhp();
        $this->checkExtensions();
        $this->checkEnvFile();
        $this->checkWritable();
        $this->checkSecrets();
        $this->checkBaseUrl();
        $this->checkDatabase();

        CLI::newLine();
        if ($this->failed > 0) {
            // Not every failure stops the site booting — a baseURL pointing at
            // this machine serves perfectly well, it just serves the wrong URLs
            // to everyone else. Claiming otherwise would be its own small lie.
            CLI::write(sprintf('%d check(s) failed. Put them right before this goes live.', $this->failed), 'red');
            CLI::write('docs/HOSTINGER-DEPLOYMENT.md explains each one.');

            return EXIT_ERROR;
        }

        CLI::write(
            $this->warned > 0
                ? sprintf('All essential checks passed, with %d warning(s) above.', $this->warned)
                : 'All checks passed.',
            $this->warned > 0 ? 'yellow' : 'green',
        );

        return EXIT_SUCCESS;
    }

    // --- checks ---------------------------------------------------------------

    private function checkPhp(): void
    {
        version_compare(PHP_VERSION, Polyfix::MINIMUM_PHP, '>=')
            ? $this->pass('PHP ' . PHP_VERSION)
            : $this->fail(sprintf(
                'PHP %s — this application needs %s or newer. Change it in your hosting control panel.',
                PHP_VERSION,
                Polyfix::MINIMUM_PHP,
            ));
    }

    private function checkExtensions(): void
    {
        $missing = array_values(array_filter(
            Polyfix::REQUIRED_EXTENSIONS,
            static fn (string $ext): bool => ! extension_loaded($ext),
        ));

        $missing === []
            ? $this->pass('PHP extensions: ' . implode(', ', Polyfix::REQUIRED_EXTENSIONS))
            : $this->fail('PHP extensions switched off: ' . implode(', ', $missing) . ' — enable them in your hosting control panel.');
    }

    private function checkEnvFile(): void
    {
        $path = ROOTPATH . '.env';

        if (! is_file($path)) {
            $this->fail('No .env file. Copy .env.example to .env, then run: php spark polyfix:keys --write');

            return;
        }

        $this->pass('.env is present');

        $mode = @fileperms($path);
        if ($mode !== false && ($mode & 0o044) !== 0) {
            $this->warn('.env is readable by other accounts on this server. Tighten it: chmod 600 .env');
        }

        if (ENVIRONMENT !== 'production') {
            $this->warn(sprintf(
                'CI_ENVIRONMENT is "%s". Set it to production on a live site, or visitors can be shown stack traces.',
                ENVIRONMENT,
            ));
        } else {
            $this->pass('CI_ENVIRONMENT is production');
        }
    }

    private function checkWritable(): void
    {
        foreach (['', 'cache/', 'logs/', 'session/', 'uploads/'] as $sub) {
            $dir   = WRITEPATH . $sub;
            $label = 'writable/' . $sub;

            if (! is_dir($dir)) {
                $this->fail($label . ' does not exist.');
                continue;
            }

            is_writable($dir)
                ? $this->pass($label . ' is writable')
                : $this->fail($label . ' is not writable by the web server. Set it to 775 (not 777).');
        }
    }

    private function checkSecrets(): void
    {
        $problems = config(Polyfix::class)->problems();

        if ($problems === []) {
            $this->pass('Secrets are set');

            return;
        }

        foreach ($problems as $problem) {
            $this->fail($problem);
        }
        CLI::write('           Fix with: php spark polyfix:keys --write', 'yellow');
    }

    private function checkBaseUrl(): void
    {
        $base = (string) config('App')->baseURL;

        if ($base === '') {
            $this->fail('app.baseURL is empty. Set it in .env to your own address, e.g. https://your-domain.com/');

            return;
        }

        if (! str_ends_with($base, '/')) {
            $this->warn('app.baseURL should end with a trailing slash: ' . $base);
        }

        $host = (string) parse_url($base, PHP_URL_HOST);

        // A development address left in .env is the classic cause of a site
        // that loads but whose CSS, forms and QR codes all point elsewhere.
        $local     = ['localhost', '127.0.0.1', '::1'];
        $isLocal   = in_array($host, $local, true) || str_ends_with($host, '.localhost');
        $isTemp    = preg_match('/(ngrok|loca\.lt|trycloudflare|gitpod|codespaces|repl\.co|e2b\.dev|daytona)/i', $host) === 1;

        // Right for a laptop, wrong for a live site — so which one it is
        // depends entirely on the environment.
        if ($isLocal || $isTemp) {
            $note = $isLocal
                ? 'app.baseURL points at ' . $host . ', which only works on this machine.'
                : 'app.baseURL points at a temporary development address (' . $host . ').';

            ENVIRONMENT === 'production'
                ? $this->fail($note . ' Set it in .env to the address the public will use.')
                : $this->pass('app.baseURL is ' . $base . ' (fine outside production)');

            return;
        }

        str_starts_with($base, 'https://')
            ? $this->pass('app.baseURL is ' . $base)
            : $this->warn('app.baseURL is not https. Turn on SSL and use https, or sessions and cookies are exposed: ' . $base);
    }

    private function checkDatabase(): void
    {
        try {
            $db = db_connect((new Database())->defaultGroup, false);
            $db->initialize();
        } catch (Throwable $e) {
            // Name the setting to check, not the host, user or driver message.
            $this->fail('The database refused the connection. Check database.default.hostname, .database, .username and .password in .env.');
            CLI::write('           MySQL said: ' . $this->safeDbMessage($e), 'yellow');

            return;
        }

        $this->pass('Database connection works');

        try {
            $version = $db->getVersion();
            $this->pass('MySQL ' . $version);
            if (version_compare($version, '8.0', '<') && stripos($version, 'mariadb') === false) {
                $this->warn('This application is tested on MySQL 8.0 and newer.');
            }
        } catch (Throwable) {
            $this->warn('Could not read the MySQL version.');
        }

        // Tables the application cannot run a single request without.
        foreach (['users', 'roles', 'permissions', 'ci_sessions', 'system_settings'] as $table) {
            $db->tableExists($table)
                ? $this->pass('table ' . $table)
                : $this->fail('table ' . $table . ' is missing. Load POLYFIX-MATTRESS-DATABASE.sql, or run: php spark polyfix:migrate');
        }

        // The application's own database account is deliberately not granted
        // anything on `migrations` — migrating is the owner account's job. So a
        // failed read here is the correct, restricted setup, not a problem, and
        // must never turn into "run polyfix:migrate" advice.
        if ($db->tableExists('migrations')) {
            try {
                $applied = (int) $db->table('migrations')->countAllResults();
                $onDisk  = count(service('migrations')->setSilent(true)->findMigrations());

                $applied >= $onDisk
                    ? $this->pass(sprintf('migrations: all %d applied', $onDisk))
                    : $this->warn(sprintf(
                        'migrations: %d of %d applied. Run as the owner account: php spark polyfix:migrate --owner-user polyfix_owner',
                        $applied,
                        $onDisk,
                    ));
            } catch (Throwable) {
                $this->pass('migrations: not visible to the application account (expected)');
            }
        } else {
            $this->pass('migrations: not visible to the application account (expected)');
        }

        try {
            $count = (int) $db->table('users')->countAllResults();
            $count > 0
                ? $this->pass(sprintf('%d user account(s) exist', $count))
                : $this->warn('There are no user accounts yet. Create one: php spark polyfix:create-user --email you@example.com --name "Your Name" --role SUPER_ADMIN');
        } catch (Throwable) {
            $this->warn('Could not count the user accounts.');
        }
    }

    /**
     * The driver's message helps whoever is deploying ("Access denied",
     * "Unknown database") but can carry the host and user name. Keep the
     * sentence, drop anything in quotes.
     */
    private function safeDbMessage(Throwable $e): string
    {
        $message = preg_replace("/'[^']*'/", "'…'", $e->getMessage()) ?? '';
        $message = (string) preg_replace('/\s+/', ' ', $message);

        return substr(trim($message), 0, 200);
    }

    // --- output ---------------------------------------------------------------

    private function pass(string $message): void
    {
        CLI::write('  ok     ' . $message, 'green');
    }

    private function warn(string $message): void
    {
        $this->warned++;
        CLI::write('  warn   ' . $message, 'yellow');
    }

    private function fail(string $message): void
    {
        $this->failed++;
        CLI::write('  FAIL   ' . $message, 'red');
    }
}

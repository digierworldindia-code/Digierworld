<?php

namespace App\Services\Admin;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * First-time setup in the browser (/setup) for hosting without SSH: the
 * same steps as `php spark key:generate`, `dgr:install` and
 * `dgr:create-owner`.
 *
 * It exists only while there is no active Owner/Super Admin, and only
 * someone who can open the server's files can use it: the page asks for a
 * one-time code that is written to writable/SETUP-CODE.txt and never shown
 * on the page. The code expires after two hours and the file is deleted
 * once the administrator account exists.
 */
final class SetupService
{
    public const CODE_FILE = 'SETUP-CODE.txt';

    /** Written once an administrator exists: from then on /setup is gone, even while the database is unreachable. */
    public const DONE_FILE = 'installed.lock';

    private const CODE_TTL = 7200;
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L

    private ?BaseConnection $db = null;
    private ?string $dbError = null;

    public function codePath(): string
    {
        return WRITEPATH . self::CODE_FILE;
    }

    /**
     * True while the browser setup may be used: no active Owner/Super Admin.
     * When the database cannot be read the page stays open (it still needs
     * the code, and creating the administrator checks again).
     */
    public function isOpen(): bool
    {
        if (is_file(WRITEPATH . self::DONE_FILE)) {
            return false;
        }
        if ($this->db() === null) {
            return true;
        }
        if ($this->needsOwner()) {
            return true;
        }
        $this->markDone();

        return false;
    }

    /** The database answers but has no DGR Office tables yet (pages then lead to /setup instead of an error). */
    public function tablesMissing(): bool
    {
        try {
            return $this->db() !== null && $this->coreTables() < 3;
        } catch (Throwable) {
            return false;
        }
    }

    /** The database is reachable and has no active Owner/Super Admin yet (the sign-in page then links here). */
    public function needsOwner(): bool
    {
        $db = $this->db();
        if ($db === null) {
            return false;
        }
        try {
            return $this->coreTables() < 3 || service('users')->activeSuperAdminCount() === 0;
        } catch (Throwable) {
            return true;
        }
    }

    /** Writes a new code unless a valid one exists (a code lasts two hours). */
    public function ensureCode(): void
    {
        if ($this->storedCode() !== null) {
            return;
        }
        $chars = '';
        for ($i = 0; $i < 12; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $code  = implode('-', str_split($chars, 4));
        $until = time() + self::CODE_TTL;
        $text  = "DGR Office - first-time setup code\r\n\r\n"
            . "Code: {$code}\r\n\r\n"
            . 'Valid until ' . date('d-m-Y h:i A', $until) . ' (' . date_default_timezone_get() . ").\r\n"
            . 'Enter it on the setup page: ' . site_url('setup') . "\r\n"
            . "This file is deleted automatically once the administrator account exists.\r\n"
            . "expires={$until}\r\n";
        if (file_put_contents($this->codePath(), $text, LOCK_EX) === false) {
            throw new BusinessRuleException('Could not write writable/' . self::CODE_FILE . ': the writable folder is not writable by the web server.');
        }
    }

    public function checkCode(string $input): bool
    {
        $stored = $this->storedCode();
        $norm   = static fn (string $c): string => (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($c));

        return $stored !== null && $norm($input) !== '' && hash_equals($norm($stored), $norm($input));
    }

    public function forgetCode(): void
    {
        if (is_file($this->codePath())) {
            unlink($this->codePath());
        }
    }

    /**
     * What the server has and what is still missing.
     *
     * @return list<array{0: string, 1: string, 2: string}> [label, ok|warn|fail, detail]
     */
    public function checks(): array
    {
        $out = [];
        $add = static function (string $label, string $status, string $detail) use (&$out): void {
            $out[] = [$label, $status, $detail];
        };

        $add('PHP version', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fail', PHP_VERSION . ' (8.2 or newer required)');
        $missing = array_values(array_filter(Diagnostics::REQUIRED_EXTENSIONS, static fn (string $e): bool => ! extension_loaded($e)));
        $add('PHP extensions', $missing === [] ? 'ok' : 'fail', $missing === [] ? 'all required extensions are loaded'
            : 'missing: ' . implode(', ', $missing) . ' — switch them on in the hosting panel (PHP configuration → Extensions)');
        $locked = array_values(array_filter(Diagnostics::WRITABLE_DIRS, static fn (string $d): bool => ! is_dir(WRITEPATH . $d) || ! is_writable(WRITEPATH . $d)));
        $add('Writable folders', $locked === [] ? 'ok' : 'fail', $locked === [] ? 'writable/ and its folders can be written'
            : 'cannot write: writable/' . implode(', writable/', $locked) . ' — set folder permissions to 755 (never 777)');

        $envFile = is_file(ROOTPATH . '.env');
        $add('.env file', $envFile ? 'ok' : 'fail', $envFile ? 'present' : 'missing — copy .env.example to .env (in the folder that contains spark) and enter the database settings');
        $add('Environment', ENVIRONMENT === 'production' ? 'ok' : 'warn', ENVIRONMENT . (ENVIRONMENT === 'production' ? '' : ' — set CI_ENVIRONMENT = production in .env on a live server'));
        $base = config('App')->baseURL;
        if (($miss = base_url_mismatch()) !== null) {
            $add('Site address', 'fail', "this page was opened at {$miss['detected']} but app.baseURL in .env is {$miss['configured']} — set app.baseURL = '{$miss['detected']}'");
        } elseif (str_contains($base, 'example.com')) {
            $add('Site address', 'fail', "{$base} is the example address — set app.baseURL in .env to the address you open, with the trailing slash");
        } else {
            $add('Site address', 'ok', $base);
        }
        $add('Encryption key', $this->hasKey() ? 'ok' : 'fail', $this->hasKey() ? 'set (not shown) — keep a copy of .env in a safe place'
            : 'not set — bank numbers and other secrets cannot be saved without it. Use "Generate the key" below');

        $db = $this->db();
        if ($db === null) {
            $add('Database', 'fail', 'cannot connect: ' . $this->dbError . ' — check database.default.hostname, .database, .username and .password in .env (on Hostinger the database and user names start with u123456789_)');
        } else {
            $ver = (string) ($db->query('SELECT VERSION() v')->getRow()->v ?? '');
            $num = preg_match('/^\d+(\.\d+)+/', $ver, $m) ? $m[0] : '0';
            $ok  = stripos($ver, 'mariadb') !== false ? version_compare($num, '10.6', '>=') : version_compare($num, '8.0.16', '>=');
            $add('Database', $ok ? 'ok' : 'fail', $db->getDatabase() . ' · ' . $ver . ($ok ? '' : ' — MySQL 8.0.16+ or MariaDB 10.6+ required'));
            $pending = $this->pending();
            $add('Database tables', $pending === [] ? 'ok' : 'warn', match (true) {
                $pending === []           => 'all tables and base data are in place',
                ! $this->hasData()        => 'the database is empty — create the tables below (or import the clean SQL file in phpMyAdmin)',
                default                   => count($pending) . ' database change(s) to apply — a verified backup is made first',
            });
        }

        return $out;
    }

    public function hasKey(): bool
    {
        return (string) config('Encryption')->key !== '';
    }

    public function databaseReady(): bool
    {
        return $this->db() !== null && $this->pending() === [];
    }

    public function canConnect(): bool
    {
        return $this->db() !== null;
    }

    /**
     * Adds `encryption.key = hex2bin:<64 hex>` to .env. Never replaces a key:
     * data already encrypted with it would become unreadable.
     *
     * @throws BusinessRuleException
     */
    public function writeEncryptionKey(?string $file = null): void
    {
        if ($this->hasKey()) {
            throw new BusinessRuleException('An encryption key is already set. It is never replaced here, because saved bank numbers depend on it.');
        }
        $file ??= ROOTPATH . '.env';
        if (! is_file($file) || ! is_writable($file)) {
            throw new BusinessRuleException('.env is missing or cannot be written by the web server. Run php spark key:generate, or add the line encryption.key = hex2bin:… to .env yourself.');
        }
        $text = (string) file_get_contents($file);
        if (preg_match('/^[ \t]*encryption\.key[ \t]*=[ \t]*[^\s#]/m', $text) === 1) {
            throw new BusinessRuleException('.env already has an encryption.key value, but it is not being read. Check that line for typing errors; it is not replaced here.');
        }
        $line = 'encryption.key = hex2bin:' . bin2hex(random_bytes(32));
        $new  = preg_replace('/^[ \t]*#?[ \t]*encryption\.key[ \t]*=[ \t]*$/m', $line, $text, 1, $count);
        if ($count === 0) {
            $new = rtrim($text, "\r\n") . "\n\n" . $line . "\n";
        }
        if (file_put_contents($file, (string) $new, LOCK_EX) === false) {
            throw new BusinessRuleException('.env could not be written.');
        }
    }

    /**
     * Creates the tables and base data (after a verified backup when the
     * database already holds data).
     *
     * @return list<string> what was done, for the page
     *
     * @throws BusinessRuleException
     */
    public function installDatabase(): array
    {
        $db = $this->db() ?? throw new BusinessRuleException('Cannot connect to the database: ' . $this->dbError);
        $messages = [];
        try {
            $result = (new InstallService($db))->run(true, static function (string $m) use (&$messages): void {
                $messages[] = $m;
            });
        } catch (\RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage());
        }
        $messages[] = $result['applied'] === [] ? 'Nothing to change: the tables were already in place.' : count($result['applied']) . ' database step(s) applied and base data loaded.';

        return $messages;
    }

    /**
     * Creates the first Owner / Super Admin. Refused once one exists.
     *
     * @throws BusinessRuleException
     */
    public function createOwner(string $email, string $fullName, string $password, string $confirm): int
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            throw new BusinessRuleException('Enter the administrator\'s full name.', ['full_name' => 'Enter the full name.']);
        }
        if ($password !== $confirm) {
            throw new BusinessRuleException('The two passwords do not match.', ['password_confirm' => 'The two passwords do not match.']);
        }
        $db = $this->db() ?? throw new BusinessRuleException('Cannot connect to the database: ' . $this->dbError);
        if (! $this->hasKey()) {
            throw new BusinessRuleException('Set the encryption key first (step 1).');
        }
        if ($this->pending() !== []) {
            throw new BusinessRuleException('Create the database tables first (step 2).');
        }

        // one administrator even if the form is sent twice at the same moment
        $db->query("SELECT GET_LOCK('dgr_setup_owner', 10)");
        try {
            if (! $this->isOpen()) {
                throw new BusinessRuleException('An administrator account already exists. Sign in with it, or ask that person to create your account.');
            }
            service('audit')->asActor('system');
            $id = service('users')->create($email, $fullName, $password, ['super_admin'], ['must_change_password' => 0]);
            service('audit')->log('setup.owner_created', 'user', $id, null, null, 'Administrator account created on the setup page', ['ip' => service('request')->getIPAddress()]);
        } finally {
            service('audit')->asActor('user');
            $db->query("SELECT RELEASE_LOCK('dgr_setup_owner')");
        }
        $this->forgetCode();
        $this->markDone();

        return $id;
    }

    private function markDone(): void
    {
        $text = "An administrator account exists, so the browser setup page (/setup) is switched off.\r\n"
            . "Delete this file only to create a new administrator when no active one is left (or use php spark dgr:create-owner).\r\n";
        if (is_dir(WRITEPATH) && is_writable(WRITEPATH)) {
            file_put_contents(WRITEPATH . self::DONE_FILE, $text, LOCK_EX);
        }
        $this->forgetCode();
    }

    private function coreTables(): int
    {
        return (int) $this->db()?->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('users', 'roles', 'user_roles')")->getRow()->n;
    }

    private function storedCode(): ?string
    {
        $text = is_file($this->codePath()) ? (string) file_get_contents($this->codePath()) : '';
        if (preg_match('/^Code: ([A-Z0-9-]+)\r?$/m', $text, $c) !== 1 || preg_match('/^expires=(\d+)\r?$/m', $text, $e) !== 1 || (int) $e[1] < time()) {
            return null;
        }

        return $c[1];
    }

    /** @return list<string> */
    private function pending(): array
    {
        $db = $this->db();

        return $db === null ? [] : (new InstallService($db))->pending();
    }

    private function hasData(): bool
    {
        $db = $this->db();

        return $db !== null && (new InstallService($db))->hasData();
    }

    private function db(): ?BaseConnection
    {
        if ($this->db === null && $this->dbError === null) {
            try {
                $db = db_connect();
                $db->initialize();
                $this->db = $db;
            } catch (Throwable $e) {
                $this->dbError = $e->getMessage(); // CodeIgniter masks the user and database names (****)
            }
        }

        return $this->db;
    }
}

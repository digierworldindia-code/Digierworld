<?php

namespace Tests\Feature;

use App\Services\Admin\InstallService;
use App\Services\Admin\SetupService;
use Tests\Support\DgrTestCase;

/**
 * First-time administrator setup in the browser (/setup): only while no
 * active Owner/Super Admin exists, only with the one-time code from the
 * server's writable folder, rate-limited, password rules applied, gone for
 * good afterwards; the encryption key is added to .env but never replaced.
 */
final class AdminSetupTest extends DgrTestCase
{
    private const PASSWORD = 'Saffron-Ledger-2026';

    /** @var list<int> */
    private array $paused = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Config\Services::resetSingle('setup');
        $this->removeFiles();
        if (auth('session')->loggedIn()) { // a visitor of a new installation is not signed in
            auth('session')->logout();
        }
    }

    protected function tearDown(): void
    {
        if ($this->paused !== []) {
            db_connect()->table('users')->whereIn('id', $this->paused)->update(['active' => 1]);
        }
        $this->removeFiles();
        service('throttler')->remove(md5(service('request')->getIPAddress())); // the sign-in rate limit shares this bucket
        parent::tearDown();
    }

    private function removeFiles(): void
    {
        foreach ([SetupService::CODE_FILE, SetupService::DONE_FILE] as $f) {
            if (is_file(WRITEPATH . $f)) {
                unlink(WRITEPATH . $f);
            }
        }
    }

    /** A fresh installation: no active Owner/Super Admin (other tests' owners are paused and restored afterwards). */
    private function withoutAdministrators(): void
    {
        $db = db_connect();
        $this->paused = array_map('intval', array_column($db->table('user_roles ur')->select('ur.user_id')->join('roles r', 'r.id = ur.role_id')
            ->join('users u', 'u.id = ur.user_id')->where(['r.is_super' => 1, 'u.active' => 1])->get()->getResultArray(), 'user_id'));
        if ($this->paused !== []) {
            $db->table('users')->whereIn('id', $this->paused)->update(['active' => 0]);
        }
        $this->assertSame(0, service('users')->activeSuperAdminCount());
    }

    private function code(): string
    {
        preg_match('/^Code: (\S+)/m', (string) file_get_contents(WRITEPATH . SetupService::CODE_FILE), $m);

        return $m[1];
    }

    public function testSetupIsGoneOnceAnAdministratorExists(): void
    {
        $this->makeUser('super_admin', 'Existing Owner');

        $this->assertSame(404, $this->statusOf(fn () => $this->get('setup')));
        $this->assertSame(404, $this->statusOf(fn () => $this->postForm('setup/owner', ['email' => 'intruder@example.test', 'full_name' => 'Intruder', 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD])));
        $this->assertSame(0, db_connect()->table('auth_identities')->where('secret', 'intruder@example.test')->countAllResults());
        $this->assertFileDoesNotExist(WRITEPATH . SetupService::CODE_FILE, 'no code is written on an installed system');
        $this->assertFileExists(WRITEPATH . SetupService::DONE_FILE, 'the installed marker keeps the page closed even if the database is unreachable later');
        $this->get('login')->assertDontSee('First-time installation');
    }

    public function testTheFirstAdministratorIsCreatedWithTheCodeFromTheServer(): void
    {
        $this->withoutAdministrators();
        $this->get('login')->assertSee('First-time installation');

        $page = $this->get('setup');
        $page->assertOK();
        $page->assertSee('writable/SETUP-CODE.txt');
        $this->assertFileExists(WRITEPATH . SetupService::CODE_FILE);
        $code = $this->code();
        $page->assertDontSee($code); // the code is only on the server

        // nothing works before the code is entered
        $this->assertSame(404, $this->statusOf(fn () => $this->postForm('setup/owner', ['email' => 'early@example.test', 'full_name' => 'Early Bird', 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD])));
        $this->postForm('setup/unlock', ['code' => 'AAAA-BBBB-CCCC'])->assertRedirectTo(site_url('setup'));
        $this->assertStringContainsString('wrong or has expired', (string) session('error'));
        $this->get('setup')->assertSee('Setup code');

        // typed without dashes and in lower case
        $this->postForm('setup/unlock', ['code' => strtolower(str_replace('-', '', $code))])->assertRedirectTo(site_url('setup'));
        $this->withSession($_SESSION); // the browser keeps its session cookie
        $checks = $this->get('setup');
        $checks->assertSee('Database tables');
        $checks->assertSee('all tables and base data are in place');
        $checks->assertSee('Create the administrator');

        // password rules and confirmation apply; the password is never kept for the form
        $this->postForm('setup/owner', ['email' => 'ravi@digierworld.test', 'full_name' => 'Ravi Kumar', 'password' => 'ravikumar123', 'password_confirm' => 'ravikumar123'])->assertRedirectTo(site_url('setup'));
        $this->assertSame('Password must not contain the part of the email before @, not contain your name.', session('error'));
        $this->assertStringNotContainsString('ravikumar123', json_encode($_SESSION['_ci_old_input'] ?? []));
        $this->postForm('setup/owner', ['email' => 'ravi@digierworld.test', 'full_name' => 'Ravi Kumar', 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD . 'x'])->assertRedirectTo(site_url('setup'));
        $this->assertSame('The two passwords do not match.', session('error'));
        $this->assertSame(0, service('users')->activeSuperAdminCount());

        $this->postForm('setup/owner', ['email' => 'Ravi@DigierWorld.test', 'full_name' => 'Ravi Kumar', 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD])->assertRedirectTo(url_to('login'));
        $this->assertSame('Administrator account created. Sign in with ravi@digierworld.test.', session('message'));
        $db  = db_connect();
        $uid = (int) $db->table('auth_identities')->where('secret', 'ravi@digierworld.test')->get()->getRow()->user_id;
        $this->assertTrue(service('users')->isSuperAdmin($uid));
        $this->assertSame(0, (int) $db->table('user_profiles')->where('user_id', $uid)->get()->getRow()->must_change_password, 'they chose the password themselves');
        $this->assertTrue(auth()->check(['email' => 'ravi@digierworld.test', 'password' => self::PASSWORD])->isOK());
        $audit = $db->table('audit_logs')->where(['action' => 'setup.owner_created', 'entity_id' => $uid])->get()->getRowArray();
        $this->assertSame('system', $audit['actor_type']);

        // gone for good: code file removed, page and actions 404
        $this->assertFileDoesNotExist(WRITEPATH . SetupService::CODE_FILE);
        $this->assertFileExists(WRITEPATH . SetupService::DONE_FILE);
        \Config\Services::resetSingle('setup');
        $this->assertSame(404, $this->statusOf(fn () => $this->get('setup')));
        $this->assertSame(404, $this->statusOf(fn () => $this->postForm('setup/owner', ['email' => 'second@example.test', 'full_name' => 'Second Owner', 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD])));
    }

    public function testGuessingTheCodeIsRateLimitedAndExpiredCodesAreReplaced(): void
    {
        $this->withoutAdministrators();
        $this->get('setup')->assertOK();
        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->postForm('setup/unlock', ['code' => 'WRONG-' . $i])->response()->getStatusCode();
        }
        $this->assertSame(429, end($statuses), 'the 11th try within a minute is refused');

        $old  = $this->code();
        $file = WRITEPATH . SetupService::CODE_FILE;
        file_put_contents($file, preg_replace('/^expires=\d+/m', 'expires=' . (time() - 1), (string) file_get_contents($file)));
        $setup = new SetupService();
        $this->assertFalse($setup->checkCode($old), 'an expired code no longer works');
        $setup->ensureCode();
        $this->assertNotSame($old, $this->code());
        $this->assertTrue($setup->checkCode($this->code()));
    }

    public function testEncryptionKeyIsAddedToEnvButNeverReplaced(): void
    {
        $config = config('Encryption');
        $saved  = $config->key;
        $file   = tempnam(sys_get_temp_dir(), 'env');
        try {
            $config->key = '';
            $setup = new SetupService();

            file_put_contents($file, "CI_ENVIRONMENT = production\n# encryption\nencryption.key =\napp.baseURL = 'https://office.example.in/'\n");
            $setup->writeEncryptionKey($file);
            $text = (string) file_get_contents($file);
            $this->assertMatchesRegularExpression('/^encryption\.key = hex2bin:[0-9a-f]{64}$/m', $text);
            $this->assertSame(1, preg_match_all('/encryption\.key/', $text), 'the empty line is filled in, not duplicated');
            $this->assertStringContainsString("app.baseURL = 'https://office.example.in/'", $text, 'other settings are kept');

            // a value already in the file is never replaced
            try {
                $setup->writeEncryptionKey($file);
                $this->fail('An existing key line must not be replaced');
            } catch (\App\Exceptions\BusinessRuleException) {
                $this->assertSame($text, (string) file_get_contents($file));
            }

            file_put_contents($file, "CI_ENVIRONMENT = production\n");
            $setup->writeEncryptionKey($file);
            $this->assertMatchesRegularExpression('/^encryption\.key = hex2bin:[0-9a-f]{64}$/m', (string) file_get_contents($file), 'added when the line is missing');

            // a key that is in use is never replaced
            $config->key = 'in-use-key-0123456789abcdef0123456789';
            file_put_contents($file, "encryption.key =\n");
            try {
                (new SetupService())->writeEncryptionKey($file);
                $this->fail('A key in use must not be replaced');
            } catch (\App\Exceptions\BusinessRuleException) {
                $this->assertSame("encryption.key =\n", (string) file_get_contents($file));
            }
        } finally {
            $config->key = $saved;
            unlink($file);
        }
    }

    public function testInstallChangesNothingWhenTheDatabaseIsUpToDate(): void
    {
        $install = new InstallService(db_connect());
        $this->assertSame([], $install->pending());
        $this->assertTrue($install->hasData());
        $before = glob(WRITEPATH . 'backups/*.sql.gz') ?: [];
        $result = $install->run();
        $this->assertSame(['applied' => [], 'backup' => null], $result, 'no backup and no change when nothing is pending');
        $this->assertSame($before, glob(WRITEPATH . 'backups/*.sql.gz') ?: []);
    }
}

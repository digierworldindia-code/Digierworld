<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Services\Admin\SetupService;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * First-time administrator setup in the browser (/setup), for hosting
 * without SSH. Public route, but: it does not exist once an active
 * Owner/Super Admin exists, every action needs the one-time code from
 * writable/SETUP-CODE.txt, posts are rate-limited (auth-rates) and
 * CSRF-checked. Extends the plain Controller on purpose: the database may
 * still be empty, so nothing here may need a signed-in user or a table.
 */
class SetupController extends Controller
{
    private const UNLOCK = 'setup_unlocked_until';
    private const UNLOCK_SECONDS = 3600;

    private SetupService $setup;

    public function index(): string
    {
        $this->open();
        try {
            $this->setup->ensureCode();
        } catch (BusinessRuleException $e) {
            return view('setup/code', ['problem' => $e->getMessage()]);
        }
        if (! $this->unlocked()) {
            return view('setup/code', ['problem' => null]);
        }

        return view('setup/index', [
            'checks'    => $this->setup->checks(),
            'hasKey'    => $this->setup->hasKey(),
            'canDb'     => $this->setup->canConnect(),
            'dbReady'   => $this->setup->databaseReady(),
            'envWrites' => is_file(ROOTPATH . '.env') && is_writable(ROOTPATH . '.env'),
        ]);
    }

    public function unlock(): RedirectResponse
    {
        $this->open();
        if (! $this->setup->checkCode((string) $this->request->getPost('code'))) {
            log_message('warning', 'Setup page: wrong or expired setup code from {ip}', ['ip' => $this->request->getIPAddress()]);

            return redirect()->to(site_url('setup'))->with('error', 'That code is wrong or has expired. Open writable/' . SetupService::CODE_FILE . ' again: a new code is written there when the old one expires.');
        }
        session()->regenerate(true);
        session()->set(self::UNLOCK, time() + self::UNLOCK_SECONDS);

        return redirect()->to(site_url('setup'));
    }

    public function key(): RedirectResponse
    {
        $this->openAndUnlocked();
        try {
            $this->setup->writeEncryptionKey();
        } catch (BusinessRuleException $e) {
            return redirect()->to(site_url('setup'))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('setup'))->with('message', 'Encryption key generated and saved in .env. Download a copy of .env and keep it somewhere safe: without this key saved bank numbers cannot be read.');
    }

    public function database(): RedirectResponse
    {
        $this->openAndUnlocked();
        set_time_limit(300);
        try {
            $done = $this->setup->installDatabase();
        } catch (BusinessRuleException $e) {
            return redirect()->to(site_url('setup'))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('setup'))->with('message', implode(' ', $done));
    }

    public function owner(): RedirectResponse
    {
        $this->openAndUnlocked();
        $post = $this->request->getPost();
        try {
            $this->setup->createOwner((string) ($post['email'] ?? ''), (string) ($post['full_name'] ?? ''), (string) ($post['password'] ?? ''), (string) ($post['password_confirm'] ?? ''));
        } catch (BusinessRuleException $e) {
            $res = redirect()->to(site_url('setup'))->withInput()->with('errors', $e->errors())->with('error', $e->getMessage());
            forget_secret_input();

            return $res;
        }
        session()->remove(self::UNLOCK);

        return redirect()->to(url_to('login'))->with('message', 'Administrator account created. Sign in with ' . strtolower(trim((string) ($post['email'] ?? ''))) . '.');
    }

    /** 404 once an administrator exists: the page is gone for good. */
    private function open(): void
    {
        $this->setup = service('setup');
        if (! $this->setup->isOpen()) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function openAndUnlocked(): void
    {
        $this->open();
        if (! $this->unlocked()) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function unlocked(): bool
    {
        return (int) session(self::UNLOCK) > time();
    }
}

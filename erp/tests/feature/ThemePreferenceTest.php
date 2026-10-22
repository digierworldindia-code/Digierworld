<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/**
 * Light / Dark / System: the top bar offers the three choices, the choice
 * is saved to the user's profile and every page starts in it (no flash),
 * and System is the default.
 */
final class ThemePreferenceTest extends DgrTestCase
{
    public function testChoiceIsSavedToTheProfileAndAppliedToEveryPage(): void
    {
        $user = $this->makeUser('accountant', 'Theme User');
        $this->loginAs($user);

        $page = $this->get('/');
        $page->assertOK();
        $html = (string) $page->response()->getBody();
        $this->assertStringContainsString('data-theme-pref="system"', $html, 'System is the default');
        $this->assertStringContainsString('theme-init.js', $html);
        foreach (['light', 'dark', 'system'] as $choice) {
            $this->assertStringContainsString('data-theme-choice="' . $choice . '"', $html);
        }

        $save = fn (string $theme) => $this->withHeaders(['X-CSRF-TOKEN' => csrf_hash(), 'X-Requested-With' => 'XMLHttpRequest'])->withBodyFormat('json')->post('profile/theme', ['theme' => $theme]);
        $save('light')->assertOK();
        $this->withHeaders([])->withBodyFormat('');
        $prefs = json_decode((string) db_connect()->table('user_profiles')->where('user_id', $user->id)->get()->getRow()->preferences, true);
        $this->assertSame('light', $prefs['theme']);

        $html = (string) $this->get('billing/documents')->response()->getBody();
        $this->assertStringContainsString('data-bs-theme="light" data-theme-pref="light"', $html, 'the page is rendered light from the server');

        $res = $save('purple');
        $this->assertSame(422, $res->response()->getStatusCode());
        $this->withHeaders([])->withBodyFormat('');
        $save('dark')->assertOK();
        $this->withHeaders([])->withBodyFormat('');
        $this->assertStringContainsString('data-bs-theme="dark" data-theme-pref="dark"', (string) $this->get('/')->response()->getBody());
    }

    public function testSignInPageAppliesTheDeviceChoiceBeforePainting(): void
    {
        if (auth('session')->loggedIn()) {
            auth('session')->logout();
        }
        $html = (string) $this->get('login')->response()->getBody();
        $this->assertStringContainsString('theme-init.js', $html);
        $this->assertLessThan(strpos($html, '<body'), strpos($html, 'theme-init.js'), 'the theme script runs in <head>, before the page is painted');
    }
}

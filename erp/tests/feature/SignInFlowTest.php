<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/**
 * Reported on a live install: after signing in, users landed on
 * /brand/logo (a 404 there) instead of the dashboard. The sign-in page
 * loads the logo, the logo URL required a session, and Shield remembered
 * that image request as the page to return to after sign-in.
 */
final class SignInFlowTest extends DgrTestCase
{
    public function testSignInPageLogoIsPublicAndOnlyPageVisitsBecomeTheReturnTarget(): void
    {
        $owner = $this->makeUser('super_admin', 'Flow Owner');
        $this->brandAsset('logo', (int) $owner->id);
        if (auth('session')->loggedIn()) {
            auth('session')->logout(); // an earlier test's sign-in may still be active in this process
        }

        // signed out: the sign-in page shows the logo and the logo itself is served
        $this->get('login')->assertSee('brand/logo');
        $logo = $this->get('brand/logo');
        $logo->assertOK();
        $logo->assertHeader('Content-Type', 'image/png');
        $this->assertNull(session()->getTempdata('beforeLoginUrl'));

        // signed out: images and script calls get 401, and are never remembered as the return page
        foreach ([['Sec-Fetch-Dest' => 'image'], ['Sec-Fetch-Dest' => 'empty'], ['X-Requested-With' => 'XMLHttpRequest']] as $headers) {
            $res = $this->withHeaders($headers)->get('billing/documents');
            $this->assertSame(401, $res->response()->getStatusCode(), json_encode($headers));
            $this->assertStringContainsString('session has ended', (string) $res->response()->getBody());
            $this->assertNull(session()->getTempdata('beforeLoginUrl'), json_encode($headers));
        }

        // a real page visit still goes to sign-in and is remembered, so the user comes back to it
        $res = $this->withHeaders(['Sec-Fetch-Dest' => 'document'])->get('billing/documents');
        $res->assertRedirectTo(site_url('login'));
        $this->assertStringEndsWith('billing/documents', (string) session()->getTempdata('beforeLoginUrl'));
    }
}

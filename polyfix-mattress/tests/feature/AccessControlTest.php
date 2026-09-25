<?php

namespace Tests\Feature;

use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\PolyfixTestCase;

/**
 * What an unauthenticated or wrongly-privileged request gets, over HTTP.
 *
 * These are the checks that matter most: hiding a link in a template is a
 * courtesy, the filter is the control.
 *
 * @internal
 */
final class AccessControlTest extends PolyfixTestCase
{
    use FeatureTestTrait;

    /**
     * @dataProvider privateAddresses
     */
    public function testAPrivateAddressSendsAnAnonymousVisitorToSignIn(string $path): void
    {
        $result = $this->get($path);

        $this->assertTrue($result->isRedirect(), "{$path} must not answer an anonymous request");
        $this->assertStringContainsString('login', $result->getRedirectUrl());
    }

    public static function privateAddresses(): array
    {
        return [
            'dashboard'     => ['admin'],
            'mattresses'    => ['admin/mattresses'],
            'claims'        => ['admin/claims'],
            'users'         => ['admin/users'],
            'audit'         => ['admin/audit'],
            'settings'      => ['admin/settings'],
            'reports'       => ['admin/reports'],
            'report export' => ['admin/reports/sales/export/csv'],
            'dealer home'   => ['dealer'],
            'dealer stock'  => ['dealer/inventory'],
            'dealer sell'   => ['dealer/sell'],
            'account'       => ['account/security'],
        ];
    }

    public function testEveryPrivateResponseTellsSearchEnginesToStayAway(): void
    {
        foreach (['admin', 'dealer', 'admin/login', 'dealer/login'] as $path) {
            $result = $this->get($path);
            $this->assertStringContainsString('noindex', $result->response()->getHeaderLine('X-Robots-Tag'), "{$path} must be noindex");
        }
    }

    public function testASignInPageIsNeverCached(): void
    {
        $result = $this->get('admin/login');

        $result->assertStatus(200);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    public function testAFormPostWithoutACsrfTokenIsRefused(): void
    {
        $result = $this->post('contact', [
            'name' => 'Someone', 'phone' => '9876500011', 'requirement' => 'PRODUCT_ENQUIRY',
            'message' => 'A message long enough to pass validation.',
        ]);

        // A browser form is sent back to the page it came from; an API-style
        // request gets a 403. Either way the submission is not stored.
        $this->assertContains($result->response()->getStatusCode(), [302, 403]);
        $this->assertSame(0, $this->db->table('contact_submissions')->countAllResults(), 'nothing is written without a token');
    }

    public function testRepeatedSignInAttemptsAreThrottled(): void
    {
        $filter  = new \App\Filters\Throttle();
        $request = service('request');
        $request->setMethod('POST');
        $limit   = config('Polyfix')->rateLoginPerMinute;
        cache()->delete('throttle_login_' . md5((string) $request->getIPAddress()));

        $refused = null;
        for ($attempt = 0; $attempt <= $limit + 1; $attempt++) {
            $answer = $filter->before($request, ['login']);
            if ($answer !== null) {
                $refused = $answer;
                break;
            }
        }

        $this->assertNotNull($refused, 'the address must be throttled once it passes the limit');
        $this->assertSame(429, $refused->getStatusCode());
        $this->assertNotSame('', $refused->getHeaderLine('Retry-After'));
        cache()->delete('throttle_login_' . md5((string) $request->getIPAddress()));
    }
}

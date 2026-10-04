<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\Services;
use App\Core\Csrf;
use App\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * Output escaping contexts and the CSRF token round trip.
 *
 * @internal
 */
final class EscapeAndCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        Services::reset();
    }

    public function testEscapingContexts(): void
    {
        $this->assertSame('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', esc("<script>alert('x')</script>"));
        $this->assertSame('a&#x20;&quot;b&quot;&#x20;onclick&#x3D;x', esc('a "b" onclick=x', 'attr'));
        $this->assertSame('\x3C\x2Fscript\x3E', esc('</script>', 'js'));
        $this->assertSame('a%20b%26c', esc('a b&c', 'url'));
        $this->assertSame(5, esc(5));
        $this->assertSame(['x' => '&amp;'], esc(['x' => '&']));
    }

    public function testCsrfTokenIsMaskedAndVerified(): void
    {
        $first  = Csrf::hash();
        $second = Csrf::hash();
        $this->assertNotSame($first, $second, 'masked differently on every render');

        foreach ([$first, $second] as $token) {
            $request = new Request(['REQUEST_METHOD' => 'POST'], [], ['csrf_qms' => $token]);
            $this->assertTrue(Csrf::verify($request));
        }
        $this->assertTrue(Csrf::verify(new Request(['REQUEST_METHOD' => 'POST', 'HTTP_X_CSRF_TOKEN' => $first])));

        $this->assertFalse(Csrf::verify(new Request(['REQUEST_METHOD' => 'POST'], [], ['csrf_qms' => str_repeat('0', 128)])));
        $this->assertFalse(Csrf::verify(new Request(['REQUEST_METHOD' => 'POST'], [], [])));
        $_SESSION = [];
        $this->assertFalse(Csrf::verify(new Request(['REQUEST_METHOD' => 'POST'], [], ['csrf_qms' => $first])), 'a token from another session fails');
    }

    public function testTrustedProxyRanges(): void
    {
        $this->assertTrue(Request::ipInRanges('10.1.2.3', ['10.0.0.0/8']));
        $this->assertFalse(Request::ipInRanges('11.1.2.3', ['10.0.0.0/8']));
        $this->assertTrue(Request::ipInRanges('192.168.1.7', ['192.168.1.7']));
        $this->assertTrue(Request::ipInRanges('2001:db8::1', ['2001:db8::/32']));
        $this->assertFalse(Request::ipInRanges('2001:db9::1', ['2001:db8::/32']));
    }
}

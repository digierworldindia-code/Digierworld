<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Every route except the public ones and the few "any logged-in user" pages
 * must carry a permission filter, so a new route can never be left open by mistake.
 *
 * @internal
 */
final class RoutePermissionTest extends CIUnitTestCase
{
    private const PUBLIC = ['health', 'media/logo', 'login'];

    private const ANY_LOGGED_IN = ['/', 'logout', 'account/password'];

    public function testEveryProtectedRouteHasAuthAndPermissionFilters(): void
    {
        $routes  = service('routes');
        $checked = 0;
        foreach (['GET', 'POST'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $from) {
                $path = (string) $from;
                if (in_array($path, self::PUBLIC, true)) {
                    continue;
                }
                $filters = $routes->getFiltersForRoute($path, $verb);
                $this->assertContains('auth', $filters, "{$verb} {$path} must require login");
                if (! in_array($path, self::ANY_LOGGED_IN, true)) {
                    $permission = array_filter($filters, static fn (string $f): bool => str_starts_with($f, 'permission:'));
                    $this->assertNotEmpty($permission, "{$verb} {$path} has no permission filter");
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(80, $checked);
    }

    public function testAutoRoutingIsOff(): void
    {
        $this->assertFalse(service('routes')->shouldAutoRoute());
    }
}

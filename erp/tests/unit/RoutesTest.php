<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/** Every route points at a public controller method that exists (a typo there is a 404 on that page). */
final class RoutesTest extends CIUnitTestCase
{
    public function testEveryRouteHandlerExists(): void
    {
        $routes = service('routes')->loadRoutes();
        $broken = [];
        $count  = 0;
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach ($routes->getRoutes($verb) as $from => $handler) {
                if (! is_string($handler)) {
                    continue; // closures
                }
                $count++;
                [$class, $method] = array_pad(explode('::', ltrim($handler, '\\'), 2), 2, 'index');
                $method = explode('/', $method, 2)[0];
                if (! class_exists($class) || ! method_exists($class, $method) || ! (new \ReflectionMethod($class, $method))->isPublic()) {
                    $broken[] = "{$verb} {$from} -> {$handler}";
                }
            }
        }
        $this->assertGreaterThan(200, $count, 'routes were loaded');
        $this->assertSame([], $broken);
    }
}

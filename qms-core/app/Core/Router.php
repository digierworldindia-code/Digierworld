<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Route table. Every URL is declared in app/routes.php; there is no automatic
 * routing from URL to controller.
 *
 *   $routes->get('inspections/(:num)', 'Inspections\InspectionController::show/$1', ['filter' => 'permission:inspection.view_all']);
 *   $routes->group('admin', ['filter' => 'auth'], function (Router $routes) { … });
 *
 * Placeholders: (:num) digits, (:segment) one path segment, (:any) the rest.
 */
final class Router
{
    /** @var array<string, array<string, array{handler: string, filters: list<string>, regex: string}>> verb => path => route */
    private array $routes = ['GET' => [], 'POST' => []];

    private string $prefix = '';

    /** @var list<string> */
    private array $groupFilters = [];

    /**
     * @param array{filter?: list<string>|string} $options
     */
    public function get(string $path, string $handler, array $options = []): void
    {
        $this->add('GET', $path, $handler, $options);
    }

    /**
     * @param array{filter?: list<string>|string} $options
     */
    public function post(string $path, string $handler, array $options = []): void
    {
        $this->add('POST', $path, $handler, $options);
    }

    /**
     * @param array{filter?: list<string>|string}|callable $options
     */
    public function group(string $prefix, array|callable $options, ?callable $callback = null): void
    {
        if (is_callable($options)) {
            [$callback, $options] = [$options, []];
        }

        $previousPrefix  = $this->prefix;
        $previousFilters = $this->groupFilters;

        $this->prefix       = $this->join($this->prefix, $prefix);
        $this->groupFilters = array_merge($this->groupFilters, self::filterList($options['filter'] ?? []));

        try {
            $callback($this);
        } finally {
            $this->prefix       = $previousPrefix;
            $this->groupFilters = $previousFilters;
        }
    }

    /**
     * @return array{handler: string, params: list<string>, filters: list<string>}|null
     *         null = no route; ['handler' => ''] = path exists for another verb (405)
     */
    public function match(string $method, string $path): ?array
    {
        $verb = $method === 'HEAD' ? 'GET' : $method;
        $path = trim($path, '/');

        $route = $this->find($verb, $path);
        if ($route !== null) {
            return $route;
        }

        foreach (array_keys($this->routes) as $other) {
            if ($other !== $verb && $this->find($other, $path) !== null) {
                return ['handler' => '', 'params' => [], 'filters' => [], 'allow' => $other];
            }
        }

        return null;
    }

    /**
     * @return array<string, array{handler: string, filters: list<string>, regex: string}>
     */
    public function getRoutes(string $verb): array
    {
        return $this->routes[strtoupper($verb)] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getFiltersForRoute(string $path, string $verb): array
    {
        return $this->routes[strtoupper($verb)][trim($path, '/')]['filters'] ?? [];
    }

    public function shouldAutoRoute(): bool
    {
        return false;
    }

    /**
     * @param array{filter?: list<string>|string} $options
     */
    private function add(string $verb, string $path, string $handler, array $options): void
    {
        $full = $this->join($this->prefix, $path);
        $regex = '#^' . strtr(preg_quote($full, '#'), [
            preg_quote('(:num)', '#')     => '([0-9]+)',
            preg_quote('(:segment)', '#') => '([^/]+)',
            preg_quote('(:any)', '#')     => '(.+)',
        ]) . '$#';

        $this->routes[$verb][$full] = [
            'handler' => $handler,
            'filters' => array_values(array_merge($this->groupFilters, self::filterList($options['filter'] ?? []))),
            'regex'   => $regex,
        ];
    }

    /**
     * @return array{handler: string, params: list<string>, filters: list<string>}|null
     */
    private function find(string $verb, string $path): ?array
    {
        $routes = $this->routes[$verb] ?? [];

        if (isset($routes[$path]) && ! str_contains($path, '(:')) {
            return ['handler' => $routes[$path]['handler'], 'params' => [], 'filters' => $routes[$path]['filters']];
        }
        foreach ($routes as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                return ['handler' => $route['handler'], 'params' => array_slice($m, 1), 'filters' => $route['filters']];
            }
        }

        return null;
    }

    private function join(string $prefix, string $path): string
    {
        return trim(trim($prefix, '/') . '/' . trim($path, '/'), '/');
    }

    /**
     * @param list<string>|string $filters
     *
     * @return list<string>
     */
    private static function filterList(array|string $filters): array
    {
        return array_values(array_filter(is_array($filters) ? $filters : [$filters], static fn ($f): bool => is_string($f) && $f !== ''));
    }
}

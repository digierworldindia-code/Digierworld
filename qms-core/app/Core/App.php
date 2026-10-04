<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Services;
use App\Filters\AuthFilter;
use App\Filters\GuestFilter;
use App\Filters\LoginThrottleFilter;
use App\Filters\NoStoreFilter;
use App\Filters\OriginCheckFilter;
use App\Filters\PermissionFilter;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Request kernel: one request in, one response out.
 *
 *  1. HTTPS redirect, maintenance mode, upload size and character checks
 *  2. route lookup (404 / 405)
 *  3. same-origin check and CSRF token for every state-changing request
 *  4. route filters (login, permission, throttle …) → controller → after-filters
 *  5. security headers and Content-Security-Policy on every response
 */
final class App
{
    /** Route filter aliases. */
    public const FILTERS = [
        'auth'          => AuthFilter::class,
        'guest'         => GuestFilter::class,
        'permission'    => PermissionFilter::class,
        'loginthrottle' => LoginThrottleFilter::class,
        'nostore'       => NoStoreFilter::class,
    ];

    public const SECURITY_HEADERS = [
        'X-Content-Type-Options'            => 'nosniff',
        'X-Frame-Options'                   => 'DENY',
        'Referrer-Policy'                   => 'same-origin',
        'Permissions-Policy'                => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy'        => 'same-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'X-Robots-Tag'                      => 'noindex, nofollow',
    ];

    public function run(): void
    {
        $response = $this->handle(Services::request());
        header_remove('X-Powered-By');
        $response->send();
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (Throwable $e) {
            $response = ErrorHandler::render($e, $request);
        }

        try {
            $this->finalize($request, $response);
        } catch (Throwable $e) {
            log_message('error', 'Response finalisation failed: {e}', ['e' => $e]);
        }

        return $response;
    }

    private function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $unsafe = ! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

        if (config('App')->forceGlobalSecureRequests && ! $request->isSecure()) {
            if ($unsafe) {
                throw new HttpException(403, 'Please use the secure (https://) address of this application.');
            }
            $query = $request->getUri()->getQuery();

            return (new RedirectResponse())->setStatusCode(301)
                ->setHeader('Location', preg_replace('#^http://#i', 'https://', current_url()) . ($query !== '' ? '?' . $query : ''));
        }

        if (is_file(WRITEPATH . 'maintenance.flag')) {
            return $this->maintenance($request);
        }

        if ($unsafe) {
            $this->checkContentLength($request);
        }
        $this->checkCharacters($request);

        $route = Services::routes()->match($method, $request->getPath());
        if ($route === null) {
            throw HttpException::notFound();
        }
        if ($route['handler'] === '') {
            $e        = new HttpException(405, 'This address does not accept this kind of request.');
            $e->allow = (string) ($route['allow'] ?? 'GET');

            throw $e;
        }

        if ($unsafe) {
            $refused = (new OriginCheckFilter())->before($request);
            if ($refused !== null) {
                return $refused;
            }
            if (! Csrf::verify($request)) {
                return $this->csrfFailed($request);
            }
        }

        $filters = [];
        foreach ($route['filters'] as $spec) {
            [$alias, $args] = array_pad(explode(':', $spec, 2), 2, null);
            $class = self::FILTERS[$alias] ?? throw new \LogicException("Unknown route filter {$alias}");
            $filters[] = [new $class(), $args === null ? null : array_map('trim', explode(',', $args))];
        }

        foreach ($filters as [$filter, $args]) {
            $stop = $filter->before($request, $args);
            if ($stop instanceof Response) {
                return $stop;
            }
        }

        $response = $this->callController($route['handler'], $route['params'], $request);

        foreach ($filters as [$filter, $args]) {
            $response = $filter->after($request, $response, $args) ?? $response;
        }

        return $response;
    }

    /**
     * @param list<string> $params
     */
    private function callController(string $handler, array $params, Request $request): Response
    {
        [$class, $method] = explode('::', $handler, 2);
        $method = explode('/', $method, 2)[0];
        $class  = 'App\\Controllers\\' . $class;

        $controller = new $class();
        if (! $controller instanceof Controller || ! method_exists($controller, $method)) {
            throw new \LogicException("Route handler {$handler} does not exist.");
        }
        $response = Services::response();
        $controller->initController($request, $response);

        // URL segments arrive as strings; (:num) parameters are passed as int.
        $reflection = new ReflectionMethod($controller, $method);
        $arguments  = [];
        foreach ($reflection->getParameters() as $i => $parameter) {
            if (! array_key_exists($i, $params)) {
                break;
            }
            $type          = $parameter->getType();
            $arguments[]   = $type instanceof ReflectionNamedType && $type->getName() === 'int' ? (int) $params[$i] : $params[$i];
        }

        $result = $controller->{$method}(...$arguments);

        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            $response->setBody($result);
        }

        return $response;
    }

    private function csrfFailed(Request $request): Response
    {
        $message = 'Your session expired or the page was out of date. Please try again.';
        if ($request->wantsJson()) {
            return (new Response())->setStatusCode(403)->setJSON(['ok' => false, 'error' => $message, 'csrf' => true]);
        }

        return redirect()->back()->with('error', $message);
    }

    private function maintenance(Request $request): Response
    {
        $response = (new Response())->setStatusCode(503)->setHeader('Retry-After', '120')->setHeader('Cache-Control', 'no-store');
        if ($request->wantsJson() || $request->getPath() === 'health') {
            return $response->setJSON(['ok' => false, 'error' => 'The application is being updated. Please try again in a few minutes.']);
        }
        $page = FCPATH . 'maintenance.html';

        return $response->setBody(is_file($page) ? (string) file_get_contents($page) : 'Maintenance in progress.');
    }

    /** A POST larger than post_max_size arrives without fields or files. */
    private function checkContentLength(Request $request): void
    {
        $length = (int) $request->getHeaderLine('Content-Length');
        $limit  = self::iniBytes((string) ini_get('post_max_size'));
        if ($limit > 0 && $length > $limit) {
            throw new HttpException(413, 'The upload is too large (server limit ' . ini_get('post_max_size') . 'B).');
        }
    }

    /** Rejects invalid UTF-8 and control characters in the URL and input. */
    private function checkCharacters(Request $request): void
    {
        if (! preg_match('#^[A-Za-z0-9~%.:_\-/ ]*$#', $request->getPath())) {
            throw new HttpException(400, 'The address contains characters that are not allowed.');
        }

        $check = static function (mixed $value) use (&$check): bool {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (! $check((string) $k) || ! $check($v)) {
                        return false;
                    }
                }

                return true;
            }

            return ! is_string($value) || (mb_check_encoding($value, 'UTF-8') && preg_match('/\A[\r\n\t[:^cntrl:]]*\z/u', $value) === 1);
        };

        if (! $check($request->getGet()) || ! $check($request->getPost()) || ! $check($_COOKIE)) {
            throw new HttpException(400, 'The request contains invalid characters.');
        }
    }

    private function finalize(Request $request, Response $response): void
    {
        foreach (self::SECURITY_HEADERS as $name => $value) {
            if (! $response->hasHeader($name)) {
                $response->setHeader($name, $value);
            }
        }
        $response->setHeader('X-Request-Id', service('requestContext')->requestId());
        if (! $response->hasHeader('Content-Security-Policy')) {
            $response->setHeader('Content-Security-Policy', self::contentSecurityPolicy($request));
        }
        if ($request->isSecure() && config('App')->forceGlobalSecureRequests) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        // Remember the last page for redirect()->back().
        $session = Services::session();
        if ($session->isStarted() && $request->getMethod() === 'GET' && ! $request->isAJAX()
            && $response->getStatusCode() === 200 && str_contains($response->getHeaderLine('Content-Type'), 'text/html')) {
            $query = $request->getUri()->getQuery();
            $session->set('_qms_previous_url', current_url() . ($query !== '' ? '?' . $query : ''));
        }
    }

    /**
     * Strict policy: only this server's own scripts, styles, fonts and images;
     * no inline scripts or style attributes, no framing, no plugins.
     */
    public static function contentSecurityPolicy(?Request $request = null): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self'",
            "script-src-elem 'self'",
            "script-src-attr 'none'",
            "style-src 'self'",
            "style-src-elem 'self'",
            "style-src-attr 'none'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-src 'self'",
            "child-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ];
        if ($request !== null && config('App')->forceGlobalSecureRequests) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}

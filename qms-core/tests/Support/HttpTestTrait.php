<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Config\Services;
use App\Core\App;
use App\Core\Csrf;
use App\Core\Request;

/**
 * Runs requests through the real kernel (routing, CSRF, filters, controllers,
 * views) inside the test process. The session is the $_SESSION array.
 */
trait HttpTestTrait
{
    /** @var array<string, string> */
    private array $nextHeaders = [];

    /**
     * @param array<string, mixed> $session values for $_SESSION before the next request
     */
    protected function withSession(array $session): static
    {
        $_SESSION = $session;

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function withHeaders(array $headers): static
    {
        $this->nextHeaders = $headers;

        return $this;
    }

    protected function get(string $path, array $query = []): TestResponse
    {
        return $this->call('GET', $path, $query);
    }

    /**
     * @param array<string, mixed> $data form fields
     */
    protected function post(string $path, array $data = [], bool $withCsrf = false): TestResponse
    {
        if ($withCsrf) {
            Services::reset();
            $data[Csrf::tokenName()] = Csrf::hash();
        }

        return $this->call('POST', $path, $data);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function call(string $method, string $path, array $params = []): TestResponse
    {
        Services::reset(); // a new request: no shared state except the DB connection
        $uri    = '/' . ltrim($path, '/');
        $query  = $method === 'GET' && $params !== [] ? http_build_query($params) : '';
        $server = [
            'REQUEST_METHOD'  => $method,
            'REQUEST_URI'     => $uri . ($query !== '' ? '?' . $query : ''),
            'QUERY_STRING'    => $query,
            'REMOTE_ADDR'     => '127.0.0.1',
            'HTTP_HOST'       => (string) parse_url(config('App')->baseURL, PHP_URL_HOST),
            'HTTP_USER_AGENT' => 'qms-tests',
        ];
        foreach ($this->nextHeaders as $name => $value) {
            $key          = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }
        $this->nextHeaders = [];

        $request = new Request($server, $method === 'GET' ? $params : [], $method === 'GET' ? [] : $params);
        Services::injectMock('request', $request);

        return new TestResponse((new App())->handle($request));
    }
}

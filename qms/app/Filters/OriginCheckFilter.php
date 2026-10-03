<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Defence in depth on top of the CSRF token: state-changing requests whose
 * Origin (or, failing that, Referer) is another site are refused.
 */
class OriginCheckFilter implements FilterInterface
{
    use FilterResponses;

    public function before(RequestInterface $request, $arguments = null)
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true) || is_cli()) {
            return null;
        }

        $source = $request->getHeaderLine('Origin');
        if ($source === '' || $source === 'null') {
            $source = $request->getHeaderLine('Referer');
        }
        if ($source === '') {
            return null; // CSRF token still required
        }

        $expected = parse_url(config('App')->baseURL);
        $actual   = parse_url($source);
        $sameHost = isset($actual['host'], $expected['host']) && strcasecmp($actual['host'], $expected['host']) === 0;
        $samePort = ($actual['port'] ?? null) === ($expected['port'] ?? null);

        if (! $sameHost || ! $samePort) {
            return $this->errorResponse($request, 403, 'Request refused: it did not come from this application.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

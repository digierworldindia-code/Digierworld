<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Authenticates AI service tokens on /api/ai/* only. These routes have no
 * session and no CSRF; a token works nowhere else (every other route needs
 * an interactive session). Each controller action then checks the specific
 * capability and record scope through the AI gateway.
 */
class AiTokenFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');
        $plain  = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : '';
        $gw     = service('aiGateway');
        [$token, $why] = $plain === '' ? [null, 'missing_token'] : $gw->authenticate($plain);
        if ($token === null) {
            $gw->log('denied', null, null, [], [], ['deny_reason' => $why, 'http_path' => $request->getUri()->getPath(), 'actor_user_id' => null]);

            return service('response')->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'unauthorized']);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response->setHeader('Cache-Control', 'no-store');
    }
}

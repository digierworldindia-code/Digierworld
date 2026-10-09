<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

/**
 * Consistent JSON envelope: {"data": ..., "meta": ...} or {"error": {...}}.
 */
abstract class BaseApi extends BaseController
{
    use ResponseTrait;

    protected $format = 'json';

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        if (str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ')) {
            auth()->setAuthenticator('tokens');
        }
    }

    protected function ok(mixed $data, array $meta = [], int $status = 200)
    {
        return $this->respond(['data' => $data] + ($meta ? ['meta' => $meta] : []), $status);
    }

    protected function error(string $message, int $status = 400, array $details = [])
    {
        return $this->respond(['error' => ['status' => $status, 'message' => $message] + ($details ? ['details' => $details] : [])], $status);
    }

    /**
     * Viewer for API calls: token-authenticated users see what they would see
     * on the website; anonymous callers get guest visibility.
     */
    protected function viewer(): \App\Libraries\Viewer
    {
        $auth = auth('tokens');
        if ($auth->loggedIn()) {
            auth()->setAuthenticator('tokens');

            return service('visibility')->current();
        }
        if (($h = $this->request->getHeaderLine('Authorization')) !== '' && str_starts_with($h, 'Bearer ')) {
            $result = auth('tokens')->check(['token' => substr($h, 7)]);
            if ($result->isOK()) {
                auth('tokens')->login($result->extraInfo());
                auth()->setAuthenticator('tokens');
                service('visibility')->reset();

                return service('visibility')->current();
            }
        }

        return \App\Libraries\Viewer::guest();
    }
}

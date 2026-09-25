<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The whole /admin tree sits behind this one gate, registered on the route
 * group, so a new admin route cannot forget it. A dealer account is refused
 * here regardless of what permissions its role holds — the previous platform
 * learned that the hard way, when a shared permission let dealers reach staff
 * endpoints.
 */
class StaffFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $context = service('requestContext');
        if ($context->isStaff()) {
            return null;
        }

        log_message('warning', 'security.STAFF_AREA_REFUSED user={user} path={path}', [
            'user' => $context->userId() ?? 'anonymous', 'path' => $request->getUri()->getPath(),
        ]);

        return $context->isDealer()
            ? redirect()->to(site_url('dealer'))->with('error', 'That area is for POLYFIX staff.')
            : service('response')->setStatusCode(403)->setBody(view('errors/html/error_403'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

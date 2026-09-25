<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** The dealer portal answers only accounts linked to a dealership. */
class DealerFilter implements FilterInterface
{
    use MarksPrivate;

    public function before(RequestInterface $request, $arguments = null)
    {
        $context = service('requestContext');
        if ($context->isDealer()) {
            return null;
        }

        return $this->private($context->isStaff()
            ? redirect()->to(site_url('admin'))->with('notice', 'The dealer portal is for dealership accounts.')
            : service('response')->setStatusCode(403)->setBody(view('errors/html/error_403')));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

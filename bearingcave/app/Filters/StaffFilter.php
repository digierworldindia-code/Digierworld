<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin area gate: logged-in BearingCave staff with admin.access.
 * Non-staff receive a 404 so the admin surface is not advertised.
 */
class StaffFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            session()->set('beforeLoginUrl', current_url());

            return redirect()->to(site_url('login'));
        }
        if (! auth()->user()->can('admin.access')) {
            service('audit')->security('access.admin_denied', 'Non-staff user requested ' . current_url());

            throw PageNotFoundException::forPageNotFound();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

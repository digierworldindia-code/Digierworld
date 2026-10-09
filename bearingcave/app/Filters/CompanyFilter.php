<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a logged-in member of a company of the given type.
 * Usage: 'company:buyer' or 'company:supplier'.
 */
class CompanyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            session()->set('beforeLoginUrl', current_url());

            return redirect()->to(site_url('login'))->with('error', 'Please sign in to continue.');
        }
        $type    = $arguments[0] ?? null;
        $company = service('companyContext')->company();
        if ($company === null || ($type !== null && $company['company_type'] !== $type)) {
            service('audit')->security('access.wrong_area', 'User tried to open the ' . ($type ?? 'company') . ' area: ' . current_url());

            return redirect()->to(site_url('dashboard'))->with('error', 'That area is not available for your account.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

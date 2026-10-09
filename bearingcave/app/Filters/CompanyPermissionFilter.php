<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\CompanyPermissions;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a company-member permission, e.g. 'member:inventory.manage'.
 * Owners and company admins always pass.
 */
class CompanyPermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $perm = $arguments[0] ?? '';
        if (! service('companyContext')->can($perm)) {
            service('audit')->security('access.member_denied', "Missing company permission {$perm} for " . current_url());
            $company = service('companyContext')->company();

            return redirect()->to(site_url(($company['company_type'] ?? 'buyer') . '/dashboard'))
                ->with('error', 'Your company administrator has not granted you "' . (CompanyPermissions::ALL[$perm] ?? $perm) . '".');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

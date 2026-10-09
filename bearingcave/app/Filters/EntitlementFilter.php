<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\EntitlementService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a membership feature, e.g. 'entitled:bulk_import'.
 */
class EntitlementFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $feature = $arguments[0] ?? '';
        $company = service('companyContext')->company();
        if ($company === null || ! service('entitlements')->has($company, $feature)) {
            $label = EntitlementService::FEATURES[$feature][0] ?? $feature;
            $to    = ($company['company_type'] ?? 'buyer') === 'supplier' ? 'supplier/membership' : 'buyer/verification';

            return redirect()->to(site_url($to))->with('error', "\"{$label}\" is not included in your current plan.");
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

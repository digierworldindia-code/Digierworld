<?php

namespace App\Controllers;

use App\Libraries\MenuBuilder;
use App\Services\Dashboard\DashboardService;

/**
 * Owner/role dashboard. Every widget is computed only if the user holds
 * its permission — the JSON endpoint applies the same gates, so KPIs can't
 * be inferred by calling the API directly.
 */
class DashboardController extends BaseController
{
    public function index()
    {
        // '/' is where sign-in and every "Home" link go; a role without the dashboard lands on its first module instead of a 403
        if (! $this->can('dashboard.view')) {
            $home = MenuBuilder::homeUrl();

            return $home !== null && $home !== '' ? redirect()->to(site_url($home)) : $this->render('dashboard/no_modules', ['pageTitle' => 'Welcome']);
        }
        $service = new DashboardService($this->db, $this->authz);
        [$from, $to, $label] = $service->resolvePeriod((string) $this->request->getGet('period'), $this->request->getGet('from'), $this->request->getGet('to'));

        return $this->render('dashboard/index', [
            'pageTitle'  => 'Dashboard',
            'period'     => (string) ($this->request->getGet('period') ?: 'this_month'),
            'from'       => $from,
            'to'         => $to,
            'label'      => $label,
            'checklist'  => $service->setupChecklist(),
            'widgets'    => $service->widgets($from, $to),
        ]);
    }

    public function data()
    {
        $service = new DashboardService($this->db, $this->authz);
        [$from, $to] = $service->resolvePeriod((string) $this->request->getGet('period'), $this->request->getGet('from'), $this->request->getGet('to'));

        return $this->json(['charts' => $service->charts($from, $to)]);
    }
}

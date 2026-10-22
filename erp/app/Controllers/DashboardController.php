<?php

namespace App\Controllers;

use App\Libraries\Landing;
use App\Libraries\MenuBuilder;
use App\Services\Dashboard\DashboardService;

/**
 * Owner/role dashboard. Every widget is computed only if the user holds
 * its permission — the JSON endpoint applies the same gates, so KPIs can't
 * be inferred by calling the API directly.
 */
class DashboardController extends BaseController
{
    /** Area dashboards: which widget groups they show and which menu section their shortcuts come from. */
    private const AREAS = [
        'billing' => ['title' => 'Billing dashboard', 'widgets' => ['finance', 'my_billing'], 'menu' => 'Billing'],
        'hr'      => ['title' => 'HR dashboard', 'widgets' => ['hr', 'payroll'], 'menu' => 'HR'],
    ];

    public function index()
    {
        // '/' is where every "Home" link goes; a role without the owner dashboard opens its landing page instead of a 403
        if (! $this->can('dashboard.view')) {
            $home = Landing::forCurrentUser();

            return $home !== '' ? redirect()->to(site_url($home)) : $this->render('dashboard/no_modules', ['pageTitle' => 'Welcome']);
        }

        return $this->dashboard(null);
    }

    /** Billing or HR dashboard: the same widgets, limited to that area, plus shortcuts to the area's pages. */
    public function area(string $area)
    {
        if (! isset(self::AREAS[$area])) {
            $this->notFound();
        }
        if (! Landing::canOpen('dashboard/' . $area)) {
            $this->deny();
        }

        return $this->dashboard($area);
    }

    private function dashboard(?string $area)
    {
        $service = new DashboardService($this->db, $this->authz);
        [$from, $to, $label] = $service->resolvePeriod((string) $this->request->getGet('period'), $this->request->getGet('from'), $this->request->getGet('to'));
        $widgets = $service->widgets($from, $to);
        $def     = $area !== null ? self::AREAS[$area] : null;
        if ($def !== null) {
            $widgets = array_intersect_key($widgets, array_flip($def['widgets']));
        }
        $shortcuts = [];
        if ($def !== null) {
            foreach (MenuBuilder::build() as $section) {
                if ($section['label'] === $def['menu']) {
                    $shortcuts = $section['items'];
                }
            }
        }

        return $this->render('dashboard/index', [
            'pageTitle'  => $def['title'] ?? 'Dashboard',
            'path'       => $area !== null ? 'dashboard/' . $area : '/',
            'area'       => $area,
            'period'     => (string) ($this->request->getGet('period') ?: 'this_month'),
            'from'       => $from,
            'to'         => $to,
            'label'      => $label,
            'checklist'  => $area === null ? $service->setupChecklist() : [],
            'widgets'    => $widgets,
            'shortcuts'  => $shortcuts,
            'recent'     => $area === 'billing' ? $service->recentDocuments(10) : [],
        ]);
    }

    public function data()
    {
        $service = new DashboardService($this->db, $this->authz);
        [$from, $to] = $service->resolvePeriod((string) $this->request->getGet('period'), $this->request->getGet('from'), $this->request->getGet('to'));

        return $this->json(['charts' => $service->charts($from, $to)]);
    }
}

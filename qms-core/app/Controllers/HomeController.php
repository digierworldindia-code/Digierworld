<?php

namespace App\Controllers;

/**
 * Start page: the dashboard for people who can see it, otherwise the operator
 * home, otherwise the inspection list.
 */
class HomeController extends BaseController
{
    public function index()
    {
        $authz = service('authorization');

        return match (true) {
            $authz->can('dashboard.view')                                => redirect()->to(site_url('dashboard')),
            $authz->can('inspection.create')                             => redirect()->to(site_url('inspections/home')),
            $authz->can('inspection.view_own', 'inspection.view_all')    => redirect()->to(site_url('inspections')),
            default                                                      => redirect()->to(site_url('account/password'))->with('info', 'Your role has no pages assigned yet. Ask the administrator.'),
        };
    }
}

<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->group('reports', static function (RouteCollection $routes): void {
    $any = 'perm:reports.billing.view,reports.finance.view,reports.gst.view,reports.payroll.view,reports.audit.view,reports.history.view';
    $routes->get('/', 'ReportsController::index', ['filter' => $any]);
    $routes->get('gst', 'ReportsController::gst', ['filter' => 'perm:reports.gst.view']);
    $routes->get('gst/gstr1', 'ReportsController::gstr1', ['filter' => 'perm:reports.gst.view']);
    $routes->get('gst/gstr3b', 'ReportsController::gstr3b', ['filter' => 'perm:reports.gst.view']);
    $routes->get('digest', 'ReportsController::digest', ['filter' => 'perm:reports.finance.view']);
    $routes->get('(:segment)', 'ReportsController::show/$1', ['filter' => $any]);
});

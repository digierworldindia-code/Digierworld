<?php

declare(strict_types=1);

use App\Core\Router;

/**
 * QMS routes. There is no automatic routing: every URL is declared here.
 *
 * Every route inside the 'auth' group carries a permission filter
 * ('permission:<code>[,<code>]' = any of). tests/Feature/RoutePermissionTest
 * fails if a route is added without one. Handlers are classes under
 * App\Controllers; filters are listed in App\Core\App::FILTERS.
 *
 * @var Router $routes
 */

// ---------------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------------
$routes->get('health', 'HealthController::index');
$routes->get('media/logo', 'MediaController::logo');

$routes->group('', ['filter' => 'guest'], static function (Router $routes): void {
    $routes->get('login', 'Auth\LoginController::show');
    $routes->post('login', 'Auth\LoginController::attempt', ['filter' => 'loginthrottle']);
});

// ---------------------------------------------------------------------------
// Logged in (any role)
// ---------------------------------------------------------------------------
$routes->group('', ['filter' => ['auth', 'nostore']], static function (Router $routes): void {
    $routes->get('/', 'HomeController::index');
    $routes->post('logout', 'Auth\LoginController::logout');
    $routes->get('account/password', 'Account\PasswordController::edit');
    $routes->post('account/password', 'Account\PasswordController::update');
});

// ---------------------------------------------------------------------------
// Logged in + permission
// ---------------------------------------------------------------------------
$routes->group('', ['filter' => ['auth', 'nostore']], static function (Router $routes): void {
    $verify = 'permission:inspection.verify_production,inspection.verify_quality,inspection.approve_qa';
    $view   = 'permission:inspection.view_own,inspection.view_all,inspection.create';

    // Dashboard ----------------------------------------------------------------
    $routes->get('dashboard', 'DashboardController::index', ['filter' => 'permission:dashboard.view']);

    // Inspections ---------------------------------------------------------------
    $routes->group('inspections', static function (Router $routes) use ($view, $verify): void {
        $routes->get('home', 'Inspections\InspectionController::home', ['filter' => 'permission:inspection.create']);
        $routes->get('/', 'Inspections\InspectionController::index', ['filter' => 'permission:inspection.view_own,inspection.view_all']);
        $routes->get('new', 'Inspections\InspectionController::new', ['filter' => 'permission:inspection.create']);
        $routes->get('options', 'Inspections\InspectionController::options', ['filter' => 'permission:inspection.create']);
        $routes->post('/', 'Inspections\InspectionController::start', ['filter' => 'permission:inspection.create']);
        $routes->get('(:num)', 'Inspections\InspectionController::show/$1', ['filter' => $view]);
        $routes->get('(:num)/edit', 'Inspections\InspectionController::edit/$1', ['filter' => 'permission:inspection.create']);
        $routes->post('(:num)/draft', 'Inspections\InspectionController::saveDraft/$1', ['filter' => 'permission:inspection.create']);

        $routes->post('(:num)/rounds', 'Inspections\RoundController::add/$1', ['filter' => 'permission:inspection.create']);
        $routes->post('(:num)/rounds/(:num)/sign', 'Inspections\RoundController::sign/$1/$2', ['filter' => 'permission:inspection.create']);
        $routes->post('(:num)/rounds/(:num)/verify', 'Inspections\RoundController::verify/$1/$2', ['filter' => 'permission:inspection.round_verify']);
        $routes->post('(:num)/rounds/(:num)/reopen', 'Inspections\RoundController::reopen/$1/$2', ['filter' => 'permission:inspection.round_reopen']);
        $routes->post('(:num)/rounds/(:num)/delete', 'Inspections\RoundController::delete/$1/$2', ['filter' => 'permission:inspection.create']);

        $routes->post('(:num)/submit', 'Inspections\WorkflowController::submit/$1', ['filter' => 'permission:inspection.submit']);
        $routes->post('(:num)/action', 'Inspections\WorkflowController::act/$1', ['filter' => $verify]);
        $routes->post('(:num)/cancel', 'Inspections\WorkflowController::cancel/$1', ['filter' => 'permission:inspection.cancel_own,inspection.cancel_any']);
        $routes->post('(:num)/revise', 'Inspections\WorkflowController::revise/$1', ['filter' => 'permission:inspection.revise']);

        $routes->get('(:num)/print', 'Inspections\PrintController::html/$1', ['filter' => 'permission:inspection.print']);
        $routes->get('(:num)/pdf', 'Inspections\PrintController::pdf/$1', ['filter' => 'permission:inspection.print']);
    });
    $routes->get('approvals', 'Inspections\ApprovalController::index', ['filter' => $verify]);

    // Templates -------------------------------------------------------------------
    $routes->group('templates', static function (Router $routes): void {
        $manage = ['filter' => 'permission:template.manage'];
        $routes->get('/', 'Templates\TemplateController::index', ['filter' => 'permission:template.view']);
        $routes->get('new', 'Templates\TemplateController::new', $manage);
        $routes->post('/', 'Templates\TemplateController::create', $manage);
        $routes->get('(:num)', 'Templates\TemplateController::show/$1', ['filter' => 'permission:template.view']);
        $routes->get('(:num)/preview', 'Templates\TemplateController::preview/$1', ['filter' => 'permission:template.view']);
        $routes->post('(:num)', 'Templates\TemplateController::update/$1', $manage);
        $routes->post('(:num)/delete', 'Templates\TemplateController::delete/$1', $manage);
        $routes->post('(:num)/sections', 'Templates\TemplateController::addSection/$1', $manage);
        $routes->post('(:num)/sections/(:num)', 'Templates\TemplateController::updateSection/$1/$2', $manage);
        $routes->post('(:num)/sections/(:num)/delete', 'Templates\TemplateController::deleteSection/$1/$2', $manage);
        $routes->post('(:num)/sections/(:num)/move', 'Templates\TemplateController::moveSection/$1/$2', $manage);
        $routes->post('(:num)/parameters', 'Templates\TemplateController::saveParameter/$1', $manage);
        $routes->post('(:num)/parameters/(:num)/delete', 'Templates\TemplateController::deleteParameter/$1/$2', $manage);
        $routes->post('(:num)/parameters/(:num)/move', 'Templates\TemplateController::moveParameter/$1/$2', $manage);
        $routes->post('(:num)/mapping', 'Templates\TemplateController::saveMapping/$1', $manage);
        $routes->post('(:num)/new-version', 'Templates\TemplateController::newVersion/$1', $manage);
        $routes->post('(:num)/publish', 'Templates\TemplateController::publish/$1', ['filter' => 'permission:template.publish']);
        $routes->post('(:num)/retire', 'Templates\TemplateController::retire/$1', ['filter' => 'permission:template.publish']);
    });

    // Master data (generic engine; per-entity permission checked again in the service)
    $routes->group('masters', static function (Router $routes): void {
        $edit = 'permission:master.machines,master.parts,master.employees,master.shifts,master.library';
        $routes->get('/', 'Masters\MasterController::overview', ['filter' => 'permission:master.view']);
        $routes->get('(:segment)', 'Masters\MasterController::index/$1', ['filter' => 'permission:master.view']);
        $routes->get('(:segment)/new', 'Masters\MasterController::new/$1', ['filter' => $edit]);
        $routes->post('(:segment)', 'Masters\MasterController::create/$1', ['filter' => $edit]);
        $routes->get('(:segment)/(:num)/edit', 'Masters\MasterController::edit/$1/$2', ['filter' => $edit]);
        $routes->post('(:segment)/(:num)', 'Masters\MasterController::update/$1/$2', ['filter' => $edit]);
        $routes->post('(:segment)/(:num)/toggle', 'Masters\MasterController::toggle/$1/$2', ['filter' => $edit]);
    });

    // Gauges ----------------------------------------------------------------------------
    $routes->group('gauges', static function (Router $routes): void {
        $routes->get('/', 'Gauges\GaugeController::index', ['filter' => 'permission:gauge.view']);
        $routes->get('new', 'Gauges\GaugeController::new', ['filter' => 'permission:gauge.manage']);
        $routes->post('/', 'Gauges\GaugeController::create', ['filter' => 'permission:gauge.manage']);
        $routes->get('(:num)', 'Gauges\GaugeController::show/$1', ['filter' => 'permission:gauge.view']);
        $routes->get('(:num)/edit', 'Gauges\GaugeController::edit/$1', ['filter' => 'permission:gauge.manage']);
        $routes->post('(:num)', 'Gauges\GaugeController::update/$1', ['filter' => 'permission:gauge.manage']);
        $routes->post('(:num)/calibrations', 'Gauges\GaugeController::calibrate/$1', ['filter' => 'permission:gauge.calibrate']);
        $routes->get('calibrations/(:num)/certificate', 'Gauges\GaugeController::certificate/$1', ['filter' => 'permission:gauge.view']);
    });

    // Reports -----------------------------------------------------------------------------
    $routes->get('reports', 'Reports\ReportController::index', ['filter' => 'permission:report.view']);
    $routes->get('reports/(:segment)', 'Reports\ReportController::show/$1', ['filter' => 'permission:report.view']);
    $routes->get('reports/(:segment)/export', 'Reports\ReportController::export/$1', ['filter' => 'permission:report.export']);

    // Administration ------------------------------------------------------------------------
    $routes->group('admin', static function (Router $routes): void {
        $users = ['filter' => 'permission:user.manage'];
        $routes->get('users', 'Admin\UserController::index', $users);
        $routes->get('users/new', 'Admin\UserController::new', $users);
        $routes->post('users', 'Admin\UserController::create', $users);
        $routes->get('users/(:num)/edit', 'Admin\UserController::edit/$1', $users);
        $routes->post('users/(:num)', 'Admin\UserController::update/$1', $users);
        $routes->post('users/(:num)/reset-password', 'Admin\UserController::resetPassword/$1', $users);
        $routes->post('users/(:num)/unlock', 'Admin\UserController::unlock/$1', $users);
        $routes->post('users/(:num)/status', 'Admin\UserController::toggleStatus/$1', $users);

        $roles = ['filter' => 'permission:role.manage'];
        $routes->get('roles', 'Admin\RoleController::index', $roles);
        $routes->post('roles', 'Admin\RoleController::create', $roles);
        $routes->get('roles/(:num)', 'Admin\RoleController::edit/$1', $roles);
        $routes->post('roles/(:num)', 'Admin\RoleController::update/$1', $roles);
        $routes->post('roles/(:num)/delete', 'Admin\RoleController::delete/$1', $roles);

        $settings = ['filter' => 'permission:setting.manage'];
        $routes->get('settings', 'Admin\SettingsController::index', $settings);
        $routes->post('settings/logo', 'Admin\SettingsController::logo', $settings);
        $routes->post('settings/report-types/(:num)', 'Admin\SettingsController::reportType/$1', $settings);
        $routes->post('settings/google-test', 'Admin\SettingsController::googleTest', $settings);
        $routes->post('settings/(:segment)', 'Admin\SettingsController::update/$1', $settings);

        $routes->get('audit', 'Admin\AuditLogController::index', ['filter' => 'permission:audit.view']);
        $routes->get('login-logs', 'Admin\LoginLogController::index', ['filter' => 'permission:loginlog.view']);

        $routes->get('sync', 'Admin\SyncController::index', ['filter' => 'permission:sync.view']);
        $routes->post('sync/(:num)/retry', 'Admin\SyncController::retry/$1', ['filter' => 'permission:sync.manage']);
        $routes->post('sync/retry-failed', 'Admin\SyncController::retryFailed', ['filter' => 'permission:sync.manage']);
        $routes->post('sync/backfill', 'Admin\SyncController::backfill', ['filter' => 'permission:sync.manage']);
    });
});

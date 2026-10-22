<?php

use CodeIgniter\Router\RouteCollection;

/**
 * Every authenticated route passes the global `session` + `guard` filters
 * (see Config\Filters). Each route below adds its `perm:` (any-of) filter;
 * controllers re-check record scope. Routes without `perm:` are
 * self-service endpoints that only ever touch the caller's own data.
 *
 * @var RouteCollection $routes
 */
$routes->setAutoRoute(false);

service('auth')->routes($routes, ['except' => ['register']]);

$routes->get('health', 'HealthController::index');

// ---- Overview / self-service ----
$routes->get('/', 'DashboardController::index'); // signed-in users only (global session filter); without dashboard.view it opens the user's first module
$routes->get('dashboard/data', 'DashboardController::data', ['filter' => 'perm:dashboard.view']);
$routes->get('brand/logo', 'BrandController::logo');
$routes->get('profile', 'ProfileController::index');
$routes->get('profile/password', 'ProfileController::password');
$routes->post('profile/password', 'ProfileController::updatePassword');
$routes->post('profile/sessions/(:num)/revoke', 'ProfileController::revokeSession/$1');
$routes->get('calendar', 'CalendarController::index', ['filter' => 'perm:calendar.view']);
$routes->get('notifications', 'NotificationsController::index');
$routes->get('notifications/feed', 'NotificationsController::feed');
$routes->post('notifications/read-all', 'NotificationsController::readAll');
$routes->get('notifications/(:num)/open', 'NotificationsController::open/$1');
$routes->get('search', 'SearchController::index');
$routes->get('search/suggest', 'SearchController::suggest');
$routes->get('files/(:num)', 'FilesController::download/$1');

// ---- Administration ----
$routes->group('admin', static function (RouteCollection $routes): void {
    $routes->get('users', 'Admin\UsersController::index', ['filter' => 'perm:security.users.view,security.users.manage']);
    $routes->get('users/new', 'Admin\UsersController::new', ['filter' => 'perm:security.users.manage']);
    $routes->post('users', 'Admin\UsersController::create', ['filter' => 'perm:security.users.manage']);
    $routes->get('users/(:num)', 'Admin\UsersController::show/$1', ['filter' => 'perm:security.users.view,security.users.manage']);
    $routes->post('users/(:num)', 'Admin\UsersController::update/$1', ['filter' => 'perm:security.users.manage']);
    $routes->post('users/(:num)/roles', 'Admin\UsersController::updateRoles/$1', ['filter' => 'perm:security.users.manage']);
    $routes->post('users/(:num)/overrides', 'Admin\UsersController::addOverride/$1', ['filter' => 'perm:security.roles.manage']);
    $routes->post('users/(:num)/overrides/(:num)/delete', 'Admin\UsersController::deleteOverride/$1/$2', ['filter' => 'perm:security.roles.manage']);
    $routes->post('users/(:num)/scopes', 'Admin\UsersController::updateScopes/$1', ['filter' => 'perm:security.roles.manage']);
    $routes->post('users/(:num)/password', 'Admin\UsersController::resetPassword/$1', ['filter' => 'perm:security.users.manage']);
    $routes->post('users/(:num)/toggle', 'Admin\UsersController::toggleActive/$1', ['filter' => 'perm:security.users.manage']);
    $routes->post('users/(:num)/sessions/revoke', 'Admin\UsersController::revokeSessions/$1', ['filter' => 'perm:security.sessions.manage']);
    $routes->get('roles', 'Admin\RolesController::index', ['filter' => 'perm:security.roles.manage']);
    $routes->get('roles/new', 'Admin\RolesController::new', ['filter' => 'perm:security.roles.manage']);
    $routes->post('roles', 'Admin\RolesController::create', ['filter' => 'perm:security.roles.manage']);
    $routes->get('roles/(:num)', 'Admin\RolesController::edit/$1', ['filter' => 'perm:security.roles.manage']);
    $routes->post('roles/(:num)', 'Admin\RolesController::update/$1', ['filter' => 'perm:security.roles.manage']);
    $routes->post('roles/(:num)/clone', 'Admin\RolesController::clone/$1', ['filter' => 'perm:security.roles.manage']);
    $routes->post('roles/(:num)/delete', 'Admin\RolesController::delete/$1', ['filter' => 'perm:security.roles.manage']);
    $routes->get('roles/matrix', 'Admin\RolesController::matrix', ['filter' => 'perm:security.roles.manage']);
    $routes->get('sessions', 'Admin\SessionsController::index', ['filter' => 'perm:security.sessions.manage']);
    $routes->post('sessions/(:num)/revoke', 'Admin\SessionsController::revoke/$1', ['filter' => 'perm:security.sessions.manage']);
    $routes->get('audit', 'Admin\AuditController::index', ['filter' => 'perm:security.audit.view']);
    $routes->get('audit/verify', 'Admin\AuditController::verify', ['filter' => 'perm:security.audit.view']);
    $routes->get('audit/(:num)', 'Admin\AuditController::show/$1', ['filter' => 'perm:security.audit.view']);
    $routes->get('diagnostics', 'Admin\DiagnosticsController::index', ['filter' => 'perm:security.diagnostics.view']);
    $routes->get('backups', 'Admin\BackupsController::index', ['filter' => 'perm:security.backup.manage']);
    $routes->post('backups', 'Admin\BackupsController::create', ['filter' => 'perm:security.backup.manage']);
    $routes->get('backups/(:segment)', 'Admin\BackupsController::download/$1', ['filter' => 'perm:security.backup.manage']);
    $routes->get('ai', 'Admin\AiController::index', ['filter' => 'perm:ai.permissions.manage,ai.usage.view']);
    $routes->post('ai/provider', 'Admin\AiController::saveProvider', ['filter' => 'perm:ai.permissions.manage']);
    $routes->post('ai/capabilities/(:segment)', 'Admin\AiController::saveCapability/$1', ['filter' => 'perm:ai.permissions.manage']);
    $routes->post('ai/tokens', 'Admin\AiController::issueToken', ['filter' => 'perm:ai.permissions.manage']);
    $routes->post('ai/tokens/(:num)/revoke', 'Admin\AiController::revokeToken/$1', ['filter' => 'perm:ai.permissions.manage']);
});

// ---- AI service-token API (no session; each action checks capability + record scope) ----
$routes->group('api/ai', ['filter' => 'aitoken'], static function (RouteCollection $routes): void {
    $routes->get('recruitment/candidates/(:num)/evaluation-input', 'Api\AiController::evaluationInput/$1');
    $routes->post('recruitment/candidates/(:num)/evaluation', 'Api\AiController::submitEvaluation/$1');
    $routes->post('recruitment/candidates/(:num)/timeline-proposals', 'Api\AiController::proposeTimeline/$1');
    $routes->get('payroll/summary', 'Api\AiController::payrollSummary');
    $routes->get('payroll/period-summary', 'Api\AiController::payrollPeriod');
    $routes->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '(:any)', 'Api\AiController::refuse/$1');
});

// ---- Settings ----
$routes->group('settings', static function (RouteCollection $routes): void {
    $routes->get('setup', 'Settings\SetupWizardController::index', ['filter' => 'perm:settings.company.edit']);
    $routes->get('company', 'Settings\CompanyController::index', ['filter' => 'perm:settings.company.view,settings.company.edit']);
    $routes->post('company', 'Settings\CompanyController::update', ['filter' => 'perm:settings.company.edit']);
    $routes->get('branding', 'Settings\BrandingController::index', ['filter' => 'perm:settings.branding.edit']);
    $routes->post('branding', 'Settings\BrandingController::upload', ['filter' => 'perm:settings.branding.edit']);
    $routes->post('branding/(:num)', 'Settings\BrandingController::update/$1', ['filter' => 'perm:settings.branding.edit']);
    $routes->get('branding/(:num)/preview', 'Settings\BrandingController::preview/$1', ['filter' => 'perm:settings.branding.edit']);
    $routes->get('banks', 'Settings\BanksController::index', ['filter' => 'perm:settings.bank.view_masked,settings.bank.edit']);
    $routes->get('banks/new', 'Settings\BanksController::new', ['filter' => 'perm:settings.bank.edit']);
    $routes->post('banks', 'Settings\BanksController::create', ['filter' => 'perm:settings.bank.edit']);
    $routes->get('banks/(:num)', 'Settings\BanksController::edit/$1', ['filter' => 'perm:settings.bank.edit']);
    $routes->post('banks/(:num)', 'Settings\BanksController::update/$1', ['filter' => 'perm:settings.bank.edit']);
    $routes->post('banks/(:num)/reveal', 'Settings\BanksController::reveal/$1', ['filter' => 'perm:settings.bank.view_full,settings.bank.view_cif']);
    $routes->get('sequences', 'Settings\SequencesController::index', ['filter' => 'perm:settings.sequences.manage']);
    $routes->post('sequences/(:num)', 'Settings\SequencesController::update/$1', ['filter' => 'perm:settings.sequences.manage']);
    $routes->get('tax', 'Settings\TaxController::index', ['filter' => 'perm:settings.tax.edit']);
    $routes->post('tax/rates', 'Settings\TaxController::saveRate', ['filter' => 'perm:settings.tax.edit']);
    $routes->post('tax/rates/(:num)', 'Settings\TaxController::saveRate/$1', ['filter' => 'perm:settings.tax.edit']);
    $routes->post('tax/treatments/(:segment)', 'Settings\TaxController::saveTreatment/$1', ['filter' => 'perm:settings.tax.edit']);
    $routes->get('templates', 'Settings\TemplatesController::index', ['filter' => 'perm:settings.templates.edit']);
    $routes->post('templates/(:num)', 'Settings\TemplatesController::update/$1', ['filter' => 'perm:settings.templates.edit']);
    $routes->get('templates/(:num)/preview', 'Settings\TemplatesController::preview/$1', ['filter' => 'perm:settings.templates.edit']);
    $routes->get('signature-policy', 'Settings\SignaturePolicyController::index', ['filter' => 'perm:settings.signature_policy.manage']);
    $routes->post('signature-policy', 'Settings\SignaturePolicyController::update', ['filter' => 'perm:settings.signature_policy.manage']);
    $routes->get('integrations', 'Settings\IntegrationsController::index', ['filter' => 'perm:settings.integrations.manage']);
    $routes->post('integrations/(:segment)', 'Settings\IntegrationsController::save/$1', ['filter' => 'perm:settings.integrations.manage']);
    $routes->post('integrations/(:segment)/test', 'Settings\IntegrationsController::test/$1', ['filter' => 'perm:settings.integrations.manage']);
    $routes->get('masters', 'Settings\MastersController::index', ['filter' => 'perm:settings.app.edit,crm.settings.manage,finance.expense.manage_categories,hr.leave.manage_types,hr.employee.manage']);
    $routes->get('masters/(:segment)', 'Settings\MastersController::list/$1', ['filter' => 'perm:settings.app.edit,crm.settings.manage,finance.expense.manage_categories,hr.leave.manage_types,hr.employee.manage']);
    $routes->post('masters/(:segment)', 'Settings\MastersController::save/$1', ['filter' => 'perm:settings.app.edit,crm.settings.manage,finance.expense.manage_categories,hr.leave.manage_types,hr.employee.manage']);
    $routes->post('masters/(:segment)/(:num)', 'Settings\MastersController::save/$1/$2', ['filter' => 'perm:settings.app.edit,crm.settings.manage,finance.expense.manage_categories,hr.leave.manage_types,hr.employee.manage']);
});

// Module route files (added per phase)
foreach (glob(APPPATH . 'Config/Routes/*.php') ?: [] as $routeFile) {
    require $routeFile;
}

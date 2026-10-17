<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->group('crm', static function (RouteCollection $routes): void {
    $view = 'perm:crm.lead.view_assigned,crm.lead.view_team,crm.lead.view_all';
    $routes->get('today', 'Crm\CrmController::today', ['filter' => $view]);
    $routes->get('pipeline', 'Crm\CrmController::pipeline', ['filter' => $view]);
    $routes->get('calls', 'Crm\CrmController::calls', ['filter' => $view]);
    $routes->get('performance', 'Crm\CrmController::performance', ['filter' => 'perm:crm.reports.view_own,crm.reports.view_team']);
    $routes->post('targets', 'Crm\CrmController::saveTarget', ['filter' => 'perm:crm.settings.manage']);
    $routes->get('export', 'Crm\CrmController::export', ['filter' => 'perm:crm.export']);

    $routes->get('leads', 'Crm\LeadsController::index', ['filter' => $view]);
    $routes->get('leads/lookup', 'Crm\LeadsController::lookup', ['filter' => $view]);
    $routes->get('leads/new', 'Crm\LeadsController::new', ['filter' => 'perm:crm.lead.create']);
    $routes->post('leads', 'Crm\LeadsController::create', ['filter' => 'perm:crm.lead.create']);
    $routes->get('leads/import', 'Crm\ImportController::form', ['filter' => 'perm:crm.lead.import']);
    $routes->post('leads/import', 'Crm\ImportController::preview', ['filter' => 'perm:crm.lead.import']);
    $routes->get('leads/import/review', 'Crm\ImportController::review', ['filter' => 'perm:crm.lead.import']);
    $routes->post('leads/import/commit', 'Crm\ImportController::commit', ['filter' => 'perm:crm.lead.import']);
    $routes->get('leads/(:num)', 'Crm\LeadsController::show/$1', ['filter' => $view]);
    $routes->get('leads/(:num)/edit', 'Crm\LeadsController::edit/$1', ['filter' => 'perm:crm.lead.edit']);
    $routes->post('leads/(:num)', 'Crm\LeadsController::update/$1', ['filter' => 'perm:crm.lead.edit']);
    $routes->post('leads/(:num)/call', 'Crm\LeadsController::call/$1', ['filter' => 'perm:crm.call.create']);
    $routes->post('leads/(:num)/stage', 'Crm\LeadsController::stage/$1', ['filter' => 'perm:crm.lead.edit']);
    $routes->post('leads/(:num)/assign', 'Crm\LeadsController::assign/$1', ['filter' => 'perm:crm.lead.assign']);
    $routes->post('leads/(:num)/dnd', 'Crm\LeadsController::dnd/$1', ['filter' => 'perm:crm.lead.edit']);
    $routes->post('leads/(:num)/archive', 'Crm\LeadsController::archive/$1', ['filter' => 'perm:crm.lead.delete']);
    $routes->post('leads/(:num)/followups', 'Crm\LeadsController::followup/$1', ['filter' => 'perm:crm.followup.manage']);
    $routes->post('leads/(:num)/convert', 'Crm\LeadsController::convert/$1', ['filter' => 'perm:crm.lead.convert']);

    $routes->get('followups', 'Crm\FollowupsController::index', ['filter' => 'perm:crm.followup.manage']);
    $routes->post('followups/(:num)/complete', 'Crm\FollowupsController::complete/$1', ['filter' => 'perm:crm.followup.manage']);

    $routes->get('visits', 'Crm\VisitsController::index', ['filter' => 'perm:crm.visit.view']);
    $routes->get('visits/new', 'Crm\VisitsController::new', ['filter' => 'perm:crm.visit.view']);
    $routes->post('visits', 'Crm\VisitsController::create', ['filter' => 'perm:crm.visit.view']);
    $routes->get('visits/(:num)', 'Crm\VisitsController::show/$1', ['filter' => 'perm:crm.visit.view']);
    $routes->post('visits/(:num)/complete', 'Crm\VisitsController::complete/$1', ['filter' => 'perm:crm.visit.view']);

    $routes->get('meetings', 'Crm\MeetingsController::index', ['filter' => 'perm:crm.meeting.manage']);
    $routes->post('meetings', 'Crm\MeetingsController::create', ['filter' => 'perm:crm.meeting.manage']);
    $routes->post('meetings/(:num)/complete', 'Crm\MeetingsController::complete/$1', ['filter' => 'perm:crm.meeting.manage']);

    $routes->get('tasks', 'Crm\TasksController::index', ['filter' => 'perm:crm.task.view_own,crm.task.view_all']);
    $routes->post('tasks', 'Crm\TasksController::create', ['filter' => 'perm:crm.task.create']);
    $routes->post('tasks/(:num)/status', 'Crm\TasksController::status/$1', ['filter' => 'perm:crm.task.view_own,crm.task.view_all']);

    $routes->get('sync', 'Crm\SyncController::index', ['filter' => 'perm:crm.sheets.view,crm.sheets.manage']);
    $routes->post('sync/settings', 'Crm\SyncController::save', ['filter' => 'perm:crm.sheets.manage']);
    $routes->post('sync/test', 'Crm\SyncController::test', ['filter' => 'perm:crm.sheets.manage']);
    $routes->post('sync/run', 'Crm\SyncController::run', ['filter' => 'perm:crm.sheets.manage']);
    $routes->post('sync/retry', 'Crm\SyncController::retry', ['filter' => 'perm:crm.sheets.manage']);
    $routes->post('sync/queue-all', 'Crm\SyncController::queueAll', ['filter' => 'perm:crm.sheets.manage']);
});

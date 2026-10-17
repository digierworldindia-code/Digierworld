<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->group('contacts', static function (RouteCollection $routes): void {
    $routes->get('/', 'ContactsController::index', ['filter' => 'perm:contacts.view_all']);
    $routes->get('export', 'ContactsController::export', ['filter' => 'perm:contacts.export']);
    $routes->get('new', 'ContactsController::new', ['filter' => 'perm:contacts.create']);
    $routes->post('/', 'ContactsController::create', ['filter' => 'perm:contacts.create']);
    $routes->get('lookup', 'ContactsController::lookup', ['filter' => 'perm:contacts.view_billing,contacts.view_all']);
    $routes->get('duplicates', 'ContactsController::duplicates', ['filter' => 'perm:contacts.create,contacts.edit']);
    $routes->get('gstin-check', 'ContactsController::gstinCheck', ['filter' => 'perm:contacts.create,contacts.edit,contacts.view_billing']);
    $routes->get('import', 'ContactsController::importForm', ['filter' => 'perm:contacts.import']);
    $routes->get('import/template', 'ContactsController::importTemplate', ['filter' => 'perm:contacts.import']);
    $routes->post('import', 'ContactsController::importPreview', ['filter' => 'perm:contacts.import']);
    $routes->post('import/commit', 'ContactsController::importCommit', ['filter' => 'perm:contacts.import']);
    $routes->get('(:num)', 'ContactsController::show/$1', ['filter' => 'perm:contacts.view_all']);
    $routes->get('(:num)/billing', 'ContactsController::billing/$1', ['filter' => 'perm:contacts.view_billing,contacts.view_all']);
    $routes->get('(:num)/edit', 'ContactsController::edit/$1', ['filter' => 'perm:contacts.edit']);
    $routes->post('(:num)', 'ContactsController::update/$1', ['filter' => 'perm:contacts.edit']);
    $routes->post('(:num)/archive', 'ContactsController::archive/$1', ['filter' => 'perm:contacts.delete']);
    $routes->get('(:num)/merge', 'ContactsController::mergeForm/$1', ['filter' => 'perm:contacts.merge']);
    $routes->post('(:num)/merge', 'ContactsController::merge/$1', ['filter' => 'perm:contacts.merge']);
    $routes->post('(:num)/files', 'ContactsController::uploadFile/$1', ['filter' => 'perm:contacts.edit']);
});

$routes->group('services', static function (RouteCollection $routes): void {
    $routes->get('/', 'ServicesController::index', ['filter' => 'perm:services.view']);
    $routes->get('lookup', 'ServicesController::lookup', ['filter' => 'perm:services.view']);
    $routes->get('new', 'ServicesController::new', ['filter' => 'perm:services.manage']);
    $routes->post('/', 'ServicesController::create', ['filter' => 'perm:services.manage']);
    $routes->get('(:num)/edit', 'ServicesController::edit/$1', ['filter' => 'perm:services.manage']);
    $routes->post('(:num)', 'ServicesController::update/$1', ['filter' => 'perm:services.manage']);
});

<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$view   = 'perm:billing.invoice.view_own,billing.invoice.view_all,billing.cash_memo.view_own,billing.cash_memo.view_all,billing.estimate.view_own,billing.estimate.view_all,billing.note.view_all';
$create = 'perm:billing.invoice.create_draft,billing.cash_memo.create_draft,billing.estimate.create_draft,billing.note.create_draft';
$edit   = 'perm:billing.invoice.edit_draft,billing.cash_memo.edit_draft,billing.estimate.edit_draft,billing.note.edit_draft';

$routes->group('billing', static function (RouteCollection $routes) use ($view, $create, $edit): void {
    $routes->get('documents', 'Billing\DocumentsController::index', ['filter' => $view]);
    $routes->get('documents/export', 'Billing\DocumentsController::export', ['filter' => 'perm:billing.invoice.export,billing.cash_memo.export,billing.export_all']);
    $routes->get('documents/new', 'Billing\DocumentsController::new', ['filter' => $create]);
    $routes->post('documents', 'Billing\DocumentsController::create', ['filter' => $create]);
    $routes->post('documents/calc', 'Billing\DocumentsController::calc', ['filter' => $create . ',' . substr($edit, 5)]);
    $routes->get('documents/(:num)', 'Billing\DocumentsController::show/$1', ['filter' => $view]);
    $routes->get('documents/(:num)/edit', 'Billing\DocumentsController::edit/$1', ['filter' => $edit]);
    $routes->post('documents/(:num)', 'Billing\DocumentsController::update/$1', ['filter' => $edit]);
    $routes->post('documents/(:num)/delete', 'Billing\DocumentsController::deleteDraft/$1', ['filter' => $edit]);
    $routes->post('documents/(:num)/finalize', 'Billing\DocumentsController::finalize/$1', ['filter' => 'perm:billing.invoice.finalize,billing.cash_memo.finalize,billing.estimate.finalize,billing.issue_credit_note']);
    $routes->get('documents/(:num)/pdf', 'Billing\DocumentsController::pdf/$1', ['filter' => 'perm:billing.invoice.download,billing.invoice.print,billing.cash_memo.download,billing.cash_memo.print,billing.estimate.download,billing.estimate.print,billing.note.download,billing.note.print,billing.invoice.create_draft,billing.cash_memo.create_draft,billing.estimate.create_draft,billing.note.create_draft']);
    $routes->post('documents/(:num)/cancel', 'Billing\DocumentsController::cancel/$1', ['filter' => 'perm:billing.invoice.cancel,billing.cash_memo.cancel,billing.note.cancel']);
    $routes->post('documents/(:num)/duplicate', 'Billing\DocumentsController::duplicate/$1', ['filter' => $create]);
    $routes->post('documents/(:num)/convert', 'Billing\DocumentsController::convert/$1', ['filter' => 'perm:billing.estimate.convert']);
    $routes->post('documents/(:num)/credit-note', 'Billing\DocumentsController::creditNote/$1', ['filter' => 'perm:billing.note.create_draft']);
    $routes->post('documents/(:num)/irn', 'Billing\DocumentsController::recordIrn/$1', ['filter' => 'perm:billing.invoice.finalize,billing.issue_credit_note']);

    $routes->get('recurring', 'Billing\RecurringController::index', ['filter' => 'perm:billing.recurring.manage']);
    $routes->get('recurring/new', 'Billing\RecurringController::new', ['filter' => 'perm:billing.recurring.manage']);
    $routes->post('recurring', 'Billing\RecurringController::create', ['filter' => 'perm:billing.recurring.manage']);
    $routes->get('recurring/(:num)', 'Billing\RecurringController::edit/$1', ['filter' => 'perm:billing.recurring.manage']);
    $routes->post('recurring/(:num)', 'Billing\RecurringController::update/$1', ['filter' => 'perm:billing.recurring.manage']);
    $routes->post('recurring/(:num)/generate', 'Billing\RecurringController::generate/$1', ['filter' => 'perm:billing.recurring.manage']);
});


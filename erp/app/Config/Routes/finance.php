<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->group('finance', static function (RouteCollection $routes): void {
    // Receipts, advances and refunds
    $routes->get('receipts', 'Finance\ReceiptsController::index', ['filter' => 'perm:finance.receipt.view']);
    $routes->get('receipts/export', 'Finance\ReceiptsController::export', ['filter' => 'perm:reports.export']);
    $routes->get('receipts/new', 'Finance\ReceiptsController::new', ['filter' => 'perm:finance.receipt.create']);
    $routes->get('receipts/open-documents', 'Finance\ReceiptsController::openDocuments', ['filter' => 'perm:finance.receipt.create,finance.receipt.allocate']);
    $routes->post('receipts', 'Finance\ReceiptsController::create', ['filter' => 'perm:finance.receipt.create']);
    $routes->get('receipts/(:num)', 'Finance\ReceiptsController::show/$1', ['filter' => 'perm:finance.receipt.view']);
    $routes->get('receipts/(:num)/pdf', 'Finance\ReceiptsController::pdf/$1', ['filter' => 'perm:finance.receipt.view']);
    $routes->post('receipts/(:num)/apply', 'Finance\ReceiptsController::apply/$1', ['filter' => 'perm:finance.receipt.allocate']);
    $routes->post('receipts/(:num)/void', 'Finance\ReceiptsController::void/$1', ['filter' => 'perm:finance.receipt.void']);
    $routes->post('receipts/(:num)/cheque', 'Finance\ReceiptsController::cheque/$1', ['filter' => 'perm:finance.receipt.create']);
    $routes->get('refunds/new', 'Finance\ReceiptsController::newRefund', ['filter' => 'perm:finance.receipt.refund']);
    $routes->post('refunds', 'Finance\ReceiptsController::createRefund', ['filter' => 'perm:finance.receipt.refund']);
});

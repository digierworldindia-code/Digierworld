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

    // Bank / cash accounts and statements
    $routes->get('accounts', 'Finance\AccountsController::index', ['filter' => 'perm:finance.bank.view']);
    $routes->get('accounts/(:num)', 'Finance\AccountsController::show/$1', ['filter' => 'perm:finance.bank.view']);
    $routes->get('accounts/(:num)/export', 'Finance\AccountsController::export/$1', ['filter' => 'perm:finance.bank.view']);

    // Internal transfers (cash deposits etc.) — never income
    $routes->get('transfers', 'Finance\TransfersController::index', ['filter' => 'perm:finance.transfer.view']);
    $routes->get('transfers/export', 'Finance\TransfersController::export', ['filter' => 'perm:reports.export']);
    $routes->get('transfers/new', 'Finance\TransfersController::new', ['filter' => 'perm:finance.transfer.create']);
    $routes->post('transfers', 'Finance\TransfersController::create', ['filter' => 'perm:finance.transfer.create']);
    $routes->get('transfers/(:num)', 'Finance\TransfersController::show/$1', ['filter' => 'perm:finance.transfer.view']);
    $routes->post('transfers/(:num)/void', 'Finance\TransfersController::void/$1', ['filter' => 'perm:finance.transfer.void']);

    // Journal & ledgers
    $routes->get('ledger', 'Finance\LedgerController::index', ['filter' => 'perm:finance.ledger.view']);
    $routes->get('ledger/entries/(:num)', 'Finance\LedgerController::entry/$1', ['filter' => 'perm:finance.ledger.view']);
    $routes->post('ledger/entries/(:num)/reverse', 'Finance\LedgerController::reverse/$1', ['filter' => 'perm:finance.ledger.journal']);
    $routes->get('ledger/accounts', 'Finance\LedgerController::accounts', ['filter' => 'perm:finance.ledger.view']);
    $routes->post('ledger/accounts', 'Finance\LedgerController::createAccount', ['filter' => 'perm:finance.ledger.journal']);
    $routes->get('ledger/accounts/(:num)', 'Finance\LedgerController::account/$1', ['filter' => 'perm:finance.ledger.view']);
    $routes->get('ledger/trial-balance', 'Finance\LedgerController::trialBalance', ['filter' => 'perm:finance.ledger.view']);
    $routes->get('ledger/journal/new', 'Finance\LedgerController::newJournal', ['filter' => 'perm:finance.ledger.journal']);
    $routes->post('ledger/journal', 'Finance\LedgerController::createJournal', ['filter' => 'perm:finance.ledger.journal']);

    // Bank statement import & reconciliation
    $routes->get('reconciliation', 'Finance\ReconciliationController::index', ['filter' => 'perm:finance.bank.reconcile,finance.bank.import']);
    $routes->get('reconciliation/import', 'Finance\ReconciliationController::importForm', ['filter' => 'perm:finance.bank.import']);
    $routes->post('reconciliation/import', 'Finance\ReconciliationController::preview', ['filter' => 'perm:finance.bank.import']);
    $routes->get('reconciliation/map', 'Finance\ReconciliationController::map', ['filter' => 'perm:finance.bank.import']);
    $routes->post('reconciliation/import/commit', 'Finance\ReconciliationController::commit', ['filter' => 'perm:finance.bank.import']);
    $routes->post('reconciliation/lines/(:num)/confirm', 'Finance\ReconciliationController::confirm/$1', ['filter' => 'perm:finance.bank.reconcile']);
    $routes->post('reconciliation/lines/(:num)/unmatch', 'Finance\ReconciliationController::unmatch/$1', ['filter' => 'perm:finance.bank.reconcile']);
    $routes->post('reconciliation/lines/(:num)/ignore', 'Finance\ReconciliationController::ignore/$1', ['filter' => 'perm:finance.bank.reconcile']);
    $routes->post('reconciliation/lines/(:num)/book', 'Finance\ReconciliationController::book/$1', ['filter' => 'perm:finance.bank.reconcile']);
});

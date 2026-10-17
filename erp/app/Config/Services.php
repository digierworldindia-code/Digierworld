<?php

namespace Config;

use App\Services\Access\BillingAccess;
use App\Services\Ai\AiConfig;
use App\Services\Access\CandidateAccess;
use App\Services\Access\LeadAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\DocumentNumberAllocator;
use App\Services\Billing\FinancialYearService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\LedgerService;
use App\Services\Finance\ReceiptService;
use App\Services\Finance\TransferService;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Pdf\PdfRenderer;
use App\Services\Security\UserService;
use App\Services\Files\FileStorage;
use App\Services\Notify\Notifier;
use App\Services\Security\Authorization;
use App\Services\Settings\SettingsStore;
use CodeIgniter\Config\BaseService;

/**
 * Application services. Business services are shared per request and use
 * the default DB connection so they participate in the same transaction.
 */
class Services extends BaseService
{
    public static function authz(bool $getShared = true): Authorization
    {
        if ($getShared) {
            return static::getSharedInstance('authz');
        }

        return new Authorization(db_connect());
    }

    public static function audit(bool $getShared = true): AuditLogger
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return new AuditLogger(db_connect());
    }

    public static function settingsStore(bool $getShared = true): SettingsStore
    {
        if ($getShared) {
            return static::getSharedInstance('settingsStore');
        }

        return new SettingsStore(db_connect());
    }

    public static function files(bool $getShared = true): FileStorage
    {
        if ($getShared) {
            return static::getSharedInstance('files');
        }

        return new FileStorage(db_connect());
    }

    public static function notifier(bool $getShared = true): Notifier
    {
        if ($getShared) {
            return static::getSharedInstance('notifier');
        }

        return new Notifier(db_connect());
    }

    public static function billingAccess(bool $getShared = true): BillingAccess
    {
        if ($getShared) {
            return static::getSharedInstance('billingAccess');
        }

        return new BillingAccess(db_connect(), static::authz());
    }

    public static function leadAccess(bool $getShared = true): LeadAccess
    {
        if ($getShared) {
            return static::getSharedInstance('leadAccess');
        }

        return new LeadAccess(db_connect(), static::authz());
    }

    public static function candidateAccess(bool $getShared = true): CandidateAccess
    {
        if ($getShared) {
            return static::getSharedInstance('candidateAccess');
        }

        return new CandidateAccess(db_connect(), static::authz());
    }

    public static function users(bool $getShared = true): UserService
    {
        if ($getShared) {
            return static::getSharedInstance('users');
        }

        return new UserService(db_connect());
    }

    public static function fy(bool $getShared = true): FinancialYearService
    {
        if ($getShared) {
            return static::getSharedInstance('fy');
        }

        return new FinancialYearService(db_connect());
    }

    public static function aiConfig(bool $getShared = true): AiConfig
    {
        if ($getShared) {
            return static::getSharedInstance('aiConfig');
        }

        return new AiConfig(static::settingsStore());
    }

    public static function numbers(bool $getShared = true): DocumentNumberAllocator
    {
        if ($getShared) {
            return static::getSharedInstance('numbers');
        }

        return new DocumentNumberAllocator(db_connect(), static::fy());
    }

    public static function ledger(bool $getShared = true): LedgerService
    {
        if ($getShared) {
            return static::getSharedInstance('ledger');
        }

        return new LedgerService(db_connect(), static::numbers());
    }

    public static function billing(bool $getShared = true): BillingDocumentService
    {
        if ($getShared) {
            return static::getSharedInstance('billing');
        }

        return new BillingDocumentService(db_connect(), static::ledger(), static::numbers());
    }

    public static function receipts(bool $getShared = true): ReceiptService
    {
        if ($getShared) {
            return static::getSharedInstance('receipts');
        }

        return new ReceiptService(db_connect(), static::ledger(), static::numbers());
    }

    public static function transfers(bool $getShared = true): TransferService
    {
        if ($getShared) {
            return static::getSharedInstance('transfers');
        }

        return new TransferService(db_connect(), static::ledger(), static::numbers());
    }

    public static function expenses(bool $getShared = true): ExpenseService
    {
        if ($getShared) {
            return static::getSharedInstance('expenses');
        }

        return new ExpenseService(db_connect(), static::ledger(), static::numbers());
    }

    public static function pdf(bool $getShared = true): PdfRenderer
    {
        if ($getShared) {
            return static::getSharedInstance('pdf');
        }

        return new PdfRenderer();
    }

    public static function documentPdf(bool $getShared = true): DocumentPdfService
    {
        if ($getShared) {
            return static::getSharedInstance('documentPdf');
        }

        return new DocumentPdfService(db_connect(), static::pdf());
    }

    public static function exports(bool $getShared = true): \App\Services\Reports\ExportService
    {
        if ($getShared) {
            return static::getSharedInstance('exports');
        }

        return new \App\Services\Reports\ExportService(db_connect());
    }

    public static function reconciliation(bool $getShared = true): \App\Services\Finance\ReconciliationService
    {
        if ($getShared) {
            return static::getSharedInstance('reconciliation');
        }

        return new \App\Services\Finance\ReconciliationService(db_connect(), static::ledger());
    }

    public static function sheetsSync(bool $getShared = true): \App\Services\Sync\SheetsSyncService
    {
        if ($getShared) {
            return static::getSharedInstance('sheetsSync');
        }

        return new \App\Services\Sync\SheetsSyncService(db_connect());
    }

    public static function leads(bool $getShared = true): \App\Services\Crm\LeadService
    {
        if ($getShared) {
            return static::getSharedInstance('leads');
        }

        return new \App\Services\Crm\LeadService(db_connect(), static::sheetsSync());
    }
}

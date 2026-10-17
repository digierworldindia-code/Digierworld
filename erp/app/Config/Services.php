<?php

namespace Config;

use App\Services\Access\BillingAccess;
use App\Services\Access\CandidateAccess;
use App\Services\Access\LeadAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\FinancialYearService;
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
}

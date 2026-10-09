<?php

namespace Config;

use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\CartService;
use App\Services\CompanyContext;
use App\Services\CompanyService;
use App\Services\DisputeService;
use App\Services\DocumentService;
use App\Services\EntitlementService;
use App\Services\ImportService;
use App\Services\InspectionService;
use App\Services\InventoryService;
use App\Services\LogisticsService;
use App\Services\MatchingService;
use App\Services\MembershipService;
use App\Services\NotificationService;
use App\Services\NumberGenerator;
use App\Services\OrderService;
use App\Services\PerformanceService;
use App\Services\ProductService;
use App\Services\QuotationService;
use App\Services\RankingService;
use App\Services\ReportService;
use App\Services\RfqService;
use App\Services\RiskService;
use App\Services\SearchService;
use App\Services\SettingsService;
use App\Services\SupplierMasterService;
use App\Services\VerificationService;
use App\Services\VisibilityService;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Every BearingCave domain service is registered here so controllers and
 * other services obtain shared instances through service('name').
 */
class Services extends BaseService
{
    public static function settings_store(bool $getShared = true): SettingsService
    {
        return $getShared ? static::getSharedInstance('settings_store') : new SettingsService();
    }

    public static function audit(bool $getShared = true): AuditService
    {
        return $getShared ? static::getSharedInstance('audit') : new AuditService();
    }

    public static function numbers(bool $getShared = true): NumberGenerator
    {
        return $getShared ? static::getSharedInstance('numbers') : new NumberGenerator();
    }

    public static function notifications(bool $getShared = true): NotificationService
    {
        return $getShared ? static::getSharedInstance('notifications') : new NotificationService();
    }

    public static function companyContext(bool $getShared = true): CompanyContext
    {
        return $getShared ? static::getSharedInstance('companyContext') : new CompanyContext();
    }

    public static function entitlements(bool $getShared = true): EntitlementService
    {
        return $getShared ? static::getSharedInstance('entitlements') : new EntitlementService();
    }

    public static function visibility(bool $getShared = true): VisibilityService
    {
        return $getShared ? static::getSharedInstance('visibility') : new VisibilityService();
    }

    public static function documents(bool $getShared = true): DocumentService
    {
        return $getShared ? static::getSharedInstance('documents') : new DocumentService();
    }

    public static function inventory(bool $getShared = true): InventoryService
    {
        return $getShared ? static::getSharedInstance('inventory') : new InventoryService();
    }

    public static function products(bool $getShared = true): ProductService
    {
        return $getShared ? static::getSharedInstance('products') : new ProductService();
    }

    public static function search(bool $getShared = true): SearchService
    {
        return $getShared ? static::getSharedInstance('search') : new SearchService();
    }

    public static function verification(bool $getShared = true): VerificationService
    {
        return $getShared ? static::getSharedInstance('verification') : new VerificationService();
    }

    public static function risk(bool $getShared = true): RiskService
    {
        return $getShared ? static::getSharedInstance('risk') : new RiskService();
    }

    public static function billing(bool $getShared = true): BillingService
    {
        return $getShared ? static::getSharedInstance('billing') : new BillingService();
    }

    public static function membership(bool $getShared = true): MembershipService
    {
        return $getShared ? static::getSharedInstance('membership') : new MembershipService();
    }

    public static function rfqs(bool $getShared = true): RfqService
    {
        return $getShared ? static::getSharedInstance('rfqs') : new RfqService();
    }

    public static function matching(bool $getShared = true): MatchingService
    {
        return $getShared ? static::getSharedInstance('matching') : new MatchingService();
    }

    public static function quotations(bool $getShared = true): QuotationService
    {
        return $getShared ? static::getSharedInstance('quotations') : new QuotationService();
    }

    public static function cart(bool $getShared = true): CartService
    {
        return $getShared ? static::getSharedInstance('cart') : new CartService();
    }

    public static function orders(bool $getShared = true): OrderService
    {
        return $getShared ? static::getSharedInstance('orders') : new OrderService();
    }

    public static function inspections(bool $getShared = true): InspectionService
    {
        return $getShared ? static::getSharedInstance('inspections') : new InspectionService();
    }

    public static function logistics(bool $getShared = true): LogisticsService
    {
        return $getShared ? static::getSharedInstance('logistics') : new LogisticsService();
    }

    public static function disputes(bool $getShared = true): DisputeService
    {
        return $getShared ? static::getSharedInstance('disputes') : new DisputeService();
    }

    public static function performance(bool $getShared = true): PerformanceService
    {
        return $getShared ? static::getSharedInstance('performance') : new PerformanceService();
    }

    public static function ranking(bool $getShared = true): RankingService
    {
        return $getShared ? static::getSharedInstance('ranking') : new RankingService();
    }

    public static function imports(bool $getShared = true): ImportService
    {
        return $getShared ? static::getSharedInstance('imports') : new ImportService();
    }

    public static function supplierMaster(bool $getShared = true): SupplierMasterService
    {
        return $getShared ? static::getSharedInstance('supplierMaster') : new SupplierMasterService();
    }

    public static function companies(bool $getShared = true): CompanyService
    {
        return $getShared ? static::getSharedInstance('companies') : new CompanyService();
    }

    public static function reports(bool $getShared = true): ReportService
    {
        return $getShared ? static::getSharedInstance('reports') : new ReportService();
    }
}

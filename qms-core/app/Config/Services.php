<?php

declare(strict_types=1);

namespace App\Config;

use App\Libraries\GoogleSheets\GoogleSheetsClient;
use App\Libraries\GoogleSheets\SheetsClientInterface;
use App\Libraries\Navigation;
use App\Services\Access\AuthorizationService;
use App\Services\Access\RoleService;
use App\Services\Access\UserService;
use App\Services\Audit\AuditService;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordPolicy;
use App\Services\Files\UploadService;
use App\Services\Gauges\GaugeService;
use App\Services\Inspections\InspectionListService;
use App\Services\Inspections\InspectionService;
use App\Services\Inspections\ProductionCalendar;
use App\Services\Inspections\SpecEvaluator;
use App\Services\Masters\MasterDataService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Print\PdfService;
use App\Services\Print\PrintViewModelBuilder;
use App\Services\Reports\DashboardService;
use App\Services\Reports\ReportQueryService;
use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use App\Services\Support\IdempotencyService;
use App\Services\Support\RequestContext;
use App\Services\Support\TransactionRunner;
use App\Services\Sync\SheetRowMapper;
use App\Services\Sync\SheetSyncQueue;
use App\Services\Sync\SheetSyncWorker;
use App\Services\Templates\TemplateResolver;
use App\Services\Templates\TemplateService;
use App\Services\Workflow\WorkflowService;
use App\Core\BaseServices;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Throttler;
use App\Core\View;
use Throwable;

/**
 * Service registry. Shared instances per request; tests replace any of them
 * with Services::injectMock('<name>', $fake). service('name') is the shortcut.
 */
class Services extends BaseServices
{
    // ------------------------------------------------------------------
    // Infrastructure
    // ------------------------------------------------------------------

    /** Shared MySQL connection (.env DB_*). */
    public static function db(): Database
    {
        return static::$connection ??= Database::fromEnv();
    }

    public static function request(bool $getShared = true): Request
    {
        return $getShared ? static::getSharedInstance('request') : Request::fromGlobals();
    }

    public static function response(bool $getShared = true): Response
    {
        return $getShared ? static::getSharedInstance('response') : new Response();
    }

    public static function session(bool $getShared = true): Session
    {
        return $getShared ? static::getSharedInstance('session') : new Session(is_cli() ? null : static::db());
    }

    public static function renderer(bool $getShared = true): View
    {
        return $getShared ? static::getSharedInstance('renderer') : new View(APPPATH . 'Views');
    }

    public static function throttler(bool $getShared = true): Throttler
    {
        return $getShared ? static::getSharedInstance('throttler') : new Throttler(WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'throttle');
    }

    public static function logger(bool $getShared = true): Logger
    {
        return $getShared ? static::getSharedInstance('logger')
            : new Logger(WRITEPATH . 'logs', ENVIRONMENT === 'production' ? 4 : 8);
    }

    public static function routes(bool $getShared = true): Router
    {
        if ($getShared) {
            return static::getSharedInstance('routes');
        }
        $routes = new Router();
        (static function (Router $routes): void {
            require APPPATH . 'routes.php';
        })($routes);

        return $routes;
    }

    /**
     * Configuration objects: config('App'), config('Qms'), config(Qms::class) …
     */
    public static function config(string $name): object
    {
        $short = str_contains($name, '\\') ? substr($name, strrpos($name, '\\') + 1) : $name;
        if (! in_array($short, ['App', 'Qms', 'Google', 'Security', 'Session'], true)) {
            throw new \InvalidArgumentException("Unknown configuration {$name}");
        }

        return static::$instances['config.' . $short] ??= new ('App\\Config\\' . $short)();
    }

    // ------------------------------------------------------------------
    // Application services
    // ------------------------------------------------------------------

    public static function clock(bool $getShared = true): Clock
    {
        if ($getShared) {
            return static::getSharedInstance('clock');
        }

        try {
            $zone = static::settings()->string('regional.timezone', 'Asia/Kolkata');
        } catch (Throwable) {
            $zone = 'UTC'; // settings table not migrated yet
        }

        return new Clock($zone);
    }

    public static function requestContext(bool $getShared = true): RequestContext
    {
        return $getShared ? static::getSharedInstance('requestContext') : new RequestContext();
    }

    public static function transactions(bool $getShared = true): TransactionRunner
    {
        return $getShared ? static::getSharedInstance('transactions') : new TransactionRunner(static::db());
    }

    public static function settings(bool $getShared = true): SettingsService
    {
        return $getShared ? static::getSharedInstance('settings') : new SettingsService(static::db());
    }

    public static function audit(bool $getShared = true): AuditService
    {
        return $getShared ? static::getSharedInstance('audit')
            : new AuditService(static::db(), static::requestContext(), static::clock());
    }

    public static function passwordPolicy(bool $getShared = true): PasswordPolicy
    {
        return $getShared ? static::getSharedInstance('passwordPolicy') : new PasswordPolicy(static::settings());
    }

    public static function auth(bool $getShared = true): AuthService
    {
        if ($getShared) {
            return static::getSharedInstance('auth');
        }

        return new AuthService(
            static::db(),
            static::session(),
            static::settings(),
            static::passwordPolicy(),
            static::audit(),
            static::requestContext(),
            static::clock(),
            static::transactions(),
        );
    }

    public static function authorization(bool $getShared = true): AuthorizationService
    {
        return $getShared ? static::getSharedInstance('authorization')
            : new AuthorizationService(static::db(), static::auth(), static::audit());
    }

    public static function idempotency(bool $getShared = true): IdempotencyService
    {
        return $getShared ? static::getSharedInstance('idempotency')
            : new IdempotencyService(static::db(), static::transactions(), static::clock());
    }

    public static function navigation(bool $getShared = true): Navigation
    {
        return $getShared ? static::getSharedInstance('navigation')
            : new Navigation(static::db(), static::auth(), static::authorization());
    }

    public static function userAdmin(bool $getShared = true): UserService
    {
        if ($getShared) {
            return static::getSharedInstance('userAdmin');
        }

        return new UserService(static::db(), static::auth(), static::passwordPolicy(), static::audit(), static::clock(), static::transactions());
    }

    public static function roleAdmin(bool $getShared = true): RoleService
    {
        return $getShared ? static::getSharedInstance('roleAdmin')
            : new RoleService(static::db(), static::audit(), static::clock(), static::transactions());
    }

    public static function masterData(bool $getShared = true): MasterDataService
    {
        return $getShared ? static::getSharedInstance('masterData')
            : new MasterDataService(static::db(), static::audit(), static::clock(), static::transactions());
    }

    public static function uploads(bool $getShared = true): UploadService
    {
        return $getShared ? static::getSharedInstance('uploads') : new UploadService(WRITEPATH . 'uploads');
    }

    public static function gauges(bool $getShared = true): GaugeService
    {
        return $getShared ? static::getSharedInstance('gauges')
            : new GaugeService(static::db(), static::audit(), static::clock(), static::transactions(), static::uploads());
    }

    public static function templates(bool $getShared = true): TemplateService
    {
        return $getShared ? static::getSharedInstance('templates')
            : new TemplateService(static::db(), static::audit(), static::clock(), static::transactions());
    }

    public static function templateResolver(bool $getShared = true): TemplateResolver
    {
        return $getShared ? static::getSharedInstance('templateResolver') : new TemplateResolver(static::db());
    }

    public static function productionCalendar(bool $getShared = true): ProductionCalendar
    {
        return $getShared ? static::getSharedInstance('productionCalendar')
            : new ProductionCalendar(static::db(), static::clock(), static::settings());
    }

    public static function specEvaluator(bool $getShared = true): SpecEvaluator
    {
        return $getShared ? static::getSharedInstance('specEvaluator') : new SpecEvaluator();
    }

    public static function documentNumbers(bool $getShared = true): DocumentNumberService
    {
        return $getShared ? static::getSharedInstance('documentNumbers')
            : new DocumentNumberService(static::db(), static::settings());
    }

    public static function inspections(bool $getShared = true): InspectionService
    {
        if ($getShared) {
            return static::getSharedInstance('inspections');
        }

        return new InspectionService(
            static::db(),
            static::auth(),
            static::authorization(),
            static::audit(),
            static::clock(),
            static::transactions(),
            static::settings(),
            static::templateResolver(),
            static::specEvaluator(),
            static::productionCalendar(),
            static::gauges(),
        );
    }

    public static function workflow(bool $getShared = true): WorkflowService
    {
        if ($getShared) {
            return static::getSharedInstance('workflow');
        }

        return new WorkflowService(
            static::db(),
            static::auth(),
            static::authorization(),
            static::audit(),
            static::clock(),
            static::transactions(),
            static::settings(),
            static::inspections(),
            static::documentNumbers(),
            static::sheetQueue(),
            static::idempotency(),
            static::requestContext(),
        );
    }

    public static function inspectionList(bool $getShared = true): InspectionListService
    {
        return $getShared ? static::getSharedInstance('inspectionList')
            : new InspectionListService(static::db(), static::authorization(), static::productionCalendar());
    }

    public static function sheetQueue(bool $getShared = true): SheetSyncQueue
    {
        return $getShared ? static::getSharedInstance('sheetQueue')
            : new SheetSyncQueue(static::db(), static::settings(), static::clock());
    }

    public static function sheetsClient(bool $getShared = true): SheetsClientInterface
    {
        return $getShared ? static::getSharedInstance('sheetsClient') : new GoogleSheetsClient(static::config('Google'));
    }

    public static function sheetRowMapper(bool $getShared = true): SheetRowMapper
    {
        return $getShared ? static::getSharedInstance('sheetRowMapper')
            : new SheetRowMapper(static::db(), static::settings(), static::clock(), static::inspections(), static::workflow());
    }

    public static function sheetWorker(bool $getShared = true): SheetSyncWorker
    {
        if ($getShared) {
            return static::getSharedInstance('sheetWorker');
        }

        return new SheetSyncWorker(static::db(), static::settings(), static::clock(), static::sheetsClient(), static::sheetRowMapper(), static::config('Qms'));
    }

    public static function dashboard(bool $getShared = true): DashboardService
    {
        return $getShared ? static::getSharedInstance('dashboard')
            : new DashboardService(static::db(), static::clock(), static::settings());
    }

    public static function reportQueries(bool $getShared = true): ReportQueryService
    {
        return $getShared ? static::getSharedInstance('reportQueries')
            : new ReportQueryService(static::db(), static::clock(), static::settings());
    }

    public static function printBuilder(bool $getShared = true): PrintViewModelBuilder
    {
        return $getShared ? static::getSharedInstance('printBuilder')
            : new PrintViewModelBuilder(static::db(), static::settings(), static::clock(), static::inspections(), static::workflow(), static::uploads());
    }

    public static function pdf(bool $getShared = true): PdfService
    {
        return $getShared ? static::getSharedInstance('pdf') : new PdfService();
    }
}

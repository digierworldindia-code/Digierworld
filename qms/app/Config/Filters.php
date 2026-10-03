<?php

namespace Config;

use App\Filters\AuthFilter;
use App\Filters\GuestFilter;
use App\Filters\LoginThrottleFilter;
use App\Filters\NoStoreFilter;
use App\Filters\OriginCheckFilter;
use App\Filters\PermissionFilter;
use App\Filters\SecurityHeadersFilter;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    /**
     * @var array<string, class-string|list<class-string>>
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        // QMS
        'auth'          => AuthFilter::class,
        'guest'         => GuestFilter::class,
        'permission'    => PermissionFilter::class,
        'loginthrottle' => LoginThrottleFilter::class,
        'origincheck'   => OriginCheckFilter::class,
        'qmsheaders'    => SecurityHeadersFilter::class,
        'nostore'       => NoStoreFilter::class,
    ];

    /**
     * Always applied. forcehttps redirects to HTTPS when
     * app.forceGlobalSecureRequests = true (production).
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            'forcehttps',
            'pagecache',
        ],
        'after' => [
            'pagecache',
            'performance',
            'toolbar',
        ],
    ];

    /**
     * Applied to every request. CSRF protects every POST/PUT/PATCH/DELETE.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            'invalidchars',
            'origincheck',
            'csrf',
        ],
        'after' => [
            'qmsheaders',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * Authentication and permissions are attached per route group in Routes.php
     * (every route must declare a permission; a test enforces it).
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [];
}

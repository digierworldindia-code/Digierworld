<?php

namespace Config;

use App\Filters\AiTokenFilter;
use App\Filters\PermissionFilter;
use App\Filters\SecurityHeadersFilter;
use App\Filters\SessionGuardFilter;
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
     * Shield registers: session, tokens, chain, auth-rates, group, permission, force-reset.
     * App aliases: perm (DB-backed RBAC), guard, dgrheaders, aitoken.
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
        'perm'          => PermissionFilter::class,
        'guard'         => SessionGuardFilter::class,
        'dgrheaders'    => SecurityHeadersFilter::class,
        'aitoken'       => AiTokenFilter::class,
    ];

    public array $required = [
        'before' => [
            'forcehttps',
            'pagecache',
        ],
        'after' => [
            'pagecache',
            'performance',
            // 'toolbar' is incompatible with the strict CSP (inline scripts); enable temporarily if needed
        ],
    ];

    /** Routes reachable without an interactive session. */
    private const PUBLIC = ['login', 'login/*', 'logout', 'auth/a/*', 'api/ai/*', 'health', 'share/*'];

    public array $globals = [
        'before' => [
            'invalidchars',
            'csrf'    => ['except' => ['api/ai/*']],
            'session' => ['except' => self::PUBLIC],
            'guard'   => ['except' => self::PUBLIC],
        ],
        'after' => [
            'dgrheaders',
        ],
    ];

    public array $methods = [];

    public array $filters = [
        'auth-rates' => ['before' => ['login', 'login/*', 'auth/a/*']],
    ];
}

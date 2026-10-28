<?php

namespace Config;

use App\Filters\AiTokenFilter;
use App\Filters\PermissionFilter;
use App\Filters\SecurityHeadersFilter;
use App\Filters\SessionGuardFilter;
use App\Filters\SignedOutRequestFilter;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
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
     * App aliases: perm (DB-backed RBAC), guard, signedout, dgrheaders, aitoken.
     */
    public array $aliases = [
        'csrf'          => \App\Filters\CsrfFilter::class, // CodeIgniter's check + a clear message when the session cookie is missing
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
        'signedout'     => SignedOutRequestFilter::class,
        'dgrheaders'    => SecurityHeadersFilter::class,
        'aitoken'       => AiTokenFilter::class,
        'unlock'        => \App\Filters\SessionUnlockFilter::class, // downloads release the session lock (no 504 chains)
        'slowlog'       => \App\Filters\SlowRequestFilter::class,   // logs requests slower than app.slowRequestMs
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

    /** Routes reachable without an interactive session (brand/logo: the official logo shown on the sign-in page; setup: first administrator, see SetupController). */
    private const PUBLIC = ['login', 'login/*', 'logout', 'auth/a/*', 'api/ai/*', 'health', 'share/*', 'brand/logo', 'setup', 'setup/*'];

    public array $globals = [
        'before' => [
            'invalidchars',
            'csrf'    => ['except' => ['api/ai/*']],
            'signedout' => ['except' => self::PUBLIC],
            'session' => ['except' => self::PUBLIC],
            'guard'   => ['except' => self::PUBLIC],
        ],
        'after' => [
            'dgrheaders',
            'slowlog',
        ],
    ];

    public array $methods = [];

    public array $filters = [
        'auth-rates' => ['before' => ['login', 'login/*', 'auth/a/*', 'setup/*']],
        // read-only downloads: PDFs, exports, stored files, backups, payroll register, payslip preview
        'unlock' => ['before' => ['*/pdf', '*/export', '*/*/export', 'files/*', 'admin/backups/*', 'hr/payroll/*/register', 'hr/payroll/items/*/preview', 'me/payslips/*']],
    ];
}

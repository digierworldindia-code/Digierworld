<?php

namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;
use CodeIgniter\HotReloader\HotReloader;

/*
 * --------------------------------------------------------------------
 * Application Events
 * --------------------------------------------------------------------
 * Events allow you to tap into the execution of the program without
 * modifying or extending core files. This file provides a central
 * location to define your events, though they can always be added
 * at run-time, also, if needed.
 *
 * You create code that can execute by subscribing to events with
 * the 'on()' method. This accepts any form of callable, including
 * Closures, that will be executed when the event is triggered.
 *
 * Example:
 *      Events::on('create', [$myInstance, 'myMethod']);
 */

/*
 * POLYFIX: refuse to serve a production request from an unfinished install.
 *
 * A half-configured deployment that runs is more dangerous than one that
 * stops. But stopping with a bare 500 was its own bug: the generic error page
 * is identical whether the secrets are unset, the database is unreachable or
 * the disk is full, so whoever is deploying has nothing to go on. Worse, that
 * page needs the brand helper, the config and base_url() to render, which is
 * precisely what is unavailable when the install is broken.
 *
 * So: name the problems on a plain, self-contained page and answer 503, which
 * is what "configured but not ready" actually means. Only install-integrity
 * problems qualify — see Polyfix::installProblems(). They cannot be true of an
 * installation that has ever served a request, so there is no live site whose
 * details this could leak, and it prints names, never values.
 */
Events::on('pre_system', static function (): void {
    if (ENVIRONMENT !== 'production' || is_cli()) {
        return;
    }

    $problems = config(\Config\Polyfix::class)->installProblems();
    if ($problems === []) {
        return;
    }

    log_message('critical', 'Refusing to serve: setup is not finished: ' . implode('; ', $problems));

    if (! headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable', true, 503);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, private');
        header('Retry-After: 300');
        header('X-Robots-Tag: noindex, nofollow');
    }

    // Included directly rather than through view(): the renderer is one more
    // thing that can fail here, and this template needs nothing from it.
    require APPPATH . 'Views/errors/html/setup.php';

    exit(1);
});

Events::on('pre_system', static function (): void {
    if (ENVIRONMENT !== 'testing') {
        $value = ini_get('zlib.output_compression');

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN) || (int) $value > 0) {
            throw FrameworkException::forEnabledZlibOutputCompression();
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start(static fn ($buffer) => $buffer);
    }

    /*
     * --------------------------------------------------------------------
     * Debug Toolbar Listeners.
     * --------------------------------------------------------------------
     * If you delete, they will no longer be collected.
     */
    if (CI_DEBUG && ! is_cli()) {
        Events::on('DBQuery', 'CodeIgniter\Debug\Toolbar\Collectors\Database::collect');
        service('toolbar')->respond();
        // Hot Reload route - for framework use on the hot reloader.
        if (ENVIRONMENT === 'development') {
            service('routes')->get('__hot-reload', static function (): void {
                (new HotReloader())->run();
            });
        }
    }
});

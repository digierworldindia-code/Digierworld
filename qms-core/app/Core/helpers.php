<?php

declare(strict_types=1);

/**
 * Global helper functions used by controllers, services and views.
 */

use App\Config\Services;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\RedirectResponse;
use App\Core\Session;

if (! function_exists('service')) {
    /** Shared service instance, e.g. service('auth'). */
    function service(string $name, mixed ...$params): mixed
    {
        return Services::$name(...$params);
    }
}

if (! function_exists('config')) {
    function config(string $name): object
    {
        return Services::config($name);
    }
}

if (! function_exists('db_connect')) {
    function db_connect(): Database
    {
        return Services::db();
    }
}

if (! function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        return Env::get($key, $default);
    }
}

if (! function_exists('is_cli')) {
    function is_cli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}

if (! function_exists('session')) {
    /** The session, or one value from it: session('key'). */
    function session(?string $key = null): mixed
    {
        $session = Services::session();

        return $key === null ? $session : $session->get($key);
    }
}

if (! function_exists('view')) {
    /**
     * Renders app/Views/<name>.php.
     *
     * @param array<string, mixed> $data
     * @param array{saveData?: bool} $options
     */
    function view(string $name, array $data = [], array $options = []): string
    {
        return Services::renderer()->setData($data)->render($name, (bool) ($options['saveData'] ?? true));
    }
}

if (! function_exists('log_message')) {
    /**
     * @param array<string, mixed> $context
     */
    function log_message(string $level, string $message, array $context = []): void
    {
        Services::logger()->log($level, $message, $context);
    }
}

if (! function_exists('site_url')) {
    /** Absolute URL of a page of this application. */
    function site_url(array|string $path = ''): string
    {
        $path = is_array($path) ? implode('/', $path) : $path;

        return config('App')->baseURL . ltrim($path, '/');
    }
}

if (! function_exists('base_url')) {
    /** Absolute URL of a file under public/. */
    function base_url(array|string $path = ''): string
    {
        return site_url($path);
    }
}

if (! function_exists('current_url')) {
    /** URL of the current page without the query string. */
    function current_url(): string
    {
        return site_url(Services::request()->getPath());
    }
}

if (! function_exists('previous_url')) {
    /** The last page shown to this user (for "back" after a failed form post). */
    function previous_url(): string
    {
        $base     = config('App')->baseURL;
        $previous = Services::session()->get('_qms_previous_url');
        if (is_string($previous) && str_starts_with($previous, $base)) {
            return $previous;
        }
        $referer = Services::request()->getHeaderLine('Referer');
        if ($referer !== '' && str_starts_with($referer, $base)) {
            return $referer;
        }

        return site_url('/');
    }
}

if (! function_exists('redirect')) {
    function redirect(?string $url = null): RedirectResponse
    {
        $response = new RedirectResponse();

        return $url === null ? $response : $response->to(str_contains($url, '://') ? $url : site_url($url));
    }
}

if (! function_exists('csrf_token')) {
    /** Name of the CSRF form field. */
    function csrf_token(): string
    {
        return Csrf::tokenName();
    }
}

if (! function_exists('csrf_header')) {
    function csrf_header(): string
    {
        return Csrf::headerName();
    }
}

if (! function_exists('csrf_hash')) {
    /** Masked CSRF token value for this page. */
    function csrf_hash(): string
    {
        return Csrf::hash();
    }
}

if (! function_exists('csrf_field')) {
    /** Hidden input carrying the CSRF token; put it in every POST form. */
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (! function_exists('old')) {
    /**
     * Submitted value from the previous request (after a failed form post),
     * unescaped: views escape it with esc() where it is printed.
     */
    function old(string $key, mixed $default = null): mixed
    {
        return Services::request()->getOldInput($key) ?? $default;
    }
}

if (! function_exists('esc')) {
    /**
     * Escapes data for HTML output. Contexts: html (default), attr, js, css, url, raw.
     * Arrays are escaped recursively; non-string scalars are returned unchanged.
     */
    function esc(mixed $data, string $context = 'html', ?string $encoding = null): mixed
    {
        $context = strtolower($context);
        if ($context === 'raw') {
            return $data;
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = esc($value, $context);
            }

            return $data;
        }
        if (! is_string($data)) {
            return $data;
        }
        if (! mb_check_encoding($data, 'UTF-8')) {
            $data = mb_scrub($data, 'UTF-8');
        }

        return match ($context) {
            'html' => htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'attr' => qms_escape_attr($data),
            'js'   => qms_escape_js($data),
            'css'  => qms_escape_css($data),
            'url'  => rawurlencode($data),
            default => throw new InvalidArgumentException('Invalid escape context provided.'),
        };
    }
}

if (! function_exists('qms_escape_attr')) {
    /** HTML attribute escaping (safe even in unquoted attributes). */
    function qms_escape_attr(string $value): string
    {
        if ($value === '' || ctype_digit($value)) {
            return $value;
        }

        return (string) preg_replace_callback('/[^a-z0-9,\.\-_]/iSu', static function (array $m): string {
            $chr = $m[0];
            $ord = mb_ord($chr, 'UTF-8');
            if (($ord <= 0x1f && ! in_array($chr, ["\t", "\n", "\r"], true)) || ($ord >= 0x7f && $ord <= 0x9f)) {
                return '&#xFFFD;';
            }

            return match ($chr) {
                '"'     => '&quot;',
                '&'     => '&amp;',
                '<'     => '&lt;',
                '>'     => '&gt;',
                default => $ord > 255 ? sprintf('&#x%04X;', $ord) : sprintf('&#x%02X;', $ord),
            };
        }, $value);
    }
}

if (! function_exists('qms_escape_js')) {
    /** JavaScript string-literal escaping. */
    function qms_escape_js(string $value): string
    {
        if ($value === '' || ctype_digit($value)) {
            return $value;
        }

        return (string) preg_replace_callback('/[^a-z0-9,\._]/iSu', static function (array $m): string {
            $ord = mb_ord($m[0], 'UTF-8');
            if ($ord < 256) {
                return sprintf('\\x%02X', $ord);
            }
            if ($ord > 0xFFFF) {
                $ord -= 0x10000;

                return sprintf('\\u%04X\\u%04X', 0xD800 | ($ord >> 10), 0xDC00 | ($ord & 0x3FF));
            }

            return sprintf('\\u%04X', $ord);
        }, $value);
    }
}

if (! function_exists('qms_escape_css')) {
    function qms_escape_css(string $value): string
    {
        if ($value === '' || ctype_digit($value)) {
            return $value;
        }

        return (string) preg_replace_callback('/[^a-z0-9]/iSu', static fn (array $m): string => sprintf('\\%X ', mb_ord($m[0], 'UTF-8')), $value);
    }
}
